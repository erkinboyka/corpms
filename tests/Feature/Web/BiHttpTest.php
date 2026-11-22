<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Bi\Services\BiService;
use App\Models\BiDashboard;
use App\Models\BiMetric;
use App\Models\BiWidget;
use App\Models\Company;
use App\Models\CrmClient;
use App\Models\CrmDeal;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\FinAccount;
use App\Models\FinTransaction;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\Sidebar;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Этап E6 (BI): гейтинг прав, сборка витрины из источников, срезы, дашборды
 * с KPI/трендами, прогноз и межтенантная изоляция.
 */
class BiHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected FinAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'BI Co', 'code' => 'bico']);
        $this->adminUser = User::factory()->create(['email' => 'bi-admin@corpms.demo']);
        $this->adminEmployee = $this->makeEmployee($this->company, 'B-1', 'Админ', 'Админов');
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);
        $this->adminEmployee->update(['user_id' => $this->adminUser->id]);
        $this->adminEmployee->refresh();

        $this->account = FinAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Расчётный счёт',
            'kind' => 'bank',
            'opening_balance' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser);
    }

    protected function makeEmployee(Company $company, string $no, string $first, string $last, ?string $roleSlug = null): Employee
    {
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'hired_at' => '2025-01-01',
            'status' => 'active',
        ]);

        if ($roleSlug !== null) {
            EmployeeRole::create([
                'employee_id' => $employee->id,
                'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            ]);
        }

        return $employee;
    }

    /** Заполнение источников: доходы/расходы, выигранная сделка, завершённая задача. */
    protected function seedSources(): void
    {
        FinTransaction::create([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'direction' => 'income',
            'amount' => 1000,
            'occurred_at' => now()->toDateString(),
        ]);
        FinTransaction::create([
            'company_id' => $this->company->id,
            'account_id' => $this->account->id,
            'direction' => 'expense',
            'amount' => 400,
            'occurred_at' => now()->toDateString(),
        ]);

        $client = CrmClient::create([
            'company_id' => $this->company->id,
            'name' => 'Клиент BI',
            'kind' => 'company',
            'status' => 'active',
        ]);

        CrmDeal::create([
            'company_id' => $this->company->id,
            'client_id' => $client->id,
            'title' => 'Выигранная сделка',
            'amount' => 5000,
            'stage' => 'won',
            'probability' => 100,
            'closed_at' => now()->toDateString(),
        ]);

        Task::create([
            'company_id' => $this->company->id,
            'title' => 'Завершённая задача',
            'number' => 'BI-T-1',
            'status' => 'completed',
            'completed_at' => now(),
            'type' => 'task',
            'priority' => 'medium',
        ]);
    }

    /** Гейтинг: без bi.* — 403; super_admin видит раздел. */
    public function test_permission_gating(): void
    {
        $worker = $this->makeEmployee($this->company, 'W-1', 'Работник', 'Работников', 'employee');
        $workerUser = User::findOrFail($worker->user_id);

        $this->actingAs($workerUser)->get('/bi')->assertForbidden();
        $this->actingAs($workerUser)->get('/bi/metrics')->assertForbidden();
        $this->actingAs($workerUser)->post('/bi/rebuild', ['month' => now()->format('Y-m')])->assertForbidden();

        $this->actingAs($this->adminUser)->get('/bi')->assertOk()->assertSee('Дашборды');
        $this->actingAs($this->adminUser)->get('/bi/metrics')->assertOk()->assertSee('Срезы витрины');

        $routes = Sidebar::routes($this->adminUser);
        $this->assertContains('bi.index', $routes);
        $this->assertContains('bi.metrics.index', $routes);

        $workerRoutes = Sidebar::routes($workerUser);
        $this->assertNotContains('bi.index', $workerRoutes);
    }

    /** Сборка витрины: метрики из финансов/CRM/задач попадают в bi_metrics. */
    public function test_rebuild_mart_from_sources(): void
    {
        $this->seedSources();

        $month = now()->format('Y-m');

        $this->post('/bi/rebuild', ['month' => $month])->assertSessionHasNoErrors();

        $today = now()->toDateString();

        $expectations = [
            'finance_income' => 1000.0,
            'finance_expense' => 400.0,
            'deals_won_count' => 1.0,
            'deals_won_amount' => 5000.0,
            'tasks_completed' => 1.0,
            'headcount' => 1.0,
        ];

        foreach ($expectations as $code => $value) {
            $metric = BiMetric::query()
                ->where('company_id', $this->company->id)
                ->where('code', $code)
                ->whereDate('metric_date', $today)
                ->first();

            $this->assertNotNull($metric, 'Нет метрики '.$code);
            $this->assertSame($value, (float) $metric->value);
        }

        $service = app(BiService::class);
        [$from, $to] = [$month.'-01', now()->endOfMonth()->toDateString()];
        $totals = $service->totals($this->company->id, $from, $to);

        $this->assertSame(1000.0, $totals['finance_income']);
        $this->assertSame(400.0, $totals['finance_expense']);
        $this->assertSame(5000.0, $totals['deals_won_amount']);
        $this->assertSame(1.0, $totals['tasks_completed']);

        // повторная сборка не дублирует строки (limit 1/мин сбрасываем между вызовами)
        RateLimiter::clear('bi.rebuild'.sha1((string) $this->adminUser->id));
        $this->post('/bi/rebuild', ['month' => $month])->assertSessionHasNoErrors();
        $this->assertSame(
            1,
            BiMetric::query()->where('company_id', $this->company->id)->where('code', 'finance_income')->count(),
        );
    }

    /** Срезы витрины: страница показывает итоги и динамику по метрикам. */
    public function test_metrics_slices_page(): void
    {
        $this->seedSources();
        $month = now()->format('Y-m');
        $this->post('/bi/rebuild', ['month' => $month]);

        $this->get('/bi/metrics?month='.$month)
            ->assertOk()
            ->assertSee('Доходы')
            ->assertSee('1 000')
            ->assertSee('Расходы')
            ->assertSee('Завершено задач');
    }

    /** Дашборд по умолчанию создаётся с виджетами; виджеты добавляются и удаляются. */
    public function test_default_dashboard_and_widgets(): void
    {
        $this->seedSources();
        $month = now()->format('Y-m');
        $this->post('/bi/rebuild', ['month' => $month]);

        $this->get('/bi?month='.$month)->assertOk()->assertSee('Обзор');

        $dashboard = BiDashboard::query()->where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();
        $this->assertSame(6, $dashboard->widgets()->count());

        $this->get('/bi/dashboards/'.$dashboard->id.'?month='.$month)
            ->assertOk()
            ->assertSee('Доходы за период')
            ->assertSee('1 000')
            ->assertSee('Тренд доходов');

        // новый виджет
        $this->post('/bi/dashboards/'.$dashboard->id.'/widgets', [
            'title' => 'Оплаты по счетам',
            'kind' => 'kpi',
            'metric_code' => 'invoices_paid',
        ])->assertSessionHasNoErrors();

        $widget = BiWidget::query()->where('title', 'Оплаты по счетам')->firstOrFail();
        $this->assertSame('invoices_paid', $widget->metric_code);

        // невалидная метрика отклоняется
        $this->post('/bi/dashboards/'.$dashboard->id.'/widgets', [
            'title' => 'Плохой виджет',
            'kind' => 'kpi',
            'metric_code' => 'unknown_metric',
        ])->assertSessionHasErrors('metric_code');

        $this->delete('/bi/dashboards/'.$dashboard->id.'/widgets/'.$widget->id)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('bi_widgets', ['id' => $widget->id]);

        // кастомный дашборд
        $this->post('/bi/dashboards', ['name' => 'Финансы'])->assertSessionHasNoErrors();
        $custom = BiDashboard::query()->where('name', 'Финансы')->firstOrFail();
        $this->get('/bi?month='.$month)->assertOk()->assertSee('Финансы');
        $this->assertSame($this->company->id, (int) $custom->company_id);
    }

    /** Прогноз: линейный тренд по месячным суммам метрики. */
    public function test_forecast_builds_linear_trend(): void
    {
        $service = app(BiService::class);
        $months = [now()->subMonths(2), now()->subMonth(), now()];

        foreach ($months as $index => $month) {
            BiMetric::create([
                'company_id' => $this->company->id,
                'metric_date' => $month->copy()->startOfMonth()->toDateString(),
                'code' => 'finance_income',
                'value' => (100 * ($index + 1)),
            ]);
        }

        $forecast = $service->forecast($this->company->id, 'finance_income');

        $this->assertCount(3, $forecast);
        $this->assertSame(now()->addMonth()->format('Y-m'), $forecast[0]['month']);
        $this->assertSame(400.0, $forecast[0]['value']);
        $this->assertSame(500.0, $forecast[1]['value']);
        $this->assertSame(600.0, $forecast[2]['value']);

        // нет данных — прогноза нет
        $this->assertSame([], $service->forecast($this->company->id, 'inventory_value'));

        // страница дашборда показывает прогноз
        $this->get('/bi')->assertOk();
        $dashboard = BiDashboard::query()->where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();

        $this->get('/bi/dashboards/'.$dashboard->id)
            ->assertOk()
            ->assertSee('Прогноз на 3 месяца');
    }

    /** Изоляция: чужой дашборд — 403, чужой виджет в своём маршруте — 404. */
    public function test_tenant_isolation(): void
    {
        $other = Company::create(['name' => 'Other BI', 'code' => 'otherbi']);
        $foreignDashboard = BiDashboard::create([
            'company_id' => $other->id,
            'name' => 'Чужой дашборд',
            'is_default' => true,
        ]);
        $foreignWidget = BiWidget::create([
            'dashboard_id' => $foreignDashboard->id,
            'title' => 'Чужой виджет',
            'kind' => 'kpi',
            'metric_code' => 'finance_income',
            'position' => 0,
        ]);

        $this->get('/bi/dashboards/'.$foreignDashboard->id)->assertForbidden();
        $this->post('/bi/dashboards/'.$foreignDashboard->id.'/widgets', [
            'title' => 'Взлом',
            'kind' => 'kpi',
            'metric_code' => 'finance_income',
        ])->assertForbidden();

        $own = BiDashboard::create([
            'company_id' => $this->company->id,
            'name' => 'Мой дашборд',
            'is_default' => false,
        ]);

        $this->delete('/bi/dashboards/'.$own->id.'/widgets/'.$foreignWidget->id)->assertNotFound();

        $this->get('/bi')->assertOk()->assertSee('Мой дашборд')->assertDontSee('Чужой дашборд');

        $this->assertSame($other->id, (int) $foreignWidget->fresh()->dashboard->company_id);
    }
}
