<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Payroll\Services\PayrollCalculationService;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollLine;
use App\Models\PayrollPeriod;
use App\Models\SalaryComponent;
use App\Shared\Enums\PayrollCalculation;
use App\Shared\Enums\PayrollComponentKind;
use App\Shared\Enums\PayrollPeriodStatus;
use App\Shared\Enums\PermissionScope;
use App\Support\EnumLabels;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Зарплата (Этап A: Payroll Core): периоды-ведомости, расчёт/согласование/выплата,
 * оклады сотрудников и компоненты начислений.
 */
class PayrollController extends Controller
{
    public function __construct(
        protected PayrollCalculationService $payroll,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /** Список ведомостей компании. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $scope = $this->permissions->can($request->user(), 'payroll.view');
        \assert($scope !== false, 'маршрут уже проверяет право');
        $showAmounts = $scope === PermissionScope::Company;

        $periods = PayrollPeriod::query()
            ->where('company_id', $me->company_id)
            ->orderByDesc('period_start')
            ->limit(60)
            ->get();

        $salaryCount = EmployeeSalary::query()
            ->whereIn('employee_id', Employee::query()->where('company_id', $me->company_id)->select('id'))
            ->count();
        $componentCount = SalaryComponent::query()->where('company_id', $me->company_id)->count();

        return view('payroll.index', [
            'periods' => $periods,
            'canManage' => $this->permissions->check($request->user(), 'payroll.manage'),
            'showAmounts' => $showAmounts,
            'totals' => [
                'periods' => $periods->count(),
                'gross' => round((float) $periods->sum('gross_amount'), 2),
                'net' => round((float) $periods->sum('net_amount'), 2),
                'salaries' => $salaryCount,
                'components' => $componentCount,
            ],
        ]);
    }

    /** Создание периода на месяц (первое число — последнее). */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['month' => 'У сотрудника не указана компания'])->withInput();
        }

        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $start = Carbon::parse($validated['month'].'-01');
        $end = $start->copy()->endOfMonth();

        $overlap = PayrollPeriod::query()
            ->where('company_id', $me->company_id)
            ->where('period_start', '<=', $end->toDateString())
            ->where('period_end', '>=', $start->toDateString())
            ->exists();

        if ($overlap) {
            return back()->withErrors(['month' => 'Ведомость за этот период уже существует'])->withInput();
        }

        $period = PayrollPeriod::query()->create([
            'company_id' => $me->company_id,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'status' => PayrollPeriodStatus::Draft,
        ]);

        $this->audit->log($request->user(), 'payroll.created', 'payroll_period', $period->id, null, [
            'period_start' => $period->period_start->toDateString(),
            'period_end' => $period->period_end->toDateString(),
        ]);

        return redirect()
            ->route('payroll.show', $period)
            ->with('status', 'Ведомость за '.$start->format('F Y').' создана');
    }

    /** Ведомость: строки по сотрудникам + детализация компонентов. */
    public function show(Request $request, PayrollPeriod $period): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($period, $me);

        $scope = $this->permissions->can($request->user(), 'payroll.view');
        \assert($scope !== false, 'маршрут уже проверяет право');

        $linesQuery = $period->lines()->with(['employee', 'component']);
        if ($scope === PermissionScope::Department) {
            $deptEmployeeIds = Employee::query()->where('department_id', $me->department_id)->select('id');
            $linesQuery->whereIn('employee_id', $deptEmployeeIds);
        }

        /** @var Collection<int, PayrollLine> $lines */
        $lines = $linesQuery->get()->sortBy([
            fn (PayrollLine $a, PayrollLine $b) => [$a->employee?->last_name, $a->employee?->first_name] <=> [$b->employee?->last_name, $b->employee?->first_name],
        ])->values();

        // Сводка по сотрудникам: начислено / удержано / к выплате
        $summary = $lines->groupBy('employee_id')->map(function (Collection $employeeLines) {
            $gross = $employeeLines
                ->filter(fn (PayrollLine $l) => $l->component->kind === PayrollComponentKind::Earning)
                ->sum('amount');
            $deductions = $employeeLines
                ->filter(fn (PayrollLine $l) => $l->component->kind === PayrollComponentKind::Deduction)
                ->sum('amount');

            return [
                'employee' => $employeeLines->first()?->employee,
                'gross' => round((float) $gross, 2),
                'deductions' => round((float) $deductions, 2),
                'net' => round((float) $gross - (float) $deductions, 2),
                'lines' => $employeeLines->count(),
            ];
        })->sortKeysDesc()->values();

        // Итоги считаются по видимым строкам: dept-scope не утекают суммы других отделов
        $earnings = $lines
            ->filter(fn (PayrollLine $l) => $l->component->kind === PayrollComponentKind::Earning)
            ->sum('amount');
        $deductionTotal = $lines
            ->filter(fn (PayrollLine $l) => $l->component->kind === PayrollComponentKind::Deduction)
            ->sum('amount');

        return view('payroll.period', [
            'period' => $period,
            'lines' => $lines,
            'summary' => $summary,
            'totals' => [
                'gross' => round((float) $earnings, 2),
                'deductions' => round((float) $deductionTotal, 2),
                'net' => round((float) $earnings - (float) $deductionTotal, 2),
                'employees' => $summary->count(),
            ],
            'canManage' => $this->permissions->check($request->user(), 'payroll.manage'),
            'canApprove' => $this->permissions->check($request->user(), 'payroll.approve'),
        ]);
    }

    /** Расчёт из табеля и окладов. */
    public function calculate(Request $request, PayrollPeriod $period): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($period, $me);

        $old = ['status' => $period->status->value];

        try {
            $this->payroll->calculate($period, $request->user());
        } catch (LogicException $e) {
            return back()->withErrors(['field' => $e->getMessage()]);
        }

        $this->audit->log($request->user(), 'payroll.calculated', 'payroll_period', $period->id, $old, [
            'status' => $period->refresh()->status->value,
            'gross' => $period->gross_amount,
            'net' => $period->net_amount,
        ]);

        return back()->with('status', 'Ведомость рассчитана: начислено '.number_format((float) $period->gross_amount, 2, ',', ' '));
    }

    /** Согласование: calculated → approved. */
    public function approve(Request $request, PayrollPeriod $period): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($period, $me);

        $old = ['status' => $period->status->value];

        try {
            $this->payroll->approve($period, $request->user());
        } catch (LogicException $e) {
            return back()->withErrors(['field' => $e->getMessage()]);
        }

        $this->audit->log($request->user(), 'payroll.approved', 'payroll_period', $period->id, $old, [
            'status' => PayrollPeriodStatus::Approved->value,
        ]);

        return back()->with('status', 'Ведомость согласована');
    }

    /** Выплата: approved → paid. */
    public function pay(Request $request, PayrollPeriod $period): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($period, $me);

        $old = ['status' => $period->status->value];

        try {
            $this->payroll->pay($period, $request->user());
        } catch (LogicException $e) {
            return back()->withErrors(['field' => $e->getMessage()]);
        }

        $this->audit->log($request->user(), 'payroll.paid', 'payroll_period', $period->id, $old, [
            'status' => PayrollPeriodStatus::Paid->value,
            'net' => $period->net_amount,
        ]);

        return back()->with('status', 'Выплата зафиксирована');
    }

    /** Экспорт ведомости CSV (UTF-8 BOM, разделитель «;» — как в табеле). */
    public function export(Request $request, PayrollPeriod $period): StreamedResponse
    {
        $scope = $this->permissions->can($request->user(), 'payroll.view');
        \assert($scope !== false, 'маршрут уже проверяет право');

        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($period, $me);

        $linesQuery = $period->lines()->with(['employee', 'component']);
        if ($scope === PermissionScope::Department) {
            $deptEmployeeIds = Employee::query()->where('department_id', $me->department_id)->select('id');
            $linesQuery->whereIn('employee_id', $deptEmployeeIds);
        }
        $lines = $linesQuery->get()->sortBy([
            fn (PayrollLine $a, PayrollLine $b) => [$a->employee?->last_name, $a->employee?->first_name] <=> [$b->employee?->last_name, $b->employee?->first_name],
        ])->values();

        $this->audit->log($request->user(), 'payroll.exported', 'payroll_period', $period->id, null, [
            'period_start' => $period->period_start->toDateString(),
        ]);

        $filename = 'payroll_'.$period->period_start->format('Y-m').'.csv';

        return response()->streamDownload(function () use ($lines, $period) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM для Excel

            fputcsv($out, ['Таб.№', 'Сотрудник', 'Компонент', 'Тип', 'Расчёт', 'Кол-во', 'Ставка', 'Сумма', 'Источник'], ';');

            foreach ($lines as $line) {
                fputcsv($out, [
                    $line->employee?->employee_no ?? '',
                    trim(($line->employee?->last_name ?? '').' '.($line->employee?->first_name ?? '')),
                    $line->component?->name ?? '',
                    EnumLabels::label('payroll_component_kind', $line->component?->kind?->value),
                    EnumLabels::label('payroll_calculation', $line->component?->calculation?->value),
                    number_format((float) $line->quantity, 2, '.', ''),
                    number_format((float) $line->rate, 2, '.', ''),
                    number_format((float) $line->amount, 2, '.', ''),
                    EnumLabels::label('payroll_line_source', $line->source->value),
                ], ';');
            }

            fputcsv($out, ['', 'ИТОГО начислено', '', '', '', '', '', number_format((float) $period->gross_amount, 2, '.', ''), ''], ';');
            fputcsv($out, ['', 'ИТОГО удержано', '', '', '', '', '', number_format((float) $period->deduction_amount, 2, '.', ''), ''], ';');
            fputcsv($out, ['', 'К выплате', '', '', '', '', '', number_format((float) $period->net_amount, 2, '.', ''), ''], ';');

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Оклады и компоненты начислений. */
    public function salaries(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $scope = $this->permissions->can($request->user(), 'payroll.view');
        \assert($scope !== false, 'маршрут уже проверяет право');

        $employeesQuery = Employee::query()
            ->where('company_id', $me->company_id)
            ->where('status', 'active');
        if ($scope === PermissionScope::Department) {
            $employeesQuery->where('department_id', $me->department_id);
        }
        $employees = $employeesQuery->orderBy('last_name')->get();

        $salaryRows = EmployeeSalary::query()
            ->with('employee')
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereNull('effective_to')
            ->orderByDesc('effective_from')
            ->get();

        $components = SalaryComponent::query()
            ->where('company_id', $me->company_id)
            ->orderBy('kind')
            ->orderBy('code')
            ->get();

        $withSalary = $salaryRows->pluck('employee_id')->unique()->count();
        $avgSalary = $withSalary > 0 ? round((float) $salaryRows->avg('base_amount'), 2) : 0;

        return view('payroll.salaries', [
            'employees' => $employees,
            'salaries' => $salaryRows,
            'components' => $components,
            'canManage' => $this->permissions->check($request->user(), 'payroll.manage'),
            'totals' => [
                'employees' => $employees->count(),
                'withSalary' => $withSalary,
                'avg' => $avgSalary,
                'components' => $components->count(),
                'active' => $components->where('is_active', true)->count(),
            ],
        ]);
    }

    /** Назначение оклада (предыдущий действующий закрывается днём раньше). */
    public function storeSalary(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'base_amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'currency' => ['required', 'string', 'size:3'],
            'effective_from' => ['required', 'date'],
        ]);

        $employee = Employee::query()->findOrFail((int) $validated['employee_id']);
        if ((int) $employee->company_id !== (int) $me->company_id) {
            abort(403, 'Сотрудник из другой компании');
        }

        DB::transaction(function () use ($validated, $employee): void {
            $previous = EmployeeSalary::query()
                ->where('employee_id', $employee->id)
                ->whereNull('effective_to')
                ->orderByDesc('effective_from')
                ->first();

            if ($previous !== null) {
                $newFrom = Carbon::parse($validated['effective_from']);
                if ($previous->effective_from->toDateString() === $newFrom->toDateString()) {
                    // перезапись оклада на ту же дату
                    $previous->update(['base_amount' => $validated['base_amount'], 'currency' => $validated['currency']]);

                    return;
                }
                if ($previous->effective_from->lt($newFrom)) {
                    $previous->update(['effective_to' => $newFrom->copy()->subDay()->toDateString()]);
                }
            }

            EmployeeSalary::query()->create([
                'employee_id' => $employee->id,
                'base_amount' => $validated['base_amount'],
                'currency' => $validated['currency'],
                'effective_from' => $validated['effective_from'],
            ]);
        });

        $this->audit->log($request->user(), 'payroll.salary.set', 'employee', $employee->id, null, [
            'base_amount' => $validated['base_amount'],
            'currency' => $validated['currency'],
            'effective_from' => $validated['effective_from'],
        ]);

        return back()->with('status', 'Оклад обновлён: '.$employee->last_name.' '.$employee->first_name);
    }

    /** Новый компонент начисления/удержания. */
    public function storeComponent(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_.-]+$/',
                Rule::unique('salary_components', 'code')->where('company_id', $me->company_id)],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::enum(PayrollComponentKind::class)],
            'calculation' => ['required', Rule::enum(PayrollCalculation::class)],
            'rate' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ]);

        $component = SalaryComponent::query()->create([
            'company_id' => $me->company_id,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'calculation' => $validated['calculation'],
            'rate' => $validated['rate'],
            'is_active' => true,
        ]);

        $this->audit->log($request->user(), 'payroll.component.created', 'salary_component', $component->id, null, [
            'code' => $component->code,
            'kind' => $component->kind->value,
            'calculation' => $component->calculation->value,
        ]);

        return back()->with('status', 'Компонент «'.$component->name.'» добавлен');
    }

    /** Включение/выключение компонента (история строк не переписывается). */
    public function toggleComponent(Request $request, SalaryComponent $component): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ((int) $component->company_id !== (int) $me->company_id) {
            abort(403, 'Компонент другой компании');
        }

        $component->update(['is_active' => ! $component->is_active]);

        $this->audit->log($request->user(), 'payroll.component.toggled', 'salary_component', $component->id, [
            'is_active' => ! $component->is_active,
        ], [
            'is_active' => $component->is_active,
        ]);

        return back()->with('status', 'Компонент «'.$component->name.'» '.($component->is_active ? 'включён' : 'выключен'));
    }
}
