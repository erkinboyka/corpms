<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Ai\Services\AiToolRegistry;
use App\Models\AiConversation;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * D5 (tool calling): белый список backend-инструментов для LLM.
 *
 * Проверяем: фильтрацию инструментов по правам, валидацию аргументов,
 * аудит вызовов/отказов, отсутствие записи в БД при критических операциях
 * (deep-link подтверждения) и один раунд tool calling в /ai.
 */
class AiToolCallingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected User $workerUser;

    protected Employee $worker;

    protected DocumentCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Tools Co', 'code' => 'toolsco']);
        $this->category = DocumentCategory::create(['code' => 'TLC', 'name' => 'Tool-документы']);

        $this->adminUser = User::factory()->create();
        $this->adminEmployee = $this->employee('A-1', 'Админ', 'Инструментов', $this->adminUser->id);
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

    public function test_tool_properties_are_json_objects_even_when_empty(): void
    {
        $tools = json_decode(json_encode(app(AiToolRegistry::class)->toolsFor($this->adminEmployee)), false);
        $this->assertNotEmpty($tools);
        foreach ($tools as $tool) {
            $this->assertIsObject($tool->function->parameters->properties, $tool->function->name);
        }
    }

    protected function document(string $title, string $body, string $number, ?Employee $author = null): Document
    {
        return Document::create([
            'company_id' => $this->company->id,
            'author_id' => ($author ?? $this->adminEmployee)->id,
            'category_id' => $this->category->id,
            'title' => $title,
            'document_number' => $number,
            'body' => $body,
            'status' => 'draft',
            'confidentiality' => 'internal',
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

    /** Имена инструментов в формате OpenAI tools. */
    protected function toolNames(array $tools): array
    {
        return array_map(static fn (array $tool): string => $tool['function']['name'], $tools);
    }

    /* -------------------- белый список и права -------------------- */

    public function test_tools_are_filtered_by_permission(): void
    {
        $registry = app(AiToolRegistry::class);

        $adminNames = $this->toolNames($registry->toolsFor($this->adminEmployee));
        self::assertContains('create_task', $adminNames, 'super_admin видит все инструменты');
        self::assertContains('task_stats', $adminNames);
        self::assertContains('search_documents', $adminNames);

        // employee: documents/tasks/attendance.view = own, нет tasks.create и analytics.view
        $workerNames = $this->toolNames($registry->toolsFor($this->worker));
        self::assertContains('search_documents', $workerNames);
        self::assertContains('search_tasks', $workerNames);
        self::assertContains('attendance_summary', $workerNames);
        self::assertNotContains('create_task', $workerNames, 'без tasks.create инструмент не предлагается');
        self::assertNotContains('task_stats', $workerNames, 'без analytics.view инструмент не предлагается');
    }

    public function test_disabled_config_hides_and_blocks_tools(): void
    {
        config(['corpms.ai.tools' => false]);

        $registry = app(AiToolRegistry::class);
        self::assertSame([], $registry->toolsFor($this->adminEmployee));

        $result = $registry->execute($this->adminEmployee, 'search_documents', ['query' => 'бюджет']);
        self::assertFalse($result['ok']);
        self::assertSame('disabled', $result['error']);
    }

    public function test_search_documents_returns_deep_links_and_is_audited(): void
    {
        $document = $this->document('Приказ о бюджете', 'Бюджет утверждён.', 'Д-TLC-1');

        $result = app(AiToolRegistry::class)->execute($this->adminEmployee, 'search_documents', ['query' => 'бюджет']);

        self::assertTrue($result['ok']);
        self::assertSame(1, $result['result']['count']);
        self::assertSame('Приказ о бюджете', $result['result']['items'][0]['title']);
        self::assertStringContainsString('/documents/'.$document->id, $result['result']['items'][0]['url']);

        self::assertDatabaseHas('audit_logs', [
            'action' => 'ai.tool.called',
            'object_type' => 'ai_tool',
        ]);
    }

    public function test_search_documents_respects_scope_of_caller(): void
    {
        // документ написан админом, у worker documents.view = own → чужой не виден
        $this->document('Приказ о бюджете', 'Бюджет утверждён.', 'Д-TLC-1');

        $result = app(AiToolRegistry::class)->execute($this->worker, 'search_documents', ['query' => 'бюджет']);

        self::assertTrue($result['ok'], 'инструмент доступен, но scope применяется');
        self::assertSame(0, $result['result']['count'], 'чужой документ вне own-scope не возвращается');
    }

    /* -------------------- валидация аргументов -------------------- */

    public function test_invalid_arguments_are_rejected_and_audited(): void
    {
        $registry = app(AiToolRegistry::class);

        $missing = $registry->execute($this->adminEmployee, 'search_documents', []);
        self::assertFalse($missing['ok']);
        self::assertSame('missing:query', $missing['error']);

        $unexpected = $registry->execute($this->adminEmployee, 'search_documents', ['query' => 'x', 'evil' => 'y']);
        self::assertSame('unexpected:evil', $unexpected['error']);

        $wrongType = $registry->execute($this->adminEmployee, 'search_documents', ['query' => 42]);
        self::assertSame('type:query', $wrongType['error']);

        $unknown = $registry->execute($this->adminEmployee, 'drop_database', []);
        self::assertFalse($unknown['ok']);
        self::assertSame('unknown_tool', $unknown['error']);

        self::assertSame(4, AuditLog::query()->where('action', 'ai.tool.invalid')->count());
    }

    public function test_denied_tool_writes_audit_and_creates_nothing(): void
    {
        // у employee нет tasks.create → прямой вызов инструмента запрещён
        $result = app(AiToolRegistry::class)->execute($this->worker, 'create_task', ['title' => 'Взлом']);

        self::assertFalse($result['ok']);
        self::assertSame('forbidden', $result['error']);
        self::assertSame(0, Task::query()->count());
        self::assertDatabaseHas('audit_logs', ['action' => 'ai.tool.denied']);
    }

    /* -------------------- критические операции -------------------- */

    public function test_critical_tool_requires_confirmation_and_does_not_write(): void
    {
        $result = app(AiToolRegistry::class)->execute($this->adminEmployee, 'create_task', [
            'title' => 'Отчёт за ноябрь',
            'description' => 'Собрать данные',
        ]);

        self::assertTrue($result['ok'], 'операция корректна, но требует подтверждения');
        self::assertTrue($result['result']['needs_confirmation']);
        self::assertSame('Отчёт за ноябрь', $result['result']['draft']['title']);
        self::assertStringContainsString('/tasks', $result['result']['confirm_url']);
        self::assertStringContainsString('#new-task', $result['result']['confirm_url']);
        self::assertSame(0, Task::query()->count(), 'ИИ не пишет в БД — только deep-link подтверждения');
    }

    /* -------------------- интеграция с LLM (/ai) -------------------- */

    public function test_assistant_executes_tool_call_roundtrip(): void
    {
        $this->enableAi();
        $this->document('Приказ о бюджете', 'Бюджет утверждён.', 'Д-TLC-1');

        Http::fake([
            'llm.example.com/v1/embeddings' => Http::response(['data' => []]),
            'llm.example.com/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => [
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'search_documents', 'arguments' => '{"query":"бюджет"}'],
                    ]],
                ]]]])
                ->push(['choices' => [['message' => ['content' => 'Нашёл приказ о бюджете — откройте по ссылке.']]]]),
        ]);

        $this->post('/ai', ['question' => 'Найди документ по бюджету'])->assertRedirect();

        $conversation = AiConversation::query()->sole();
        self::assertTrue((bool) $conversation->grounded, 'финальный ответ после tool round подтверждён источниками');
        self::assertSame('Нашёл приказ о бюджете — откройте по ссылке.', (string) $conversation->answer);
        self::assertNotEmpty($conversation->query_used['source_items'] ?? [], 'AI-02: источники инструмента сохраняются');
        self::assertStringContainsString('Приказ о бюджете', (string) ($conversation->query_used['source_items'][0]['label'] ?? ''));

        // первый запрос — с tools; второй — с результатом инструмента в role=tool
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'chat/completions')) {
                return false;
            }
            $data = $request->data();
            if (! isset($data['tools'])) {
                return false;
            }
            $names = array_map(static fn (array $tool): string => $tool['function']['name'], $data['tools']);

            return in_array('search_documents', $names, true);
        });

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'chat/completions')) {
                return false;
            }
            $tools = collect($request->data()['messages'])->where('role', 'tool');

            return $tools->contains(function (array $message): bool {
                $content = json_decode((string) $message['content'], true);

                return is_array($content)
                    && ($content['ok'] ?? false) === true
                    && str_contains((string) ($content['result']['items'][0]['title'] ?? ''), 'Приказ о бюджете');
            });
        });

        // журнал действий инструмента
        self::assertDatabaseHas('audit_logs', ['action' => 'ai.tool.called']);
    }

    public function test_local_fallback_when_tool_round_fails(): void
    {
        $this->enableAi();
        Queue::fake();
        Http::fake([
            'llm.example.com/v1/embeddings' => Http::response(['data' => []]),
            'llm.example.com/v1/chat/completions' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->post('/ai', ['question' => 'Сколько людей в компании?'])->assertRedirect();

        $conversation = AiConversation::query()->sole();
        self::assertFalse((bool) $conversation->grounded, 'сбой LLM → локальный фолбэк');
        self::assertNotEmpty((string) $conversation->answer);
    }
}
