<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\AiConversation;
use App\Models\AttendanceRecord;
use App\Models\BiometricDevice;
use App\Models\BiometricEvent;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Shared\Enums\AttendanceStatus;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI-ассистент (п.68 ТЗ) и live-фид посещаемости (п.30).
 */
class AiAndLiveFeedHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected User $workerUser;

    protected Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'AI Co', 'code' => 'aico']);

        $this->adminUser = User::factory()->create();
        $this->adminEmployee = $this->employee('A-1', 'Админ', 'Айтишников', $this->adminUser->id);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->workerUser = User::factory()->create();
        $this->worker = $this->employee('W-1', 'Ольга', 'Рядовая', $this->workerUser->id);
        EmployeeRole::create([
            'employee_id' => $this->worker->id,
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $this->actingAs($this->adminUser);
    }

    protected function employee(string $no, string $first, string $last, ?int $userId = null): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $userId ?? User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
        ]);
    }

    /* -------------------- AI: локальный аналитик -------------------- */

    public function test_ai_answers_attendance_question_from_facts(): void
    {
        AttendanceRecord::create([
            'employee_id' => $this->worker->id,
            'date' => today(),
            'clock_in_at' => now()->subHours(2),
            'late_minutes' => 15,
            'status' => AttendanceStatus::Late,
        ]);

        $this->post('/ai', ['question' => 'Есть ли опоздания и сколько людей на месте сегодня?'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $conversation = AiConversation::query()->sole();
        self::assertStringContainsStringIgnoringCase('опоздали', (string) $conversation->answer);
        // честный флаг: LLM не настроен → локальный фолбэк → grounded=false (S10)
        self::assertFalse((bool) $conversation->grounded);
        // факты записаны вместе с ответом
        self::assertSame('company', $conversation->query_used['scope']);
        self::assertSame(1, $conversation->query_used['attendance_today']['late']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'ai.asked']);

        // история видна на странице
        $this->get('/ai')
            ->assertOk()
            ->assertSee('Есть ли опоздания')
            ->assertSee('опоздали 1')
            ->assertSee('grounded');
    }

    public function test_ai_answers_tasks_question(): void
    {
        Task::create([
            'company_id' => $this->company->id,
            'title' => 'Просроченная задача',
            'number' => 'T-AI-1',
            'author_id' => $this->adminEmployee->id,
            'status' => 'overdue',
        ])->assignments()->create([
            'assignee_type' => 'employee',
            'employee_id' => $this->worker->id,
            'assignment_status' => 'accepted',
        ]);

        $this->post('/ai', ['question' => 'Сколько просроченных задач?'])
            ->assertRedirect();

        $answer = (string) AiConversation::query()->sole()->answer;
        self::assertStringContainsStringIgnoringCase('просрочено', $answer);
        self::assertStringContainsString('1', $answer);
    }

    public function test_ai_validation_rejects_short_question(): void
    {
        $this->post('/ai', ['question' => 'ok'])
            ->assertRedirect()
            ->assertSessionHasErrors(['question']);

        self::assertSame(0, AiConversation::query()->count());
    }

    /* -------------------- AI: настроенный LLM -------------------- */

    public function test_ai_uses_configured_llm_and_stays_grounded(): void
    {
        config([
            'corpms.ai.base_url' => 'https://llm.example.com/v1',
            'corpms.ai.api_key' => 'secret-key',
        ]);

        Http::fake([
            'llm.example.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'По фактам: сегодня 1 опоздание, 0 на месте из 2 сотрудников.']],
                ],
            ]),
        ]);

        $this->post('/ai', ['question' => 'Какая посещаемость?'])->assertRedirect();

        $conversation = AiConversation::query()->sole();
        self::assertStringContainsString('По фактам', (string) $conversation->answer);
        self::assertTrue((bool) $conversation->grounded);

        // LLM получил факты в системном сообщении
        Http::assertSent(function ($request): bool {
            $body = $request->data();
            $messages = $body['messages'];

            return str_contains((string) $messages[1]['content'], 'Факты')
                && str_contains((string) $messages[1]['content'], '"scope":"company"')
                && str_contains((string) $messages[2]['content'], 'посещаемост');
        });
    }

    public function test_ai_denied_without_permission(): void
    {
        $this->actingAs($this->workerUser);

        $this->get('/ai')->assertForbidden();
        $this->post('/ai', ['question' => 'сколько людей на месте?'])->assertForbidden();

        self::assertSame(0, AiConversation::query()->count());
    }

    /* -------------------- S10: история диалога + честный grounded -------------------- */

    public function test_second_llm_question_includes_dialogue_history_in_context(): void
    {
        config([
            'corpms.ai.base_url' => 'https://llm.example.com/v1',
            'corpms.ai.api_key' => 'secret-key',
        ]);

        Http::fake([
            'llm.example.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Первый ответ ассистента.']],
                ],
            ]),
        ]);

        // первый вопрос — история ещё пуста
        $this->post('/ai', ['question' => 'Сколько сотрудников в компании?'])->assertRedirect();

        Http::fake([
            'llm.example.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Второй ответ с учётом контекста.']],
                ],
            ]),
        ]);

        // второй вопрос: в контексте должны быть первая реплика и первый ответ
        $this->post('/ai', ['question' => 'А из них сколько опоздали?'])->assertRedirect();

        Http::assertSent(function ($request): bool {
            $messages = $request->data()['messages'];
            $roles = array_column($messages, 'role');
            $contents = array_column($messages, 'content');

            $hasFirstQuestion = in_array('Сколько сотрудников в компании?', $contents, true);
            $hasFirstAnswer = in_array('Первый ответ ассистента.', $contents, true);
            $lastIsSecondQuestion = $messages[array_key_last($messages)]['content'] === 'А из них сколько опоздали?';

            // первая реплика идёт раньше второй (user → assistant → user)
            $firstIdx = array_search('Сколько сотрудников в компании?', $contents, true);
            $secondIdx = array_search('А из них сколько опоздали?', $contents, true);
            $orderOk = $firstIdx !== false && $secondIdx !== false && $firstIdx < $secondIdx;
            $rolesOk = $roles[$firstIdx] === 'user' && $roles[$secondIdx] === 'user';

            return $hasFirstQuestion && $hasFirstAnswer && $lastIsSecondQuestion && $orderOk && $rolesOk;
        });
    }

    public function test_grounded_is_false_when_llm_fails_over_to_local_fallback(): void
    {
        config([
            'corpms.ai.base_url' => 'https://llm.example.com/v1',
            'corpms.ai.api_key' => 'secret-key',
        ]);

        // LLM настроен, но отвечает ошибкой → фолбэк на локальный анализатор
        Http::fake([
            'llm.example.com/v1/chat/completions' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->post('/ai', ['question' => 'Есть ли опоздания сегодня?'])->assertRedirect();

        $conversation = AiConversation::query()->sole();
        self::assertNotEmpty((string) $conversation->answer, 'Фолбэк должен дать ответ');
        self::assertFalse((bool) $conversation->grounded, 'При фолбэке с LLM grounded должен быть false');
    }

    public function test_panel_message_grounded_flag_reflects_llm_or_fallback(): void
    {
        // LLM не настроен → панель отвечает локально → grounded=false
        $this->postJson('/ai/message', ['question' => 'Сколько просроченных задач?'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $conversation = AiConversation::query()->orderByDesc('id')->first();
        self::assertNotNull($conversation);
        self::assertFalse((bool) $conversation->grounded, 'Панель без LLM — локальный ответ, grounded=false');

        // LLM настроен и ответил → grounded=true
        config([
            'corpms.ai.base_url' => 'https://llm.example.com/v1',
            'corpms.ai.api_key' => 'secret-key',
        ]);
        Http::fake([
            'llm.example.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Ответ панели от LLM.']],
                ],
            ]),
        ]);

        $this->postJson('/ai/message', ['question' => 'Какая численность?'])->assertOk();

        $conversation = AiConversation::query()->orderByDesc('id')->first();
        self::assertTrue((bool) $conversation->grounded, 'Ответ LLM — grounded=true');
        self::assertSame('Ответ панели от LLM.', (string) $conversation->answer);
    }

    /* -------------------- Live-фид -------------------- */

    public function test_live_feed_returns_events_and_counters(): void
    {
        AttendanceRecord::create([
            'employee_id' => $this->worker->id,
            'date' => today(),
            'clock_in_at' => now()->subHour(),
            'status' => AttendanceStatus::OnTime,
        ]);

        $device = $this->device();
        BiometricEvent::create([
            'device_id' => $device->id,
            'employee_id' => $this->worker->id,
            'event_uuid' => 'feed-evt-1',
            'occurred_at' => now(),
            'direction' => 'in',
            'liveness_passed' => true,
            'liveness_required' => false,
            'raw_payload' => [],
            'processing_status' => 'processed',
        ]);

        $response = $this->getJson('/attendance/live/feed');

        $response->assertOk()
            ->assertJsonPath('events.0.employee', 'Рядовая')
            ->assertJsonPath('events.0.direction', 'in')
            ->assertJsonPath('records.0.employee', 'Рядовая Ольга')
            ->assertJsonPath('counters.on_time', 1);
    }

    public function test_live_feed_denied_without_permission(): void
    {
        $this->actingAs($this->workerUser);
        $this->getJson('/attendance/live/feed')->assertForbidden();
    }

    protected function device(): BiometricDevice
    {
        return BiometricDevice::create([
            'company_id' => $this->company->id,
            'name' => 'Терминал feed',
            'serial_number' => 'SN-FEED-1',
            'device_type' => 'face_terminal',
            'vendor' => 'generic',
            'direction' => 'both',
            'status' => 'online',
            'api_token_hash' => hash('sha256', 'x'),
        ]);
    }
}
