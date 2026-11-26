<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\DashboardGen\Jobs\GenerateAiDashboard;
use App\Domains\DashboardGen\Services\AiClient;
use App\Domains\DashboardGen\Services\ReadOnlySqlGuard;
use App\Domains\DashboardGen\Services\SchemaInspector;
use App\Domains\DashboardGen\Services\SqlParameterBinder;
use App\Domains\DashboardGen\Services\WidgetQueryRunner;
use App\Domains\DashboardGen\Services\WidgetShapeMapper;
use App\Domains\DashboardGen\Services\WidgetSpecCompiler;
use App\Domains\DashboardGen\Services\WidgetSpecGenerator;
use App\Domains\DashboardGen\Services\WidgetSpecValidator;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function test_schema_inspector_reports_real_tables_and_columns(): void
    {
        $schema = (new SchemaInspector(DB::connection()))->schema([
            'crm_clients',
            'crm_deals',
            'no_such_table',
        ]);

        $this->assertArrayHasKey('crm_clients', $schema);
        $this->assertArrayHasKey('crm_deals', $schema);
        $this->assertArrayNotHasKey('no_such_table', $schema);
        $this->assertArrayHasKey('company_id', $schema['crm_clients']);
        $this->assertArrayHasKey('name', $schema['crm_clients']);
        $this->assertArrayHasKey('amount', $schema['crm_deals']);
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

    public function test_generation_pipeline_compiles_structured_query_spec(): void
    {
        CrmClient::create([
            'company_id' => $this->company->id,
            'name' => 'Клиент',
            'kind' => 'company',
            'status' => 'active',
        ]);

        Http::fakeSequence()->push([
            'choices' => [['message' => ['content' => json_encode([
                'dashboard_name' => 'Структурный BI',
                'widgets' => [[
                    'name' => 'mini-counters',
                    'title' => 'Клиенты',
                    'instruction' => 'Посчитать клиентов',
                    'tables' => ['crm_clients'],
                    'span' => 12,
                ]],
            ], JSON_UNESCAPED_UNICODE)]]],
        ])->push([
            'choices' => [['message' => ['content' => json_encode([
                'queries' => [
                    'main' => [
                        'from' => 'crm_clients',
                        'alias' => 'c',
                        'select' => [
                            ['expr' => 'literal', 'value' => 'Клиентов', 'as' => 'name'],
                            ['expr' => 'count', 'as' => 'value'],
                        ],
                        'where' => [
                            ['column' => 'c.status', 'op' => '=', 'value' => 'active'],
                        ],
                        'limit' => 10,
                    ],
                ],
                'shape' => 'counters',
                'presentation' => [],
            ], JSON_UNESCAPED_UNICODE)]]],
        ]);

        $dashboard = BiDashboard::create([
            'company_id' => $this->company->id,
            'name' => 'Структура',
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
        $widget = $dashboard->widgets()->firstOrFail();
        $spec = $widget->query_spec;

        $this->assertSame(12, $widget->span);
        $this->assertSame('bi_query_spec_v1', $spec['compiler'] ?? null);
        $this->assertContains('Клиентов', array_values($spec['bindings'] ?? []));
        $this->assertContains('active', array_values($spec['bindings'] ?? []));
        $this->assertStringContainsString('= :p', (string) ($spec['queries']['main'] ?? ''));
        $this->assertStringNotContainsString("'active'", (string) ($spec['queries']['main'] ?? ''));
        $this->assertStringContainsString(':company_id', (string) ($spec['queries']['main'] ?? ''));
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

        $this->assertSame('Генерация дашборда', $dashboard->name);
        $this->assertSame('Доходы и расходы по месяцам', $dashboard->instruction);
        $this->assertSame('generating', $dashboard->status);

        Queue::assertPushed(GenerateAiDashboard::class);

        $this->get(route('bi.index'))
            ->assertOk()
            ->assertSee('data-bi-mode', false)
            ->assertSee('bm-pet__papers', false)
            ->assertDontSee('bm-pet__sketch', false)
            ->assertSee('Bumi · аналитик')
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

    public function test_validator_rejects_tenant_placeholder_in_comments_and_literals(): void
    {
        CrmClient::create([
            'company_id' => $this->company->id,
            'name' => 'Клиент',
            'kind' => 'company',
            'status' => 'active',
        ]);

        $schema = (new SchemaInspector(DB::connection()))->schema(['crm_clients']);
        $validator = new WidgetSpecValidator(
            new WidgetQueryRunner(companyId: $this->company->id),
            $schema,
            array_keys($schema),
        );

        $withComment = "SELECT name AS label, COUNT(*) AS value FROM crm_clients\n"
            . '-- company_id = :company_id'."\n"
            . 'GROUP BY name';
        $result = $validator->validate(['queries' => ['main' => $withComment]], 'pie');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('company_id', implode(' ', $result['errors']));

        $withLiteral = "SELECT ':company_id' AS label, COUNT(*) AS value FROM crm_clients GROUP BY label";
        $result = $validator->validate(['queries' => ['main' => $withLiteral]], 'pie');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('company_id', implode(' ', $result['errors']));

        $valid = 'SELECT name AS label, COUNT(*) AS value FROM crm_clients '
            . 'WHERE company_id = :company_id GROUP BY name';
        $result = $validator->validate(['queries' => ['main' => $valid]], 'pie');
        $this->assertTrue($result['ok'], implode(' ', $result['errors']));
        $this->assertContains('label', $result['columns']);
    }

    public function test_widget_spec_generator_repairs_invalid_spec_with_model(): void
    {
        config()->set('dashboard_gen.max_attempts', 2);

        CrmClient::create([
            'company_id' => $this->company->id,
            'name' => 'Клиент',
            'kind' => 'company',
            'status' => 'active',
        ]);

        Http::fakeSequence()->push([
            'choices' => [['message' => ['content' => json_encode([
                'queries' => ['main' => [
                    'from' => 'crm_clients',
                    'alias' => 'c',
                    'select' => [['expr' => 'literal', 'value' => 'Клиентов', 'as' => 'name']],
                    'limit' => 1,
                ]],
                'shape' => 'counters',
                'presentation' => [],
            ], JSON_UNESCAPED_UNICODE)]]],
        ])->push([
            'choices' => [['message' => ['content' => json_encode([
                'queries' => ['main' => [
                    'from' => 'crm_clients',
                    'alias' => 'c',
                    'select' => [
                        ['expr' => 'literal', 'value' => 'Клиентов', 'as' => 'name'],
                        ['expr' => 'count', 'as' => 'value'],
                    ],
                    'limit' => 1,
                ]],
                'shape' => 'counters',
                'presentation' => [],
            ], JSON_UNESCAPED_UNICODE)]]],
        ]);

        $schema = (new SchemaInspector(DB::connection()))->schema(['crm_clients']);
        $result = (new WidgetSpecGenerator(
            app(AiClient::class),
            'sqlite',
            $schema,
            $this->company->id,
            $schema,
        ))->generate('Считать клиентов', 'mini-counters', null);

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame(2, $result['attempts']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains(
            (string) ($request->data()['messages'][1]['content'] ?? ''),
            'ПРЕДЫДУЩАЯ ПОПЫТКА НЕ СРАБОТАЛА',
        ));
    }

    public function test_failed_regeneration_preserves_existing_sql_widgets(): void
    {
        config()->set('dashboard_gen.max_attempts', 1);

        CrmClient::create([
            'company_id' => $this->company->id,
            'name' => 'Клиент',
            'kind' => 'company',
            'status' => 'active',
        ]);

        $dashboard = BiDashboard::create([
            'company_id' => $this->company->id,
            'name' => 'Повторная генерация',
            'created_by' => $this->user->id,
            'origin' => 'ai',
            'status' => 'generating',
            'instruction' => 'Показать клиентов',
        ]);

        $oldSpec = [
            'queries' => ['main' => 'SELECT name AS label, COUNT(*) AS value FROM crm_clients '
                .'WHERE company_id = :company_id GROUP BY name'],
            'shape' => WidgetShapeMapper::SHAPE_SERIES_VALUES,
            'presentation' => [],
        ];
        $old = $dashboard->widgets()->create([
            'title' => 'Старый рабочий виджет',
            'kind' => 'kpi',
            'position' => 0,
            'widget_family' => 'pie',
            'content_mode' => 'sql',
            'status' => 'ready',
            'query_spec' => $oldSpec,
        ]);

        Http::fakeSequence()->push([
            'choices' => [['message' => ['content' => json_encode([
                'dashboard_name' => 'Новая попытка',
                'widgets' => [[
                    'name' => 'mini-counters',
                    'title' => 'Сломанный счётчик',
                    'instruction' => 'Посчитать клиентов',
                    'tables' => ['crm_clients'],
                ]],
            ], JSON_UNESCAPED_UNICODE)]]],
        ])->push([
            'choices' => [['message' => ['content' => json_encode([
                'queries' => ['main' => [
                    'from' => 'crm_clients',
                    'alias' => 'c',
                    'select' => [['expr' => 'literal', 'value' => 'Клиентов', 'as' => 'name']],
                    'limit' => 1,
                ]],
                'shape' => 'counters',
                'presentation' => [],
            ], JSON_UNESCAPED_UNICODE)]]],
        ]);

        GenerateAiDashboard::dispatch(
            $dashboard->id,
            $this->company->id,
            $this->user->id,
            'Показать клиентов',
        );

        $dashboard->refresh();

        $this->assertSame('error', $dashboard->status);
        $this->assertSame([$old->id], $dashboard->widgets()->pluck('id')->all());
        $this->assertSame($oldSpec, $dashboard->widgets()->firstOrFail()->query_spec);
        $this->assertSame('ready', $dashboard->widgets()->firstOrFail()->status);
    }

    public function test_generate_route_is_single_flight_per_company(): void
    {
        Queue::fake();

        $lock = Cache::lock('bi-generate:company:'.$this->company->id, 15);
        $this->assertTrue($lock->get());

        try {
            $this->from(route('bi.index'))
                ->post(route('bi.dashboards.generate'), ['instruction' => 'Доходы по месяцам'])
                ->assertRedirect(route('bi.index'))
                ->assertSessionHas('error');

            $this->assertSame(0, BiDashboard::query()->where('origin', 'ai')->count());
            Queue::assertNotPushed(GenerateAiDashboard::class);
        } finally {
            $lock->release();
        }
    }

    public function test_generation_status_does_not_expose_sql_or_bindings(): void
    {
        $dashboard = BiDashboard::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Статус без SQL',
            'is_default' => false,
            'created_by' => $this->user->id,
            'origin' => 'ai',
            'status' => 'generating',
            'instruction' => 'Доходы по месяцам',
        ]);

        $widget = BiWidget::query()->create([
            'dashboard_id' => $dashboard->id,
            'title' => 'Виджет',
            'kind' => 'kpi',
            'position' => 0,
            'widget_family' => 'pie',
            'content_mode' => 'sql',
            'status' => 'ready',
            'query_spec' => [
                'queries' => ['main' => 'SELECT secret_marker_xyz AS label, COUNT(*) AS value '
                    .'FROM crm_clients WHERE company_id = :company_id GROUP BY label'],
                'bindings' => ['p1' => 'secret_binding_marker'],
                'shape' => WidgetShapeMapper::SHAPE_SERIES_VALUES,
                'presentation' => [],
            ],
        ]);

        $this->getJson(route('bi.dashboards.generation-status', $dashboard))
            ->assertOk()
            ->assertJsonPath('widgets.0', [
                'id' => $widget->id,
                'title' => 'Виджет',
                'status' => 'ready',
                'ready' => true,
            ])
            ->assertDontSee('secret_marker_xyz')
            ->assertDontSee('secret_binding_marker');
    }

    public function test_filter_recompile_keeps_tenant_predicate_and_applies_new_filters(): void
    {
        $other = Company::create(['name' => 'Other Co', 'code' => 'otherco']);

        foreach ([
            [$this->company->id, 'Наш архивный', 'archived'],
            [$this->company->id, 'Наш активный', 'active'],
            [$other->id, 'Чужой архивный', 'archived'],
        ] as [$companyId, $name, $status]) {
            CrmClient::create([
                'company_id' => $companyId,
                'name' => $name,
                'kind' => 'company',
                'status' => $status,
            ]);
        }

        $structured = [
            'queries' => [
                'main' => [
                    'from' => 'crm_clients',
                    'alias' => 'c',
                    'select' => [
                        ['expr' => 'column', 'column' => 'c.name', 'as' => 'label'],
                        ['expr' => 'count', 'as' => 'value'],
                    ],
                    'limit' => 10,
                ],
            ],
            'shape' => WidgetShapeMapper::SHAPE_SERIES_VALUES,
            'presentation' => [],
        ];

        $compiler = WidgetSpecCompiler::forCurrentConnection();
        $compiled = $compiler->compile($structured);
        $filtered = $compiler->apply($compiled, [
            ['column' => 'c.status', 'op' => '=', 'value' => 'archived'],
        ]);

        $sql = (string) ($filtered['queries']['main'] ?? '');
        $this->assertStringContainsString('"c"."company_id" = :company_id', $sql);
        $this->assertStringContainsString('"c"."status" = :p1', $sql);
        $this->assertSame('archived', $filtered['bindings']['p1'] ?? null);
        $this->assertSame($structured['queries'], $filtered['source']);

        $result = (new WidgetQueryRunner(companyId: $this->company->id))->run($filtered, 'pie');
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame(['Наш архивный'], $result['data']['labels']);
    }

    public function test_show_renders_generation_progress_instead_of_empty_state(): void
    {
        $dashboard = BiDashboard::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Генерация дашборда',
            'is_default' => false,
            'created_by' => $this->user->id,
            'origin' => 'ai',
            'status' => 'generating',
            'instruction' => 'Доходы по месяцам',
        ]);

        $this->get(route('bi.dashboards.show', $dashboard))
            ->assertOk()
            ->assertSee('data-bm-progress-list', false)
            ->assertSee('data-bm-progress-live', false)
            ->assertSee('Bumi ещё не составил план виджетов…', false)
            ->assertDontSee('Виджетов нет', false)
            ->assertDontSee('data-bm-status', false)
            ->assertDontSee('значения считаются из витрины метрик', false)
            ->assertSee('Создать дашборд', false)
            ->assertDontSee('Сгенерировать ИИ', false);
    }

    public function test_show_does_not_load_lms_legal_or_realtime_assets(): void
    {
        $dashboard = BiDashboard::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Чистая страница BI',
            'is_default' => false,
            'created_by' => $this->user->id,
            'origin' => 'ai',
            'status' => 'ready',
        ]);

        $this->get(route('bi.dashboards.show', $dashboard))
            ->assertOk()
            ->assertDontSee('/assets/js/lms.js', false)
            ->assertDontSee('/assets/js/lms-whiteboard.js', false)
            ->assertDontSee('/assets/js/lms-meeting.js', false)
            ->assertDontSee('/assets/js/lms-pulse.js', false)
            ->assertDontSee('/assets/css/lms.css', false)
            ->assertDontSee('/assets/css/legal.css', false)
            ->assertDontSee('/assets/vendor/echo/pusher.min.js', false)
            ->assertDontSee('/assets/vendor/echo/echo.iife.js', false)
            ->assertDontSee('window.LMS_I18N', false)
            ->assertSee('/assets/js/ai-panel.js', false);
    }

    public function test_show_marks_empty_ai_widget_and_its_last_run(): void
    {
        $dashboard = BiDashboard::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Пустые данные',
            'is_default' => false,
            'created_by' => $this->user->id,
            'origin' => 'ai',
            'status' => 'ready',
        ]);

        $spec = WidgetSpecCompiler::forCurrentConnection()->compile([
            'queries' => [
                'main' => [
                    'from' => 'crm_clients',
                    'alias' => 'c',
                    'select' => [
                        ['expr' => 'column', 'column' => 'c.name', 'as' => 'label'],
                        ['expr' => 'count', 'as' => 'value'],
                    ],
                    'limit' => 10,
                ],
            ],
            'shape' => WidgetShapeMapper::SHAPE_SERIES_VALUES,
            'presentation' => [],
        ]);

        $dashboard->widgets()->create([
            'title' => 'Клиенты по месяцам',
            'kind' => 'kpi',
            'position' => 0,
            'widget_family' => 'pie',
            'content_mode' => 'sql',
            'status' => 'ready',
            'span' => 4,
            'query_spec' => $spec,
            'last_run_at' => now(),
        ]);

        $this->get(route('bi.dashboards.show', $dashboard))
            ->assertOk()
            ->assertSee('нет данных', false)
            ->assertSee('обновлено', false);
    }

    public function test_widget_update_changes_layout_without_touching_sql(): void
    {
        $dashboard = BiDashboard::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Редактор виджета',
            'is_default' => false,
            'created_by' => $this->user->id,
            'origin' => 'ai',
            'status' => 'ready',
        ]);

        $spec = [
            'queries' => ['main' => 'SELECT name AS label, COUNT(*) AS value FROM crm_clients '
                .'WHERE company_id = :company_id GROUP BY name'],
            'shape' => WidgetShapeMapper::SHAPE_SERIES_VALUES,
            'presentation' => [],
        ];

        $widget = $dashboard->widgets()->create([
            'title' => 'Старый заголовок',
            'kind' => 'kpi',
            'position' => 0,
            'widget_family' => 'pie',
            'widget_type' => 'donut',
            'content_mode' => 'sql',
            'status' => 'ready',
            'span' => 4,
            'query_spec' => $spec,
        ]);

        $response = $this->put(route('bi.widgets.update', [$dashboard, $widget]), [
            'title' => 'Новый заголовок',
            'widget_family' => 'table',
            'widget_type' => 'compact',
            'span' => 12,
            'presentation' => '{"compact":true}',
        ])->assertRedirect();
        $response->assertSessionHas('status');

        // Успешное действие должно быть видно пользователю, а не только в сессии.
        $this->get(route('bi.dashboards.show', $dashboard))
            ->assertSee('Виджет «Новый заголовок» обновлён', false)
            ->assertSee('data-test="flash-status"', false);

        $widget->refresh();

        $this->assertSame('Новый заголовок', $widget->title);
        $this->assertSame('table', $widget->widget_family);
        $this->assertSame('compact', $widget->widget_type);
        $this->assertSame(12, $widget->span);
        $this->assertSame($spec['queries'], $widget->query_spec['queries']);
        $this->assertSame(['compact' => true], $widget->query_spec['presentation']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'bi.widget_updated',
            'company_id' => $this->company->id,
        ]);

        $this->get(route('bi.dashboards.show', $dashboard))
            ->assertOk()
            ->assertSee('col-xl-12', false)
            ->assertSee('Новый заголовок', false);
    }
}
