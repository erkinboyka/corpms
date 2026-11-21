<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Services;

use App\Models\AttendanceAnomaly;
use App\Models\AttendanceRecord;
use App\Models\BiometricEvent;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\ScheduleAssignment;
use App\Models\WorkSchedule;
use App\Shared\Enums\AnomalyType;
use App\Shared\Enums\AttendanceStatus;
use Illuminate\Support\Carbon;

/**
 * Обработка биометрических событий → табель (п. 27, 28, 29, 71 ТЗ).
 *
 * Поток: BiometricEventReceived → ValidateEvent → IdentifyEmployee
 *        → CreateAttendanceRecord → CalculateStatus → DispatchNotifications
 */
class AttendanceService
{
    /**
     * Применяет биометрическое событие к табелю.
     * Идемпотентность по event_uuid обеспечена на уровне BP (unique).
     */
    public function applyEvent(Employee $employee, BiometricEvent $event, ?WorkSchedule $schedule = null): AttendanceRecord
    {
        $day = Carbon::parse($event->occurred_at)->startOfDay();
        $record = AttendanceRecord::query()->firstOrNew([
            'employee_id' => $employee->id,
            'date' => $day,
        ]);

        $schedule ??= $this->scheduleFor($employee, $day->toDateString());

        if ($event->direction === 'in') {
            if ($record->exists && $record->clock_in_at !== null) {
                return $record;
            }

            $record->clock_in_at = $event->occurred_at;
            $record->source = 'biometric';
            $record->status = $this->statusOnArrival($record->clock_in_at, $schedule, $day, (int) $employee->company_id);
            $record->late_minutes = $this->lateMinutes($record->clock_in_at, $schedule, $day);
            $record->scheduled_minutes = $schedule !== null
                ? (int) $day->copy()->setTimeFromTimeString($schedule->start_time)->diffInMinutes($day->copy()->setTimeFromTimeString($schedule->end_time), true)
                : 0;
        } else {
            if ($record->clock_out_at !== null) {
                return $record;
            }

            $record->clock_out_at = $event->occurred_at;
            $this->recalculateForDate($record, $schedule, $day);
        }

        $record->schedule_snapshot = [
            'work_schedule_id' => $schedule?->id,
            'start_time' => is_string($schedule?->start_time) ? $schedule->start_time : null,
            'end_time' => is_string($schedule?->end_time) ? $schedule->end_time : null,
            'break_start' => is_string($schedule?->break_start) ? $schedule->break_start : null,
            'break_end' => is_string($schedule?->break_end) ? $schedule->break_end : null,
            'grace_minutes' => $schedule?->grace_minutes ?? 10,
        ];

        $record->save();

        return $record;
    }

    /** Статус прихода: выходной / отсутствует график / вовремя / опоздал. */
    public function statusOnArrival(Carbon $clockIn, ?WorkSchedule $schedule, Carbon $day, ?int $companyId = null): AttendanceStatus
    {
        if ($this->isDayOff($day, $companyId ?? $schedule?->company_id)) {
            return AttendanceStatus::Weekend;
        }

        if ($schedule === null) {
            return AttendanceStatus::Present;
        }

        $late = $this->lateMinutes($clockIn, $schedule, $day);

        return $late > ($schedule->grace_minutes ?? 10)
            ? AttendanceStatus::Late
            : AttendanceStatus::OnTime;
    }

    /** Пересчёт: worked / late / early leave / overtime (п. 28, 33 ТЗ). */
    public function recompute(AttendanceRecord $record, ?WorkSchedule $schedule = null): AttendanceRecord
    {
        $day = Carbon::parse($record->date)->startOfDay();
        $schedule ??= $this->scheduleFor($record->employee, $day->toDateString());
        $this->recalculateForDate($record, $schedule, $day);
        $record->save();

        return $record;
    }

    public function recalculateForDate(AttendanceRecord $record, ?WorkSchedule $schedule, Carbon $day): void
    {
        $zero = static function (AttendanceRecord $r) {
            $r->late_minutes = 0;
            $r->early_leave_minutes = 0;
            $r->overtime_minutes = 0;
            $r->break_minutes = 0;
            $r->worked_minutes = 0;
            $r->scheduled_minutes = 0;
        };

        if ($record->clock_in_at === null || $record->clock_out_at === null) {
            $zero($record);

            return;
        }

        if ($schedule === null) {
            $zero($record);
            $record->worked_minutes = (int) $record->clock_in_at->diffInMinutes($record->clock_out_at, true);
            $record->status = AttendanceStatus::Present;

            return;
        }

        $scheduledStart = $day->copy()->setTimeFromTimeString($schedule->start_time);
        $scheduledEnd = $day->copy()->setTimeFromTimeString($schedule->end_time);

        $record->late_minutes = $this->lateMinutes($record->clock_in_at, $schedule, $day);
        $record->scheduled_minutes = (int) $scheduledStart->diffInMinutes($scheduledEnd, true);

        $record->early_leave_minutes = $record->clock_out_at->lt($scheduledEnd)
            ? (int) $record->clock_out_at->diffInMinutes($scheduledEnd, true)
            : 0;

        $record->overtime_minutes = $record->clock_out_at->gt($scheduledEnd)
            ? (int) $scheduledEnd->diffInMinutes($record->clock_out_at, true)
            : 0;

        $breakMinutes = $this->breakMinutes($schedule, $day);
        $span = (int) $record->clock_in_at->diffInMinutes($record->clock_out_at, true);
        $record->break_minutes = $breakMinutes;
        $record->worked_minutes = $span >= $breakMinutes
            ? max(0, $span - $breakMinutes)
            : $span;

        if ($record->late_minutes > 0) {
            $record->status = AttendanceStatus::Late;
        } elseif ($record->early_leave_minutes > 0) {
            $record->status = AttendanceStatus::EarlyLeave;
        } elseif ($record->overtime_minutes > 0) {
            $record->status = AttendanceStatus::Overtime;
        } else {
            $record->status = AttendanceStatus::OnTime;
        }
    }

    public function lateMinutes(Carbon $clockIn, ?WorkSchedule $schedule, Carbon $day): int
    {
        if ($schedule === null || is_string($schedule->start_time) === false) {
            return 0;
        }

        $scheduledStart = $day->copy()->setTimeFromTimeString($schedule->start_time);

        if ($clockIn->lessThanOrEqualTo($scheduledStart->copy()->addMinutes($schedule->grace_minutes ?? 10))) {
            return 0;
        }

        // опоздание меряем от планового старта, grace НЕ выдаёт минуту бесплатно (по ТЗ статус LATE от grace)
        return (int) $scheduledStart->diffInMinutes($clockIn, true);
    }

    /** рабочий график на день: назначение по сотруднику > текущий график сотрудника */
    public function scheduleFor(Employee $employee, string $dateString): ?WorkSchedule
    {
        $assigned = ScheduleAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $dateString)
            ->where(function ($q) use ($dateString) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $dateString);
            })
            ->orderByDesc('effective_from')
            ->value('work_schedule_id');

        return WorkSchedule::find($assigned ?? $employee->work_schedule_id);
    }

    public function isDayOff(Carbon $day, ?int $companyId = null): bool
    {
        if (in_array($day->dayOfWeekIso, [6, 7], true)) {
            return true;
        }

        return Holiday::query()->whereDate('date', $day->toDateString())->where('is_day_off', true)
            ->where(fn ($query) => $query->whereNull('company_id')->when($companyId !== null, fn ($scope) => $scope->orWhere('company_id', $companyId)))
            ->exists();
    }

    public function detectMissingCheckout(Carbon $day): int
    {
        $records = AttendanceRecord::query()
            ->whereDate('date', $day->toDateString())
            ->whereNotNull('clock_in_at')
            ->whereNull('clock_out_at')
            ->get();

        foreach ($records as $record) {
            AttendanceAnomaly::updateOrCreate(
                ['employee_id' => $record->employee_id, 'date' => $day->toDateString(), 'type' => AnomalyType::MissingCheckout],
                ['description' => __('Не зафиксирован выход'), 'status' => 'open']
            );
        }

        return $records->count();
    }

    protected function breakMinutes(?WorkSchedule $schedule, Carbon $day): int
    {
        if ($schedule?->break_start === null || $schedule->break_end === null) {
            return 0;
        }

        $start = $day->copy()->setTimeFromTimeString($schedule->break_start);
        $end = $day->copy()->setTimeFromTimeString($schedule->break_end);

        return max(0, (int) $start->diffInMinutes($end, true));
    }
}
