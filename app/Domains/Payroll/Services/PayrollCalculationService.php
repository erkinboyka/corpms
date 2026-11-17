<?php

declare(strict_types=1);

namespace App\Domains\Payroll\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\PayrollLine;
use App\Models\PayrollPeriod;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Shared\Enums\EmployeeStatus;
use App\Shared\Enums\LeaveStatus;
use App\Shared\Enums\LeaveType;
use App\Shared\Enums\PayrollCalculation;
use App\Shared\Enums\PayrollComponentKind;
use App\Shared\Enums\PayrollLineSource;
use App\Shared\Enums\PayrollPeriodStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Детерминированный расчётный движок зарплаты (Этап A: Payroll Core).
 *
 * Правила (никакого ИИ в суммах — только проверяемые формулы):
 *   fixed     — earning: оклад сотрудника; deduction: сумма из rate компонента
 *   hourly    — отработанные часы из табеля × rate
 *   overtime  — часы переработки из табеля × rate
 *   percent   — процент от оклада (rate / 100)
 *   manual    — фиксированная сумма из rate (премия/аванс по решению)
 *
 * Статусная машина периода: draft → calculated → approved → paid.
 * Пересчёт разрешён только из draft/calculated; назад — никогда.
 */
class PayrollCalculationService
{
    /** Пересчёт ведомости из табеля и окладов. Ручные строки (source=manual) сохраняются. */
    public function calculate(PayrollPeriod $period, User $actor): PayrollPeriod
    {
        if (in_array($period->status, [PayrollPeriodStatus::Draft, PayrollPeriodStatus::Calculated], true) === false) {
            throw new LogicException('Пересчёт возможен только для черновика или рассчитанного периода');
        }

        return DB::transaction(function () use ($period, $actor): PayrollPeriod {
            $start = $period->period_start->toDateString();
            $end = $period->period_end->toDateString();

            $components = SalaryComponent::query()
                ->where('company_id', $period->company_id)
                ->where('is_active', true)
                ->get();

            if ($components->isEmpty()) {
                throw new LogicException('В компании нет активных компонентов начисления');
            }

            // Сотрудники компании, работавшие в периоде (включая уволенных в периоде —
            // окончательный расчёт при увольнении)
            $employees = Employee::query()
                ->where('company_id', $period->company_id)
                ->where(fn ($q) => $q->whereNull('hired_at')->orWhere('hired_at', '<=', $end))
                ->where(function ($q) use ($start) {
                    $q->where('status', EmployeeStatus::Active->value)
                        ->orWhere(fn ($q2) => $q2
                            ->where('status', EmployeeStatus::Dismissed->value)
                            ->where('fired_at', '>=', $start));
                })
                ->get();

            $employeeIds = $employees->pluck('id');

            // Оклады, действующие в периоде; при пересечении берём самый свежий effective_from
            $salaries = EmployeeSalary::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('effective_from', '<=', $end)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start))
                ->orderByDesc('effective_from')
                ->get()
                ->groupBy('employee_id');

            // Табель за период (детерминированные минуты — источник часов)
            $attendance = AttendanceRecord::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereBetween('date', [$start, $end])
                ->get(['employee_id', 'worked_minutes', 'overtime_minutes'])
                ->groupBy('employee_id');

            // Одобренные оплачиваемые отпуска за период: vacation/sick/business_leave
            // (day_off/other не оплачиваются). Раньше отпуска игнорировались полностью —
            // почасовой сотрудник в отпуске получал 0, хотя отпуск оплачивается.
            $paidLeaveDays = LeaveRequest::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', LeaveStatus::Approved->value)
                ->whereIn('type', [
                    LeaveType::Vacation->value,
                    LeaveType::Sick->value,
                    LeaveType::BusinessLeave->value,
                ])
                ->where('from_date', '<=', $end)
                ->where('to_date', '>=', $start)
                ->get(['employee_id', 'from_date', 'to_date'])
                ->groupBy('employee_id');

            // Старые строки расчёта заменяем; ручные остаются нетронутыми
            $period->lines()->where('source', PayrollLineSource::Auto->value)->delete();

            $created = 0;
            foreach ($employees as $employee) {
                /** @var EmployeeSalary|null $salary */
                $salary = $salaries->get($employee->id)?->first();
                if ($salary === null) {
                    continue; // без оклада в периоде расчёт невозможен
                }

                $base = (float) $salary->base_amount;
                $minutes = $attendance->get($employee->id);
                $workedHours = round((int) ($minutes?->sum('worked_minutes') ?? 0) / 60, 2);
                $overtimeHours = round((int) ($minutes?->sum('overtime_minutes') ?? 0) / 60, 2);

                // оплачиваемый отпуск идёт в часы hourly-компонента (отпуск ≠ прогул)
                $workedHours = round($workedHours + $this->paidLeaveHours($employee, $paidLeaveDays->get($employee->id), $start, $end), 2);

                foreach ($components as $component) {
                    $row = $this->lineRow($component, $period->id, $employee->id, $base, $workedHours, $overtimeHours);
                    if ($row === null) {
                        continue;
                    }
                    PayrollLine::query()->create($row);
                    $created++;
                }
            }

            if ($created === 0 && $period->lines()->count() === 0) {
                throw new LogicException('Нет данных для расчёта: нужны оклады сотрудников и активные компоненты');
            }

            $this->refreshTotals($period, $actor);

            return $period->refresh();
        });
    }

    /**
     * Часы оплачиваемого отпуска сотрудника за период.
     *
     * Считаем по календарным дням пересечения отпуска с периодом, исключая
     * выходные и праздники (в них отпуск не оплачивается — они и так не рабочие).
     * Часы в день берутся из графика сотрудника; без графика — 8 ч.
     */
    protected function paidLeaveHours(Employee $employee, ?Collection $leaves, string $start, string $end): float
    {
        if ($leaves === null || $leaves->isEmpty()) {
            return 0.0;
        }

        $hoursPerDay = $this->scheduledHoursPerDay($employee);
        if ($hoursPerDay <= 0) {
            return 0.0;
        }

        $periodStart = Carbon::parse($start);
        $periodEnd = Carbon::parse($end);

        $holidays = Holiday::query()
            ->whereBetween('date', [$start, $end])
            ->pluck('date')
            ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d)
            ->all();
        $holidays = array_flip($holidays);

        $days = 0;
        foreach ($leaves as $leave) {
            $from = Carbon::parse($leave->from_date)->startOfDay();
            $to = Carbon::parse($leave->to_date)->startOfDay();

            $from = $from->lt($periodStart) ? $periodStart : $from;
            $to = $to->gt($periodEnd) ? $periodEnd : $to;

            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                if (in_array($day->dayOfWeekIso, [6, 7], true)) {
                    continue; // выходной
                }
                if (isset($holidays[$day->toDateString()])) {
                    continue; // праздник
                }
                $days++;
            }
        }

        return round($days * $hoursPerDay, 2);
    }

    /** Часы в день по графику сотрудника (график − перерыв); без графика — 8 ч. */
    protected function scheduledHoursPerDay(Employee $employee): float
    {
        /** @var WorkSchedule|null $schedule */
        $schedule = $employee->workSchedule;

        if ($schedule === null || ! is_string($schedule->start_time) || ! is_string($schedule->end_time)) {
            return 8.0;
        }

        $start = Carbon::parse($schedule->start_time);
        $end = Carbon::parse($schedule->end_time);

        $span = (int) $start->diffInMinutes($end, true);

        $break = 0;
        if (is_string($schedule->break_start ?? null) && is_string($schedule->break_end ?? null)) {
            $break = (int) Carbon::parse($schedule->break_start)
                ->diffInMinutes(Carbon::parse($schedule->break_end), true);
        }

        return round(max(0, $span - $break) / 60, 2);
    }

    /** Согласование ведомости: calculated → approved. */
    public function approve(PayrollPeriod $period, User $actor): PayrollPeriod
    {
        if ($period->status !== PayrollPeriodStatus::Calculated) {
            throw new LogicException('Согласовать можно только рассчитанную ведомость');
        }

        $period->update([
            'status' => PayrollPeriodStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $actor->id,
        ]);

        return $period->refresh();
    }

    /** Выплата: approved → paid. Финальный статус, откатов нет. */
    public function pay(PayrollPeriod $period, User $actor): PayrollPeriod
    {
        if ($period->status !== PayrollPeriodStatus::Approved) {
            throw new LogicException('Выплатить можно только согласованную ведомость');
        }

        $period->update([
            'status' => PayrollPeriodStatus::Paid,
            'paid_at' => now(),
            'paid_by' => $actor->id,
        ]);

        return $period->refresh();
    }

    /**
     * Строка ведомости или null, если по правилу сумма равна нулю.
     *
     * Арифметика — в целых центах (int), чтобы исключить накопление
     * float-погрешности при умножении и суммировании денег.
     *
     * @return array<string, mixed>|null
     */
    protected function lineRow(
        SalaryComponent $component,
        int $periodId,
        int $employeeId,
        float $base,
        float $workedHours,
        float $overtimeHours,
    ): ?array {
        $rate = (float) $component->rate;
        $baseCents = (int) round($base * 100);
        $rateCents = (int) round($rate * 100);

        [$quantity, $rateForLine, $amountCents] = match ($component->calculation) {
            PayrollCalculation::Fixed => [
                1.0,
                $component->kind === PayrollComponentKind::Earning ? $base : $rate,
                $component->kind === PayrollComponentKind::Earning ? $baseCents : $rateCents,
            ],
            PayrollCalculation::Hourly => [
                $workedHours,
                $rate,
                // часы × ставка, но в центах: round(round(hours,2) × rateCents)
                (int) round($workedHours * $rateCents),
            ],
            PayrollCalculation::Overtime => [
                $overtimeHours,
                $rate,
                (int) round($overtimeHours * $rateCents),
            ],
            PayrollCalculation::Percent => [
                1.0,
                $rate,
                // процент от оклада: baseCents × rate(%) / 100
                (int) round($baseCents * $rate / 100),
            ],
            PayrollCalculation::Manual => [1.0, $rate, $rateCents],
        };

        if ($amountCents <= 0) {
            return null; // нулевые строки не показываем
        }

        return [
            'period_id' => $periodId,
            'employee_id' => $employeeId,
            'component_id' => $component->id,
            'quantity' => round($quantity, 2),
            'rate' => round($rateForLine, 4),
            'amount' => $amountCents / 100,
            'source' => PayrollLineSource::Auto->value,
            'note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** Пересчёт итогов периода по всем строкам (включая ручные). Суммирование — в целых центах. */
    protected function refreshTotals(PayrollPeriod $period, User $actor): void
    {
        /** @var Collection<int, PayrollLine> $lines */
        $lines = $period->lines()->with('component')->get();

        $toCents = static fn (mixed $amount): int => (int) round(((float) $amount) * 100);

        $grossCents = $lines
            ->filter(fn (PayrollLine $l) => $l->component->kind === PayrollComponentKind::Earning)
            ->sum(fn (PayrollLine $l) => $toCents($l->amount));

        $deductionCents = $lines
            ->filter(fn (PayrollLine $l) => $l->component->kind === PayrollComponentKind::Deduction)
            ->sum(fn (PayrollLine $l) => $toCents($l->amount));

        $period->update([
            'status' => PayrollPeriodStatus::Calculated,
            'gross_amount' => $grossCents / 100,
            'deduction_amount' => $deductionCents / 100,
            'net_amount' => ($grossCents - $deductionCents) / 100,
            'employees_count' => $lines->pluck('employee_id')->unique()->count(),
            'calculated_at' => now(),
            'calculated_by' => $actor->id,
        ]);
    }
}
