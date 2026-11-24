<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\DashboardGen\Jobs\GenerateAiDashboard;
use App\Domains\DashboardGen\Services\AiClient;
use App\Domains\DashboardGen\Services\ReadOnlySqlGuard;
use App\Domains\DashboardGen\Services\SqlParameterBinder;
use App\Domains\DashboardGen\Services\WidgetQueryRunner;
use App\Domains\DashboardGen\Services\WidgetShapeMapper;
use App\Models\BiDashboard;
use App\Models\BiWidget;
use App\Models\Company;
use App\Models\CrmClient;
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
 * Перенос пайплайна генерации Datavue в corpms: безопасный SQL, раскладка
 * форм данных, исполнение spec и сквозная генерация с подменённой моделью.
 */
class DashboardGenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Gen Co', 'code' => 'genco']);
        $this->user = User::factory()->create(['email' => 'gen-admin@corpms.demo']);

        $employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'employee_no' => 'G-1',
            'first_name' => 'Ген',
            'last_name' => 'Админ',
            'hired_at' => '2025-01-01',
            'status' => 'active',
        ]);

        EmployeeRole::create([
            'employee_id' => $employee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->actingAs($this->user);

        config()->set('corpms.ai.base_url', 'https://api.openai.test/v1');
        config()->set('corpms.ai.api_key', 'test-key');
        config()->set('corpms.ai.model', 'test-model');
    }

    public function test_read_only_guard_blocks_mutations_and_stacked_queries(): void
    {
        foreach ([
            'DELETE FROM crm_clients',
            'UPDATE crm_clients SET name = "x"',
            'SELECT 1; DROP TABLE crm_clients',
            'SELECT 1 -- comment',
        ] as $index => $sql) {
            if ($index === 3) {
                $this->assertSame('SELECT 1 LIMIT 10', ReadOnlySqlGuard::sanitize($sql, 10));
                continue;
            }

            try {
                ReadOnlySqlGuard::sanitize($sql, 10);
                $this->fail('Опасный SQL должен отклоняться: '.$sql);
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_parameter_binder_ignores_placeholders_inside_string_literals(): void
    {
        $result = (new SqlParameterBinder())->apply(
            "SELECT ':company_id' AS literal, name FROM crm_clients WHERE company_id = :company_id",
            ['company_id' => 7],
            ['company_id' => SqlParameterBinder::TYPE_INT],
        );

        $this->assertStringContainsString("':company_id' AS literal", $result['sql']);
        $this->assertStringContainsString('company_id = ?', $result['sql']);
        $this->assertSame([7], $result['bindings']);
    }

    public function test_shape_mapper_builds_matrix_from_flat_rows(): void
    {
        $content = (new WidgetShapeMapper())->map(
            'bar',
            'column',
            WidgetShapeMapper::SHAPE_SERIES_MATRIX,
            [
                ['series' => 'Выручка', 'category' => 'Январь', 'value' => 10],
                ['series' => 'Выручка', 'category' => 'Февраль', 'value' => 20],
            ],
        );

        $this->assertSame(['Январь', 'Февраль'], $content['categories']);
        $this->assertSame([10, 20], $content['series'][0]['data']);
    }

    public function test_query_runner_applies_company_filter(): void
    {
        CrmClient::create([
            'company_id' => $this->company->id,
            'name' => 'Наш клиент',
            'kind' => 'company',
            'status' => 'active',
        ]);

        $other = Company::create(['name' => 'Other Co', 'code' => 'otherco']);
        CrmClient::create([
            'company_id' => $other->id,
            'name' => 'Чужой клиент',
            'kind' => 'company',
            'status' => 'active',
        ]);

        $spec = [
            'shape' => WidgetShapeMapper::SHAPE_SERIES_VALUES,
            'queries' => [
                'main' => 'SELECT name AS label, COUNT(*) AS value FROM crm_clients '
                    .'WHERE company_id = :company_id GROUP BY name',
            ],
        ];

        $result = (new WidgetQueryRunner(companyId: $this->company->id))->run($spec, 'pie');

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame(['Наш клиент'], $result['data']['labels']);
    }

    public function test_generation_pipeline_builds_ready_sql_widgets(): void
    {
        CrmClient::create([
            'company_id' => $this->company->id,
            'name' => 'Клиент',
            'kind' => 'company',
            'status' => 'active',
        ]);

        Http::fakeSequence()->push([
            'choices' => [['message' => ['content' => json_encode([
                'dashboard_name' => 'Обзор продаж',
                'widgets' => [
                    [
                        'name' => 'bar',
                        'title' => 'Клиенты по месяцам',
                        'instruction' => 'Столбцы по количеству клиентов',
                        'tables' => ['crm_clients'],
                    ],
                    [
                        'name' => 'mini-counters',
                        'title' => 'Всего клиентов',
                        'instruction' => 'Один счётчик',
                        'tables' => ['crm_clients'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE)]]],
        ])->push([
            'choices' => [['message' => ['content' => json_encode([
                'queries' => [
                    'main' => "SELECT 'Клиенты' AS series, 'Все' AS category, COUNT(*) AS value "
                        .'FROM crm_clients WHERE company_id = :company_id',
                ],
                'shape' => 'series_matrix',
                'presentation' => [],
            ], JSON_UNESCAPED_UNICODE)]]],
        ])->push([
            'choices' => [['message' => ['content' => json_encode([
                'queries' => [
                    'main' => "SELECT 'Клиентов' AS name, COUNT(*) AS value FROM crm_clients WHERE company_id = :company_id",
                ],
                'shape' => 'counters',
                'presentation' => [],
            ], JSON_UNESCAPED_UNICODE)]]],
        ]);

        $dashboard = BiDashboard::create([
            'company_id' => $this->company->id,
            'name' => 'Генерация',
            'created_by' => $this->user->id,
            'origin' => 'ai',
            'status' => 'generating',
            'instruction' => 'Показать клиентов',
        ]);

        GenerateAiDashboard::dispatch(
            $dashboard->id,
            $this->company->id,
            $this->user->id,
            'Показать клиентов',
        );

        $dashboard->refresh();

        $this->assertSame('ready', $dashboard->status);
        $this->assertSame('Обзор продаж', $dashboard->name);
        $this->assertSame(2, $dashboard->widgets()->count());

        foreach ($dashboard->widgets as $widget) {
            $this->assertSame('sql', $widget->content_mode);
            $this->assertSame('ready', $widget->status);
            $this->assertNotNull($widget->query_spec);
            $this->assertNotNull($widget->widget_family);
        }

        $this->get(route('bi.dashboards.show', $dashboard))
            ->assertOk()
            ->assertSee('Клиенты по месяцам');
    }

    public function test_ai_client_sends_token_limit_and_retries_rate_limit(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['message' => 'TPM limit']], 429, ['Retry-After' => '0'])
            ->push(['choices' => [['message' => ['content' => '{"ok":true}']]]]);

        $answer = app(AiClient::class)->chat('Тест', 'Система', 1234);

        $this->assertSame(['ok' => true], $answer['json']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => ($request->data()['max_tokens'] ?? null) === 1234);
    }

    public function test_generate_route_creates_dashboard_and_queues_job(): void
    {
        Queue::fake();

        $response = $this->post(route('bi.dashboards.generate'), [
            'instruction' => 'Доходы и расходы по месяцам',
        ]);

        $dashboard = BiDashboard::query()->where('origin', 'ai')->firstOrFail();

        $response->assertRedirect(route('bi.dashboards.show', $dashboard));

        $this->assertSame('Доходы и расходы по месяцам', $dashboard->name);
        $this->assertSame('generating', $dashboard->status);

        Queue::assertPushed(GenerateAiDashboard::class);

        $this->get(route('bi.index'))
            ->assertOk()
            ->assertSee('data-bi-mode', false)
            ->assertSee('bm-pet__papers', false)
            ->assertDontSee('bm-pet__sketch', false)
            ->assertSee('Bumi · BI')
            ->assertSee(route('bi.dashboards.generate'), false);

        $this->get(route('bi.dashboards.show', $dashboard))
            ->assertOk()
            ->assertDontSee('bi-generating-banner', false)
            ->assertDontSee('data-bm-work', false)
            ->assertSee('bumi-gen-state', false)
            ->assertSee('data-status-url', false)
            ->assertSee('data-bi-mode', false)
            ->assertSee('bm-pet__papers', false)
            ->assertDontSee('bm-pet__sketch', false)
            ->assertSee('bi-skeleton', false)
            ->assertDontSee('bi-gen-veil__ring', false)
            ->assertDontSee('bi-art-card__orb', false);
    }

    public function test_generate_route_rejects_non_bi_requests(): void
    {
        Queue::fake();

        $response = $this->post(route('bi.dashboards.generate'), [
            'instruction' => 'про погоду в худжанде',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('instruction');

        $this->assertSame(0, BiDashboard::query()->where('origin', 'ai')->count());
        Queue::assertNotPushed(GenerateAiDashboard::class);
    }

    public function test_generation_status_exposes_live_widget_progress(): void
    {
        $dashboard = BiDashboard::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Статус генерации',
            'is_default' => false,
            'created_by' => $this->user->id,
            'origin' => 'ai',
            'status' => 'generating',
            'instruction' => 'Доходы по месяцам',
        ]);

        $ready = BiWidget::query()->create([
            'dashboard_id' => $dashboard->id,
            'title' => 'Готовый виджет',
            'kind' => 'kpi',
            'position' => 0,
            'widget_family' => 'mini-counters',
            'content_mode' => 'sql',
            'status' => 'ready',
            'query_spec' => json_encode(['queries' => ['main' => 'SELECT 1'], 'shape' => 'counters', 'presentation' => []]),
        ]);

        BiWidget::query()->create([
            'dashboard_id' => $dashboard->id,
            'title' => 'Ожидающий виджет',
            'kind' => 'kpi',
            'position' => 1,
            'widget_family' => 'line',
            'content_mode' => 'sql',
            'status' => 'pending',
        ]);

        $this->get(route('bi.dashboards.generation-status', $dashboard))
            ->assertOk()
            ->assertJsonPath('status', 'generating')
            ->assertJsonPath('total', 2)
            ->assertJsonPath('ready', 1)
            ->assertJsonPath('widgets.0.id', $ready->id)
            ->assertJsonPath('widgets.0.ready', true)
            ->assertJsonPath('widgets.1.ready', false);
    }
}
