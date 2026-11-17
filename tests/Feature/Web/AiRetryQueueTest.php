<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Ai\Jobs\RetryAiAnswer;
use App\Domains\Ai\Services\AiAssistantService;
use App\Models\AiConversation;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * D3: деградация AI вместо 30-секундного блока — потолок синхронного
 * ожидания LLM (corpms.ai.sync_timeout) + фоновый повтор в очереди.
 */
class AiRetryQueueTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Retry Co', 'code' => 'retryco']);
        $this->adminUser = User::factory()->create();
        $this->adminEmployee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $this->adminUser->id,
            'employee_no' => 'A-1',
            'first_name' => 'Админ',
            'last_name' => 'Админов',
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->actingAs($this->adminUser);
    }

    /** Включить LLM в тесте (в phpunit.xml он выключен). */
    protected function enableLlm(): void
    {
        config([
            'corpms.ai.base_url' => 'https://llm.example.com/v1',
            'corpms.ai.api_key' => 'secret-key',
        ]);
    }

    protected function llmFail(): void
    {
        Http::fake([
            'llm.example.com/v1/chat/completions' => Http::response(['error' => 'boom'], 500),
        ]);
    }

    /* -------------------- очередь повтора -------------------- */

    public function test_llm_failure_answers_locally_and_queues_retry(): void
    {
        $this->enableLlm();
        Queue::fake();
        $this->llmFail();

        $this->post('/ai', ['question' => 'Есть ли опоздания сегодня?'])->assertRedirect();

        $conversation = AiConversation::query()->sole();
        self::assertNotEmpty((string) $conversation->answer, 'Пользователь сразу получает локальный ответ');
        self::assertFalse((bool) $conversation->grounded);

        // синхронный вызов не прошёл → фоновый повтор поставлен в очередь
        Queue::assertPushed(
            RetryAiAnswer::class,
            fn (RetryAiAnswer $job): bool => $job->conversationId === $conversation->id && $job->panel === false,
        );
    }

    public function test_panel_llm_failure_queues_panel_retry(): void
    {
        $this->enableLlm();
        Queue::fake();
        $this->llmFail();

        $this->postJson('/ai/message', ['question' => 'Сколько просроченных задач?'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $conversation = AiConversation::query()->orderByDesc('id')->first();
        self::assertNotNull($conversation);
        self::assertFalse((bool) $conversation->grounded);

        Queue::assertPushed(
            RetryAiAnswer::class,
            fn (RetryAiAnswer $job): bool => $job->conversationId === $conversation->id && $job->panel === true,
        );
    }

    public function test_no_retry_queued_when_llm_disabled(): void
    {
        Queue::fake(); // AI в тестах выключен (phpunit.xml) — pointless-джобы не нужны

        $this->post('/ai', ['question' => 'Сколько задач у меня?'])->assertRedirect();

        $conversation = AiConversation::query()->sole();
        self::assertFalse((bool) $conversation->grounded);
        Queue::assertNothingPushed();
    }

    /* -------------------- выполнение повтора -------------------- */

    public function test_retry_job_upgrades_fallback_answer_to_grounded_llm(): void
    {
        $this->enableLlm();

        // диалог после синхронного сбоя LLM: локальный фолбэк, grounded=false
        $conversation = AiConversation::create([
            'employee_id' => $this->adminEmployee->id,
            'question' => 'Есть ли опоздания сегодня?',
            'answer' => 'Локальный фолбэк: сегодня опоздали 1.',
            'query_used' => ['scope' => 'company'],
            'grounded' => false,
        ]);

        // более ранняя реплика — должна попасть в контекст повторного вызова
        AiConversation::create([
            'employee_id' => $this->adminEmployee->id,
            'question' => 'Старый вопрос о численности',
            'answer' => 'Старый ответ ассистента',
            'query_used' => ['scope' => 'company'],
            'grounded' => true,
        ]);

        // LLM снова доступен — job выполняется
        Http::fake([
            'llm.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Финальный ответ LLM.']]],
            ]),
        ]);

        (new RetryAiAnswer($conversation->id))->handle();

        $conversation->refresh();
        self::assertTrue((bool) $conversation->grounded, 'Повтор должен обновить grounded');
        self::assertSame('Финальный ответ LLM.', (string) $conversation->answer);

        // контекст повтора: старая реплика есть, фолбэк-ответ текущего диалога — нет
        Http::assertSent(function ($request): bool {
            $contents = array_column($request->data()['messages'], 'content');

            return in_array('Старый вопрос о численности', $contents, true)
                && in_array('Старый ответ ассистента', $contents, true)
                && ! in_array('Локальный фолбэк: сегодня опоздали 1.', $contents, true);
        });

        $this->assertDatabaseHas('audit_logs', ['action' => 'ai.retried']);
    }

    public function test_retry_job_skips_when_already_grounded(): void
    {
        $this->enableLlm();
        Http::fake();

        $conversation = AiConversation::create([
            'employee_id' => $this->adminEmployee->id,
            'question' => 'Сколько людей в компании?',
            'answer' => 'Ответ LLM уже сохранён',
            'query_used' => ['scope' => 'company'],
            'grounded' => true,
        ]);

        (new RetryAiAnswer($conversation->id))->handle();

        Http::assertNothingSent();
        self::assertSame('Ответ LLM уже сохранён', (string) $conversation->fresh()->answer);
        self::assertTrue((bool) $conversation->fresh()->grounded);
    }

    public function test_panel_retry_parses_llm_blocks_and_strips_json(): void
    {
        $this->enableLlm();

        $conversation = AiConversation::create([
            'employee_id' => $this->adminEmployee->id,
            'question' => 'Какая численность?',
            'answer' => 'Локальный фолбэк панели',
            'query_used' => ['facts' => ['scope' => 'company'], 'blocks' => []],
            'grounded' => false,
        ]);

        Http::fake([
            'llm.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => "Численность: 2.\n```json\n{\"blocks\":[{\"type\":\"chart\",\"title\":\"Численность\",\"data\":[{\"label\":\"Активных\",\"value\":2}]}]}\n```"]]],
            ]),
        ]);

        (new RetryAiAnswer($conversation->id, true))->handle();

        $conversation->refresh();
        self::assertTrue((bool) $conversation->grounded);
        self::assertSame('Численность: 2.', (string) $conversation->answer);
        self::assertStringNotContainsString('```', (string) $conversation->answer, 'JSON-блок не должен попасть в текст ответа');
        // блоки LLM добавлены в query_used, потому что локальных блоков не было
        self::assertSame('chart', $conversation->query_used['blocks'][0]['type'] ?? null);
    }

    /* -------------------- потолок синхронного ожидания -------------------- */

    public function test_sync_timeout_caps_configured_llm_wait(): void
    {
        $service = app(AiAssistantService::class);

        config(['corpms.ai.sync_timeout' => 8]);
        self::assertSame(8, $service->syncTimeout(30), 'Синхронный вызов не ждёт дольше потолка');
        self::assertSame(5, $service->syncTimeout(5), 'Короткий таймаут не поднимается до потолка');

        // 0 — без потолка (полное настроенное ожидание)
        config(['corpms.ai.sync_timeout' => 0]);
        self::assertSame(30, $service->syncTimeout(30));
    }
}
