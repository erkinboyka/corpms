<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Ai\Services\AiMemoryService;
use App\Domains\Ai\Services\AiPanelService;
use App\Models\AiConversation;
use App\Models\AiMemory;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Shared\Enums\AttendanceStatus;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * D6: AI Memory (долговременная память), AI Insights, сводный отчёт
 * и генератор документов (блок type=text).
 *
 * Проверяем: remember/recall/forget с изоляцией между сотрудниками,
 * память в системном промпте LLM, детерминированные инсайты в scope own,
 * сводный отчёт (chart+table+actions), черновик документа без записи в БД
 * и санитизацию text-блока от LLM.
 */
class AiMemoryInsightsTest extends TestCase
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

        $this->company = Company::create(['name' => 'Memory Co', 'code' => 'memoryco']);

        $this->adminUser = User::factory()->create();
        $this->adminEmployee = $this->employee('M-1', 'Админ', 'Памятный', $this->adminUser->id);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->workerUser = User::factory()->create();
        $this->worker = $this->employee('M-2', 'Ольга', 'Рабочая', $this->workerUser->id);
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

    /** Включить LLM (в phpunit.xml она выключена). */
    protected function enableAi(): void
    {
        config([
            'corpms.ai.base_url' => 'https://llm.example.com/v1',
            'corpms.ai.api_key' => 'secret-key',
        ]);
    }

    /* -------------------- AI Memory -------------------- */

    public function test_memory_remember_recall_and_forget_via_panel(): void
    {
        $this->postJson('/ai/message', ['question' => 'Запомни: мой проект — Альфа'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('ai_memories', [
            'employee_id' => $this->adminEmployee->id,
            'memory_key' => 'мой проект',
            'memory_value' => 'Альфа',
        ]);

        $recall = $this->postJson('/ai/message', ['question' => 'Что помнишь?'])->assertOk();
        self::assertSame('table', $recall->json('blocks.0.type'));
        self::assertContains(['мой проект', 'Альфа'], $recall->json('blocks.0.rows'));

        $this->postJson('/ai/message', ['question' => 'Забудь мой проект'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        self::assertSame(0, AiMemory::query()->count());
    }

    public function test_memory_is_isolated_between_employees(): void
    {
        $memory = app(AiMemoryService::class);

        $memory->remember($this->adminEmployee, 'ключ admin', 'ценное значение');

        self::assertSame(0, $memory->recall($this->worker)->count(), 'чужая память не видна');
        self::assertSame('', $memory->contextBlock($this->worker));
        self::assertFalse($memory->forget($this->worker, 'ключ admin'), 'нельзя забыть чужую запись');

        // админ в панели видит только свои записи
        $adminRecall = $this->postJson('/ai/message', ['question' => 'Покажи память'])->assertOk();
        $adminRows = $adminRecall->json('blocks.0.rows') ?? [];
        self::assertContains(['ключ admin', 'ценное значение'], $adminRows);

        // у работника своя память — админ её не видит
        $memory->remember($this->worker, 'ключ worker', 'worker-секрет');
        $again = $this->postJson('/ai/message', ['question' => 'Что помнишь?'])->assertOk();
        self::assertNotContains(['ключ worker', 'worker-секрет'], $again->json('blocks.0.rows') ?? []);
    }

    public function test_memory_commands_do_not_dispatch_llm_retry(): void
    {
        $this->enableAi();
        Http::fake();
        Queue::fake();

        $this->postJson('/ai/message', ['question' => 'Запомни: тест — значение'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        // детерминированная команда: LLM не вызывается, фоновый повтор не ставится
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        self::assertFalse((bool) AiConversation::query()->sole()->grounded);
    }

    public function test_memory_context_is_sent_to_llm_system_prompt(): void
    {
        $this->enableAi();
        Http::fake([
            'llm.example.com/v1/embeddings' => Http::response(['data' => []]),
            'llm.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Ответ на основе фактов.']]],
            ]),
        ]);

        app(AiMemoryService::class)->remember($this->adminEmployee, 'мой проект', 'Альфа');

        $this->postJson('/ai/message', ['question' => 'Сколько сотрудников в компании?'])
            ->assertOk();

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'chat/completions')) {
                return false;
            }

            $systems = collect($request->data()['messages'])->where('role', 'system');

            return $systems->contains(fn (array $message): bool => str_contains($message['content'], 'Память о сотруднике'))
                && $systems->contains(fn (array $message): bool => str_contains($message['content'], 'Альфа'));
        });
    }

    /* -------------------- AI Insights -------------------- */

    public function test_insights_flag_overdue_and_missing_mark_for_own_scope(): void
    {
        Task::create([
            'company_id' => $this->company->id,
            'title' => 'Просроченная задача',
            'number' => 'T-D6-1',
            'author_id' => $this->adminEmployee->id,
            'status' => 'overdue',
        ])->assignments()->create([
            'assignee_type' => 'employee',
            'employee_id' => $this->worker->id,
            'assignment_status' => 'accepted',
        ]);

        // у работника нет analytics.view → scope own; /ai ему недоступен, зовём сервис
        $payload = app(AiPanelService::class)->respond($this->worker, 'На что обратить внимание?');

        self::assertStringContainsString('Просрочено задач: 1', $payload['reply']);
        self::assertStringContainsString('нет отметки прихода', $payload['reply']);
        self::assertSame('text', $payload['blocks'][0]['type'] ?? null);
        self::assertTrue((bool) ($payload['local_only'] ?? false));
        self::assertFalse($payload['grounded'], 'локальный ответ без LLM');
    }

    public function test_insights_report_company_issues_for_admin(): void
    {
        // все на работе, опозданий нет, заявок и задач нет → «спокойный» ответ
        foreach ([$this->adminEmployee, $this->worker] as $employee) {
            AttendanceRecord::create([
                'employee_id' => $employee->id,
                'date' => today(),
                'clock_in_at' => now()->subHour(),
                'late_minutes' => 0,
                'status' => AttendanceStatus::Present,
            ]);
        }

        $this->postJson('/ai/message', ['question' => 'На что обратить внимание?'])
            ->assertOk();

        $this->postJson('/ai/message', ['question' => 'Есть ли аномалии?'])->assertOk();

        self::assertStringContainsString(
            'отклонений не вижу',
            (string) AiConversation::query()->orderByDesc('id')->value('answer'),
        );
    }

    /* -------------------- AI Reports -------------------- */

    public function test_summary_report_returns_chart_table_and_actions(): void
    {
        $response = $this->postJson('/ai/message', ['question' => 'Построй сводный отчёт'])->assertOk();

        $types = array_column($response->json('blocks'), 'type');
        self::assertContains('chart', $types, 'диаграмма ключевых показателей');
        self::assertContains('table', $types, 'сводная таблица');
        self::assertContains('actions', $types, 'ссылки на раздел отчётов');
        self::assertStringContainsString('Сводка по компании', (string) $response->json('reply'));

        $urls = array_column($response->json('blocks.2.items'), 'url');
        self::assertContains('/reports', $urls);
    }

    /* -------------------- Doc Generator -------------------- */

    public function test_doc_generator_creates_text_draft_without_writing_documents(): void
    {
        $response = $this->postJson('/ai/message', ['question' => 'Сформируй справку о посещаемости'])
            ->assertOk();

        $blocks = $response->json('blocks');
        self::assertSame('text', $blocks[0]['type'] ?? null);
        self::assertStringContainsString('Справка', (string) ($blocks[0]['title'] ?? ''));
        self::assertStringContainsString('Посещаемость сегодня', (string) ($blocks[0]['content'] ?? ''));

        $actions = array_column($blocks[1]['items'] ?? [], 'url');
        self::assertContains('/documents/templates', $actions, 'deep-link на шаблоны документов');

        self::assertSame(0, Document::query()->count(), 'ИИ не пишет документы в БД');
    }

    /* -------------------- санитизация LLM-блоков -------------------- */

    public function test_llm_text_block_is_sanitized(): void
    {
        $this->enableAi();

        $llmBlocks = json_encode([
            'blocks' => [
                [
                    'type' => 'text',
                    'title' => str_repeat('Заголовок ', 30),
                    'content' => '<script>alert(1)</script>'.str_repeat('д', 5000),
                ],
                [
                    'type' => 'actions',
                    'items' => [['label' => 'Внешний', 'url' => 'https://evil.com/x']],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        Http::fake([
            'llm.example.com/v1/embeddings' => Http::response(['data' => []]),
            'llm.example.com/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => [
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_sanitize',
                        'type' => 'function',
                        'function' => ['name' => 'task_stats', 'arguments' => '{}'],
                    ]],
                ]]]])
                ->push(['choices' => [['message' => ['content' => "Черновик:\n```json\n{$llmBlocks}\n```"]]]]),
        ]);

        $response = $this->postJson('/ai/message', ['question' => 'Расскажи обстановку в компании'])
            ->assertOk();

        $blocks = $response->json('blocks');
        $textBlocks = array_values(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['type'] ?? null) === 'text',
        ));
        self::assertCount(1, $textBlocks, 'actions с внешним URL отброшен, остался text и блок источников');
        self::assertSame('text', $textBlocks[0]['type']);
        self::assertStringNotContainsString('<', (string) $textBlocks[0]['content'], 'HTML-теги вырезаны');
        self::assertLessThanOrEqual(4000, mb_strlen((string) $textBlocks[0]['content']));
        self::assertLessThanOrEqual(80, mb_strlen((string) $textBlocks[0]['title']));
    }
}
