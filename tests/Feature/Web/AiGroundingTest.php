<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

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
use Tests\TestCase;

/**
 * AI-01/AI-02/AI-05: grounded означает не «LLM ответил», а «есть
 * подтверждённые источники», сохранённые в query_used.source_items.
 */
class AiGroundingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->company = Company::create(['name' => 'Grounding Co', 'code' => 'grounding']);
        $this->adminUser = User::factory()->create();
        $this->adminEmployee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $this->adminUser->id,
            'employee_no' => 'G-1',
            'first_name' => 'Админ',
            'last_name' => 'Источников',
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->actingAs($this->adminUser);
    }

    protected function enableLlm(): void
    {
        config([
            'corpms.ai.base_url' => 'https://llm.example.com/v1',
            'corpms.ai.api_key' => 'secret-key',
        ]);
    }

    /** LLM вызывает task_stats, затем даёт финальный текст. */
    protected function fakeToolGroundedAnswer(string $final): void
    {
        Http::fake([
            'llm.example.com/v1/embeddings' => Http::response(['data' => []]),
            'llm.example.com/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => [
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_grounding',
                        'type' => 'function',
                        'function' => ['name' => 'task_stats', 'arguments' => '{}'],
                    ]],
                ]]]])
                ->push(['choices' => [['message' => ['content' => $final]]]]),
        ]);
    }

    public function test_llm_text_without_sources_is_not_grounded_and_uses_local_answer(): void
    {
        $this->enableLlm();
        Http::fake([
            'llm.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Выдуманный ответ без источников.']]],
            ]),
        ]);

        $this->post('/ai', ['question' => 'Есть ли опоздания сегодня?'])->assertRedirect();

        $conversation = AiConversation::query()->sole();
        self::assertFalse((bool) $conversation->grounded);
        self::assertStringNotContainsString('Выдуманный ответ', (string) $conversation->answer);
        self::assertNotEmpty((string) $conversation->answer, 'Должен быть явный локальный фолбэк');
        self::assertSame([], $conversation->query_used['source_items'] ?? null);
    }

    public function test_tool_call_sources_are_persisted_and_make_answer_grounded(): void
    {
        $this->enableLlm();
        $this->fakeToolGroundedAnswer('По данным задач: всё в работе.');

        $this->post('/ai', ['question' => 'Сколько задач в работе?'])->assertRedirect();

        $conversation = AiConversation::query()->sole();
        self::assertTrue((bool) $conversation->grounded);
        self::assertSame('По данным задач: всё в работе.', (string) $conversation->answer);

        $sources = $conversation->query_used['source_items'] ?? [];
        self::assertNotEmpty($sources, 'Источники инструмента должны сохраняться в query_used');
        self::assertNotEmpty($sources[0]['label'] ?? null);
    }

    public function test_retry_without_sources_does_not_upgrade_grounded(): void
    {
        $this->enableLlm();
        $conversation = AiConversation::create([
            'employee_id' => $this->adminEmployee->id,
            'question' => 'Есть ли опоздания сегодня?',
            'answer' => 'Локальный фолбэк',
            'query_used' => ['scope' => 'company', 'source_items' => []],
            'grounded' => false,
        ]);

        Http::fake([
            'llm.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Повтор без источников.']]],
            ]),
        ]);

        self::assertFalse(app(AiAssistantService::class)->retryLlm($conversation));
        self::assertFalse((bool) $conversation->fresh()->grounded);
        self::assertSame('Локальный фолбэк', (string) $conversation->fresh()->answer);
    }

    public function test_retry_with_tool_sources_updates_answer_and_sources(): void
    {
        $this->enableLlm();
        $conversation = AiConversation::create([
            'employee_id' => $this->adminEmployee->id,
            'question' => 'Сколько задач в работе?',
            'answer' => 'Локальный фолбэк',
            'query_used' => ['scope' => 'company', 'source_items' => []],
            'grounded' => false,
        ]);
        $this->fakeToolGroundedAnswer('Финальный ответ после повтора.');

        self::assertTrue(app(AiAssistantService::class)->retryLlm($conversation));

        $conversation->refresh();
        self::assertTrue((bool) $conversation->grounded);
        self::assertSame('Финальный ответ после повтора.', (string) $conversation->answer);
        self::assertNotEmpty($conversation->query_used['source_items'] ?? []);
    }

    public function test_panel_persists_source_items_and_returns_sources_block(): void
    {
        $this->enableLlm();
        $this->fakeToolGroundedAnswer('Панель: по данным задач.');

        $response = $this->postJson('/ai/message', ['question' => 'Сколько задач в работе?'])->assertOk();

        $blocks = collect($response->json('blocks'));
        self::assertTrue($blocks->contains('type', 'sources'), 'В UI должен быть блок «Источники ответа»');
        self::assertStringContainsString('Источники', (string) $response->json('blocks_html'));

        $conversation = AiConversation::query()->orderByDesc('id')->firstOrFail();
        self::assertTrue((bool) $conversation->grounded);
        self::assertNotEmpty($conversation->query_used['source_items'] ?? []);
    }
}
