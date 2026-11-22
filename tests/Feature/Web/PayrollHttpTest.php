<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\EmployeeSalary;
use App\Models\LeaveRequest;
use App\Models\PayrollLine;
use App\Models\PayrollPayment;
use App\Models\PayrollPeriod;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Models\WorkflowTransition;
use App\Support\SingleFlightLock;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Этап A — Payroll Core: гейтинг прав, создание периода, детерминированный
 * расчёт из табеля, статусная машина, scope отдела, CSV-экспорт.
 */
class PayrollHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Payroll Co', 'code' => 'payrollco']);
        $this->adminUser = User::factory()->create([
            'email' => 'admin@corpms.demo',
            'password' => 'password',
        ]);
        $this->adminEmployee = $this->makeEmployee('A-1', 'Админ', 'Админов');
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);
        $this->adminEmployee->update(['user_id' => $this->adminUser->id]);
        $this->adminEmployee->refresh();

        $this->actingAs($this->adminUser);
    }

    protected function makeEmployee(
        string $no,
        string $first,
        string $last,
        ?string $roleSlug = null,
        ?int $departmentId = null,
    ): Employee {
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'department_id' => $departmentId,
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

    /** Активные компоненты компании: оклад, сверхурочные, аванс, налог. */
    protected function seedComponents(): void
    {
        SalaryComponent::create(['company_id' => $this->company->id, 'code' => 'SALARY', 'name' => 'Оклад', 'kind' => 'earning', 'calculation' => 'fixed', 'rate' => 0]);
        SalaryComponent::create(['company_id' => $this->company->id, 'code' => 'OVERTIME', 'name' => 'Сверхурочные', 'kind' => 'earning', 'calculation' => 'overtime', 'rate' => 15]);
        SalaryComponent::create(['company_id' => $this->company->id, 'code' => 'ADVANCE', 'name' => 'Аванс', 'kind' => 'deduction', 'calculation' => 'fixed', 'rate' => 1000]);
        SalaryComponent::create(['company_id' => $this->company->id, 'code' => 'TAX', 'name' => 'Налог', 'kind' => 'deduction', 'calculation' => 'percent', 'rate' => 10]);
    }

    /** Табель за октябрь 2026: 168 отработанных часов + 8 часов переработки. */
    protected function seedTimesheet(Employee $employee): void
    {
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'date' => '2026-10-05',
            'worked_minutes' => 5040,
            'overtime_minutes' => 240,
            'scheduled_minutes' => 5040,
            'status' => 'present',
            'source' => 'manual',
        ]);
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'date' => '2026-10-06',
            'worked_minutes' => 5040,
            'overtime_minutes' => 240,
            'scheduled_minutes' => 5040,
            'status' => 'overtime',
            'source' => 'manual',
        ]);
    }

    protected function makePeriod(string $status = 'draft'): PayrollPeriod
    {
        return PayrollPeriod::create([
            'company_id' => $this->company->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'status' => $status,
        ]);
    }

    /** Полный расчётный фикстурный набор: сотрудник + оклад + табель + компоненты. */
    protected function fullFixture(): PayrollPeriod
    {
        $employee = $this->makeEmployee('E-1', 'Иван', 'Сидоров');
        EmployeeSalary::create([
            'employee_id' => $employee->id,
            'base_amount' => 5000,
            'currency' => 'TJS',
            'effective_from' => '2026-01-01',
        ]);
        $this->seedTimesheet($employee);
        $this->seedComponents();

        return $this->makePeriod();
    }

    /** Гейтинг: без payroll.* — 403; super_admin — 200 и пункт в сайдбаре. */
    public function test_permission_gating(): void
    {
        $worker = $this->makeEmployee('W-1', 'Работник', 'Работников', 'employee');
        $workerUser = User::findOrFail($worker->user_id);

        $this->actingAs($workerUser)
            ->get('/payroll')
            ->assertForbidden();

        $this->actingAs($workerUser)
            ->post('/payroll', ['month' => '2026-10'])
            ->assertForbidden();

        $this->actingAs($workerUser)
            ->get('/payroll/salaries')
            ->assertForbidden();

        $this->actingAs($this->adminUser)
            ->get('/payroll')
            ->assertOk()
            ->assertSee('Расчётные ведомости');

        $this->actingAs($this->adminUser)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Зарплата')
            ->assertSee('Ведомости');
    }

    /** Создание периода на месяц + защита от дублей. */
    public function test_period_create_and_duplicate_blocked(): void
    {
        $this->post('/payroll', ['month' => '2026-10'])
            ->assertRedirect();

        $this->assertDatabaseCount('payroll_periods', 1);
        $period = PayrollPeriod::query()->firstOrFail();
        $this->assertSame('draft', $period->status->value);
        $this->assertSame('2026-10-01', $period->period_start->toDateString());
        $this->assertSame('2026-10-31', $period->period_end->toDateString());

        $this->post('/payroll', ['month' => '2026-10'])
            ->assertSessionHasErrors('month');

        $this->assertDatabaseCount('payroll_periods', 1);
    }

    /** H-BL-01: пока другой запрос держит лок компании, ответ — 422, а не 500/дубль. */
    public function test_period_create_respects_single_flight_lock(): void
    {
        $lockKey = 'payroll:period:'.$this->company->id;
        $token = SingleFlightLock::acquire($lockKey);
        self::assertNotNull($token);

        try {
            $this->post('/payroll', ['month' => '2026-10'])->assertSessionHasErrors('month');
        } finally {
            SingleFlightLock::release($lockKey, $token);
        }

        $this->assertDatabaseCount('payroll_periods', 0);

        $this->post('/payroll', ['month' => '2026-10'])->assertRedirect();
        $this->assertDatabaseCount('payroll_periods', 1);
    }

    /** Детерминированный расчёт: 5000 оклад + 8ч×15 сверхурочных − 1000 аванс − 10% налог. */
    public function test_calculate_from_timesheet_is_deterministic(): void
    {
        $period = $this->fullFixture();

        $this->post('/payroll/periods/'.$period->id.'/calculate')
            ->assertRedirect();

        $period->refresh();
        $this->assertSame('calculated', $period->status->value);
        $this->assertSame(4, PayrollLine::query()->where('period_id', $period->id)->count());
        $this->assertEquals(5120.0, (float) $period->gross_amount);   // 5000 + 8*15
        $this->assertEquals(1500.0, (float) $period->deduction_amount); // 1000 + 5000*10%
        $this->assertEquals(3620.0, (float) $period->net_amount);
        $this->assertSame(1, $period->employees_count);
        $this->assertNotNull($period->calculated_at);
        $this->assertSame($this->adminUser->id, $period->calculated_by);

        // пересчёт идемпотентен: старые auto-строки заменяются, суммы те же
        $this->post('/payroll/periods/'.$period->id.'/calculate')->assertRedirect();
        $this->assertSame(4, PayrollLine::query()->where('period_id', $period->id)->count());
        $this->assertEquals(3620.0, (float) $period->refresh()->net_amount);
    }

    /** Оплачиваемый отпуск идёт в часы hourly-компонента: отпуск ≠ прогул и ≠ 0. */
    public function test_paid_leave_adds_hours_to_hourly_component(): void
    {
        $employee = $this->makeEmployee('E-2', 'Пётр', 'Отпускников');
        EmployeeSalary::create([
            'employee_id' => $employee->id,
            'base_amount' => 5000,
            'currency' => 'TJS',
            'effective_from' => '2026-01-01',
        ]);

        // почасовой компонент без табеля: часов 0 → строка не создаётся
        SalaryComponent::create([
            'company_id' => $this->company->id,
            'code' => 'HOURLY',
            'name' => 'Почасовая оплата',
            'kind' => 'earning',
            'calculation' => 'hourly',
            'rate' => 10,
        ]);

        // одобренный отпуск 12–16 октября 2026 (пн–пт, 5 рабочих дней) × 8 ч = 40 ч
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'type' => 'vacation',
            'from_date' => '2026-10-12',
            'to_date' => '2026-10-16',
            'status' => 'approved',
        ]);

        $period = $this->makePeriod();
        $this->post('/payroll/periods/'.$period->id.'/calculate')->assertRedirect();

        $line = PayrollLine::query()
            ->where('period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->first();

        $this->assertNotNull($line, 'Должна создаться строка hourly за счёт отпускных часов');
        $this->assertEquals(40.0, (float) $line->quantity); // 5 дней × 8 ч
        $this->assertEquals(400.0, (float) $line->amount);  // 40 ч × 10
    }

    /** Неоплачиваемые типы (day_off/other) и неодобренные заявки не дают часов. */
    public function test_unpaid_or_unapproved_leave_adds_nothing(): void
    {
        $employee = $this->makeEmployee('E-3', 'Олег', 'НеОтпускник');
        EmployeeSalary::create([
            'employee_id' => $employee->id,
            'base_amount' => 5000,
            'currency' => 'TJS',
            'effective_from' => '2026-01-01',
        ]);
        SalaryComponent::create([
            'company_id' => $this->company->id,
            'code' => 'HOURLY',
            'name' => 'Почасовая оплата',
            'kind' => 'earning',
            'calculation' => 'hourly',
            'rate' => 10,
        ]);

        // day_off — не оплачивается
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'type' => 'day_off',
            'from_date' => '2026-10-12',
            'to_date' => '2026-10-16',
            'status' => 'approved',
        ]);
        // vacation, но отклонена — не оплачивается
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'type' => 'vacation',
            'from_date' => '2026-10-19',
            'to_date' => '2026-10-23',
            'status' => 'rejected',
        ]);

        $period = $this->makePeriod();
        $this->post('/payroll/periods/'.$period->id.'/calculate')->assertRedirect();

        $this->assertSame(
            0,
            PayrollLine::query()->where('period_id', $period->id)->where('employee_id', $employee->id)->count(),
            'Неоплачиваемый/неодобренный отпуск не должен давать часы'
        );
    }

    /** Расчёт без данных — понятная ошибка, ведомость не меняется. */
    public function test_calculate_without_data_fails(): void
    {
        $period = $this->makePeriod(); // без окладов/компонентов

        $this->post('/payroll/periods/'.$period->id.'/calculate')
            ->assertSessionHasErrors('field');

        $this->assertSame('draft', $period->refresh()->status->value);
        $this->assertSame(0, PayrollLine::query()->count());
    }

    /** Статусная машина: calculate → approve → pay, назад — никогда. */
    public function test_state_machine_transitions(): void
    {
        $period = $this->fullFixture();

        // согласовать черновик нельзя
        $this->post('/payroll/periods/'.$period->id.'/approve')
            ->assertSessionHasErrors('field');
        $this->assertSame('draft', $period->refresh()->status->value);

        // рассчитать → согласовать → выплатить
        $this->post('/payroll/periods/'.$period->id.'/calculate')->assertRedirect();
        $this->post('/payroll/periods/'.$period->id.'/approve')->assertRedirect();
        $this->assertSame('approved', $period->refresh()->status->value);
        $this->assertNotNull($period->approved_at);

        $this->post('/payroll/periods/'.$period->id.'/pay')->assertRedirect();
        $this->assertSame('paid', $period->refresh()->status->value);
        $this->assertNotNull($period->paid_at);

        // финальный статус: пересчёт и повторная выплата заблокированы
        $this->post('/payroll/periods/'.$period->id.'/calculate')
            ->assertSessionHasErrors('field');
        $this->post('/payroll/periods/'.$period->id.'/pay')
            ->assertSessionHasErrors('field');
        $this->assertSame('paid', $period->refresh()->status->value);
    }

    /** BL-10: calculate/approve/pay пишут переходы в единый WorkflowEngine. */
    public function test_payroll_lifecycle_is_journaled_in_workflow_engine(): void
    {
        $period = $this->fullFixture();

        $this->post('/payroll/periods/'.$period->id.'/calculate')->assertRedirect();
        $this->post('/payroll/periods/'.$period->id.'/approve')->assertRedirect();
        $this->post('/payroll/periods/'.$period->id.'/pay')->assertRedirect();

        $transitions = WorkflowTransition::query()
            ->where('subject_type', 'payroll_period')
            ->where('subject_id', $period->id)
            ->orderBy('id')
            ->get(['from_status', 'to_status', 'action', 'actor_user_id'])
            ->map(fn ($t) => [
                'from_status' => $t->from_status,
                'to_status' => $t->to_status,
                'action' => $t->action,
                'actor_user_id' => (int) $t->actor_user_id,
            ])->all();

        $this->assertSame([
            ['from_status' => 'draft', 'to_status' => 'calculated', 'action' => 'calculate', 'actor_user_id' => $this->adminUser->id],
            ['from_status' => 'calculated', 'to_status' => 'approved', 'action' => 'approve', 'actor_user_id' => $this->adminUser->id],
            ['from_status' => 'approved', 'to_status' => 'paid', 'action' => 'pay', 'actor_user_id' => $this->adminUser->id],
        ], $transitions);
    }

    /** BL-12: активный manual-компонент не становится авто-начислением для всех. */
    public function test_active_manual_component_is_not_auto_accrued(): void
    {
        $period = $this->fullFixture();
        $manual = SalaryComponent::create([
            'company_id' => $this->company->id,
            'code' => 'MANUAL_BONUS',
            'name' => 'Ручная премия',
            'kind' => 'earning',
            'calculation' => 'manual',
            'rate' => 9999,
            'is_active' => true,
        ]);

        $this->post('/payroll/periods/'.$period->id.'/calculate')->assertRedirect();

        $this->assertSame(0, PayrollLine::query()->where('component_id', $manual->id)->count());
        $this->assertSame(4, PayrollLine::query()->where('period_id', $period->id)->count());
    }

    /** Экспорт CSV: UTF-8 BOM, разделитель «;», итоги в конце. */
    public function test_csv_export_has_bom_and_totals(): void
    {
        $period = $this->fullFixture();
        $this->post('/payroll/periods/'.$period->id.'/calculate')->assertRedirect();

        $response = $this->get('/payroll/periods/'.$period->id.'/export');
        $response->assertOk();

        $content = $response->streamedContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $content);
        self::assertStringContainsString('Таб.№;Сотрудник;Компонент', $content);
        self::assertStringContainsString('Сидоров Иван', $content);
        self::assertStringContainsString('ИТОГО начислено', $content);
        self::assertStringContainsString('5120.00', $content);
        self::assertStringContainsString('3620.00', $content);
    }

    /** Scope отдела: директор видит только строки своего отдела. */
    public function test_department_scope_filters_lines(): void
    {
        $deptA = Department::create(['company_id' => $this->company->id, 'name' => 'Отдел А', 'code' => 'depta']);
        $deptB = Department::create(['company_id' => $this->company->id, 'name' => 'Отдел Б', 'code' => 'deptb']);

        $director = $this->makeEmployee('D-1', 'Директор', 'Отдела', 'department_director', $deptA->id);
        $workerA = $this->makeEmployee('E-2', 'Пётр', 'Сидоров', null, $deptA->id);
        $workerB = $this->makeEmployee('E-3', 'Павел', 'Петров', null, $deptB->id);

        foreach ([$workerA, $workerB] as $worker) {
            EmployeeSalary::create([
                'employee_id' => $worker->id,
                'base_amount' => 4000,
                'currency' => 'TJS',
                'effective_from' => '2026-01-01',
            ]);
            $this->seedTimesheet($worker);
        }
        $this->seedComponents();
        $period = $this->makePeriod();
        $this->post('/payroll/periods/'.$period->id.'/calculate')->assertRedirect();

        $directorUser = User::findOrFail($director->user_id);

        // индекс доступен, суммы компании скрыты
        $this->actingAs($directorUser)
            ->get('/payroll')
            ->assertOk()
            ->assertSee('Расчётные ведомости')
            ->assertDontSee('5 120,00');

        // в ведомости — только сотрудник своего отдела
        $this->actingAs($directorUser)
            ->get('/payroll/periods/'.$period->id)
            ->assertOk()
            ->assertSee('Сидоров')
            ->assertDontSee('Петров');
    }

    /** Разделение обязанностей: HR считает, директор согласует. */
    public function test_manage_and_approve_buttons_split(): void
    {
        $period = $this->fullFixture();
        $this->post('/payroll/periods/'.$period->id.'/calculate')->assertRedirect();

        $hr = $this->makeEmployee('H-1', 'HR', 'Менеджер', 'hr_manager');
        $this->actingAs(User::findOrFail($hr->user_id))
            ->get('/payroll/periods/'.$period->id)
            ->assertOk()
            ->assertSee('Пересчитать')
            ->assertDontSee('Согласовать');

        $director = $this->makeEmployee('C-1', 'Директор', 'Компании', 'company_director');
        $this->actingAs(User::findOrFail($director->user_id))
            ->get('/payroll/periods/'.$period->id)
            ->assertOk()
            ->assertSee('Согласовать')
            ->assertDontSee('Пересчитать');

        // у HR нет payroll.approve — переход заблокирован на маршруте
        $this->actingAs(User::findOrFail($hr->user_id))
            ->post('/payroll/periods/'.$period->id.'/approve')
            ->assertForbidden();
        $this->assertSame('calculated', $period->refresh()->status->value);
    }

    /** Оклады: назначение, закрытие предыдущего оклада, страница справочников. */
    public function test_salaries_and_components_management(): void
    {
        $employee = $this->makeEmployee('E-5', 'Оклад', 'Тестов');

        $this->get('/payroll/salaries')
            ->assertOk()
            ->assertSee('Оклады и компоненты')
            ->assertSee('Общие правила расчёта');

        $this->post('/payroll/salaries', [
            'employee_id' => $employee->id,
            'base_amount' => 5000,
            'currency' => 'TJS',
            'effective_from' => '2026-01-01',
        ])->assertRedirect();
        $this->assertDatabaseCount('employee_salaries', 1);

        // новый оклад закрывает предыдущий днём раньше
        $this->post('/payroll/salaries', [
            'employee_id' => $employee->id,
            'base_amount' => 6000,
            'currency' => 'TJS',
            'effective_from' => '2026-09-01',
        ])->assertRedirect();
        $this->assertDatabaseCount('employee_salaries', 2);
        $previous = EmployeeSalary::query()->where('employee_id', $employee->id)->orderBy('effective_from')->first();
        $this->assertSame('2026-08-31', $previous->effective_to?->toDateString());

        $this->post('/payroll/components', [
            'code' => 'BONUS',
            'name' => 'Премия',
            'kind' => 'earning',
            'calculation' => 'manual',
            'rate' => 500,
        ])->assertRedirect();
        $this->assertDatabaseCount('salary_components', 1);

        // дубль кода — валидация
        $this->post('/payroll/components', [
            'code' => 'BONUS',
            'name' => 'Премия 2',
            'kind' => 'earning',
            'calculation' => 'manual',
            'rate' => 500,
        ])->assertSessionHasErrors('code');
        $this->assertDatabaseCount('salary_components', 1);
    }

    public function test_pay_creates_immutable_payment_ledger_per_employee(): void
    {
        $period = $this->fullFixture();
        $this->post('/payroll/periods/'.$period->id.'/calculate')->assertRedirect();
        $this->post('/payroll/periods/'.$period->id.'/approve')->assertRedirect();
        $this->post('/payroll/periods/'.$period->id.'/pay')->assertRedirect();

        $this->assertSame('paid', $period->fresh()->status->value);
        $payments = PayrollPayment::query()->where('period_id', $period->id)->get();
        $this->assertCount(1, $payments);
        $this->assertEquals(3620.0, (float) $payments->sole()->amount);
        $this->assertMatchesRegularExpression('/^PAY-\d+-[A-Z0-9]{10}$/', (string) $payments->sole()->reference);

        $audit = AuditLog::query()->where('action', 'payroll.paid')->sole();
        $this->assertSame($payments->sole()->reference, $audit->new_value['reference']);
        $this->assertSame(1, $audit->new_value['payments']);
    }

    public function test_pay_rejects_zero_amount_without_financial_records(): void
    {
        $period = $this->makePeriod();
        $period->forceFill(['status' => 'approved', 'net_amount' => 0])->save();

        $this->post('/payroll/periods/'.$period->id.'/pay')->assertSessionHasErrors('field');

        $this->assertSame('approved', $period->fresh()->status->value);
        $this->assertSame(0, PayrollPayment::query()->where('period_id', $period->id)->count());
    }

    public function test_calculate_warns_about_employees_without_salary(): void
    {
        $period = $this->fullFixture();
        $noSalary = $this->makeEmployee('E-9', 'Без', 'Оклада');

        $response = $this->post('/payroll/periods/'.$period->id.'/calculate')->assertRedirect();

        $status = (string) $response->getSession()->get('status');
        $this->assertStringContainsString('Оклада Без', $status);
        $this->assertStringContainsString('пропущены', $status);

        $audit = AuditLog::query()->where('action', 'payroll.calculated')->sole();
        $this->assertNotEmpty($audit->new_value['skipped_employees']);
        $this->assertStringContainsString('E-9', implode(' ', $audit->new_value['skipped_employees']));
        $this->assertNotContains($noSalary->id, $period->lines()->pluck('employee_id')->all());
    }

    public function test_salary_periods_never_overlap_including_backdate_and_same_day(): void
    {
        $employee = $this->makeEmployee('S-1', 'Оклад', 'Тест');
        $set = static fn (string $from, int $amount) => [
            'employee_id' => $employee->id,
            'base_amount' => $amount,
            'currency' => 'TJS',
            'effective_from' => $from,
        ];

        $this->post('/payroll/salaries', $set('2026-01-01', 1000))->assertRedirect();
        $this->post('/payroll/salaries', $set('2026-06-01', 2000))->assertRedirect();
        $this->post('/payroll/salaries', $set('2026-03-01', 1500))->assertRedirect();
        // Повтор на ту же дату перезаписывает сумму, а не создаёт строку.
        $this->post('/payroll/salaries', $set('2026-03-01', 1700))->assertRedirect();

        $rows = EmployeeSalary::query()->where('employee_id', $employee->id)->orderBy('effective_from')->get();
        $this->assertCount(3, $rows);
        foreach ($rows->values() as $index => $row) {
            $next = $rows->get($index + 1);
            if ($next !== null) {
                $this->assertTrue(
                    $row->effective_to !== null && $row->effective_to->lt($next->effective_from),
                    'Периоды оклада не должны пересекаться',
                );
            }
        }
        $this->assertNull($rows->last()->effective_to);
        $this->assertSame(1700.0, (float) $rows->get(1)->base_amount);
        $this->assertSame(1, DB::table('employee_salaries')
            ->where('employee_id', $employee->id)
            ->whereNull('effective_to')
            ->count());
    }
}
