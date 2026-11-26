<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Ai\Services\AiToolRegistry;
use App\Domains\DashboardGen\Services\BiAnalystChat;
use App\Domains\DashboardGen\Services\BiToolContext;
use App\Models\BiChatMessage;
use App\Models\BiDashboard;
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
 * Bumi /bi: инструментальные вызовы на текущем дашборде, откат и обратная связь.
 */
class BiChatToolsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $employee;

    private BiDashboard $dashboard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Chat Co', 'code' => 'chatco']);
        $this->user = User::factory()->create(['email' => 'bi-chat@corpms.demo']);
        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'employee_no' => 'C-1',
            'first_name' => 'Бими',
            'last_name' => 'Аналитик',
            'hired_at' => '2025-01-01',
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $this->employee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->actingAs($this->user);

        config()->set('corpms.ai.base_url', 'https://api.openai.test/v1');
        config()->set('corpms.ai.api_key', 'test-key');
        config()->set('corpms.ai.model', 'test-model');

        $this->dashboard = BiDashboard::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Тестовый дашборд',
            'created_by' => $this->user->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function structuredSpec(): array
    {
        return [
            'queries' => [
                'main' => [
                    'from' => 'crm_clients',
                    'alias' => 'c',
                    'select' => [
                        ['expr' => 'literal', 'value' => 'Клиенты', 'as' => 'name'],
                        ['expr' => 'count', 'as' => 'value'],
                    ],
                ],
            ],
            'shape' => 'counters',
            'presentation' => [],
        ];
    }

    /** @param list<array<string, mixed>> $calls */
    private function fakeAi(array $calls): void
    {
        $first = ['choices' => [['message' => [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => $calls,
        ]]]];
        $second = ['choices' => [['message' => [
            'role' => 'assistant',
            'content' => 'Готово: дашборд обновлён.',
        ]]]];

        Http::fake([
            '*' => Http::sequence()->push($first)->push($second),
        ]);
    }

    /** @return array<string, mixed> */
    private function addWidgetCall(string $title = 'Клиенты'): array
    {
        return [
            'id' => 'call_1',
            'type' => 'function',
            'function' => [
                'name' => 'add_widget',
                'arguments' => json_encode([
                    'title' => $title,
                    'family' => 'mini-counters',
                    'type' => 'cards',
                    'spec' => $this->structuredSpec(),
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];
    }

    public function test_bi_tools_are_hidden_from_the_general_ai_panel(): void
    {
        $registry = app(AiToolRegistry::class);

        $general = array_column(array_column($registry->toolsFor($this->employee), 'function'), 'name');
        $bi = array_column(array_column($registry->toolsFor($this->employee, 'bi'), 'function'), 'name');

        $this->assertNotContains('add_widget', $general);
        $this->assertNotContains('set_filter', $general);
        $this->assertContains('add_widget', $bi);
        $this->assertContains('update_widget', $bi);
        $this->assertContains('set_filter', $bi);
    }

    public function test_add_widget_tool_compiles_spec_and_records_undo(): void
    {
        $context = new BiToolContext($this->dashboard);

        $result = app(AiToolRegistry::class)->execute($this->employee, 'add_widget', [
            'title' => 'Клиенты',
            'family' => 'mini-counters',
            'type' => 'cards',
            'spec' => $this->structuredSpec(),
        ], $context);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $widget = $this->dashboard->widgets()->firstOrFail();
        $this->assertSame('sql', $widget->content_mode);
        $this->assertSame('mini-counters', $widget->widget_family);
        $this->assertSame('bi_query_spec_v1', $widget->query_spec['compiler']);
        $this->assertArrayHasKey('source', $widget->query_spec);
        $this->assertSame([['kind' => 'delete_widget', 'widget_id' => $widget->id]], $context->undo);
    }

    public function test_undo_restores_filters_titles_and_removes_created_widget(): void
    {
        $context = new BiToolContext($this->dashboard);
        $registry = app(AiToolRegistry::class);

        $registry->execute($this->employee, 'add_widget', [
            'title' => 'Клиенты',
            'family' => 'mini-counters',
            'spec' => $this->structuredSpec(),
        ], $context);

        $widget = $this->dashboard->widgets()->firstOrFail();

        $registry->execute($this->employee, 'update_widget', [
            'widget_id' => $widget->id,
            'title' => 'Переименованный',
        ], $context);

        $filters = [['column' => 'c.name', 'op' => 'like', 'value' => '%ООО%']];
        $registry->execute($this->employee, 'set_filter', ['filters' => $filters], $context);

        $this->dashboard->refresh();
        $this->assertSame($filters, $this->dashboard->filters);
        $this->assertSame('Переименованный', $widget->fresh()->title);

        $message = BiChatMessage::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'dashboard_id' => $this->dashboard->id,
            'role' => 'assistant',
            'content' => 'Изменения внесены',
            'payload' => ['effects' => [], 'undo' => $context->undo],
        ]);

        app(BiAnalystChat::class)->undo($this->user, $this->dashboard, $message);

        $this->assertSame(0, $this->dashboard->widgets()->count());
        $this->assertSame([], $this->dashboard->fresh()->filters);
        $this->assertNotNull($message->fresh()->undone_at);
    }

    public function test_invalid_filter_is_reported_without_changing_the_dashboard(): void
    {
        $result = app(AiToolRegistry::class)->execute($this->employee, 'set_filter', [
            'filters' => [['column' => 'c.name; DROP TABLE crm_clients', 'op' => '=']],
        ], new BiToolContext($this->dashboard));

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_filter_column', $result['error']);
        $this->assertEmpty($this->dashboard->fresh()->filters);
    }

    public function test_chat_rejects_non_bi_request_before_calling_the_model(): void
    {
        Http::fake();

        $this->postJson(route('bi.dashboards.chat', $this->dashboard), [
            'message' => 'Какая погода в Худжанде завтра?',
        ])->assertStatus(422)->assertJsonPath('ok', false);

        Http::assertNothingSent();
        $this->assertSame(0, BiChatMessage::query()->count());
    }

    public function test_chat_executes_tool_calls_and_supports_undo_and_rating(): void
    {
        $this->fakeAi([$this->addWidgetCall()]);

        $response = $this->postJson(route('bi.dashboards.chat', $this->dashboard), [
            'message' => 'Покажи метрику числа клиентов на дашборде',
        ])->assertOk()->assertJsonPath('ok', true)->assertJsonPath('undoable', true);

        $this->assertSame(1, $this->dashboard->widgets()->count());

        $messageId = (int) $response->json('id');
        $message = BiChatMessage::query()->findOrFail($messageId);
        $this->assertSame('add_widget', $message->payload['effects'][0]['tool']);
        $this->assertNotEmpty($message->payload['undo']);

        $this->postJson(route('bi.chat.rating', ['message' => $message]), ['rating' => 5])
            ->assertOk()->assertJsonPath('rating', 5);
        $this->assertSame(5, $message->fresh()->rating);

        $this->postJson(route('bi.chat.undo', ['dashboard' => $this->dashboard, 'message' => $message]))
            ->assertOk()->assertJsonPath('refresh', true);

        $this->assertSame(0, $this->dashboard->widgets()->count());
        $this->assertNotNull($message->fresh()->undone_at);
    }

    public function test_chat_is_scoped_to_the_tenant(): void
    {
        $otherCompany = Company::create(['name' => 'Other Co', 'code' => 'otherco']);
        $foreign = BiDashboard::query()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Чужой дашборд',
        ]);

        Http::fake();

        $this->postJson(route('bi.dashboards.chat', $foreign), [
            'message' => 'Покажи метрику клиентов на дашборде',
        ])->assertForbidden();

        Http::assertNothingSent();
    }
}
