<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Services;

use App\Models\AttendanceOffsiteRequest;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\ScheduleAssignment;
use App\Models\WorkSchedule;
use App\Shared\Enums\AttendanceSource;
use App\Shared\Enums\AttendanceStatus;
use App\Shared\Enums\AttendanceWorkType;
use App\Shared\Enums\LeaveStatus;
use App\Shared\Enums\LeaveType;
use App\Shared\Enums\OffsiteRequestStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Единый расчёт рабочего дня (п. 21–24 ТЗ).
 *
 * Приоритет источников:
 *   1. отпуск  2. больничный  3. командировка
 *   4. согласованная удалённая работа  5. офис  6. выходной.
 *
 * Важно: PENDING offsite не даёт присутствия. OFFICE — «обычный» путь,
 * поэтому resolve() для него возвращает null, и статус считает
 * AttendanceService по фактам face-in/face-out.
 */
class AttendanceCalculationService
{
    /** @var array<string, array{rank: int, work_type: AttendanceWorkType, status: AttendanceStatus}> */
    private const LEAVE_PRIORITY = [
        'vacation' => ['rank' => 1, 'work_type' => AttendanceWorkType::Leave, 'status' => AttendanceStatus::Present],
        'sick' => ['rank' => 2, 'work_type' => AttendanceWorkType::Sick, 'status' => AttendanceStatus::Present],
        'business_leave' => ['rank' => 3, 'work_type' => AttendanceWorkType::BusinessTrip, 'status' => AttendanceStatus::Present],
        'other' => ['rank' => 4, 'work_type' => AttendanceWorkType::Leave, 'status' => AttendanceStatus::Present],
        'day_off' => ['rank' => 6, 'work_type' => AttendanceWorkType::DayOff, 'status' => AttendanceStatus::Weekend],
    ];

    /**
     * @return array{status: AttendanceStatus, work_type: AttendanceWorkType, reason: string}|null
     */
    public function resolve(Employee $employee, CarbonInterface $day): ?array
    {
        $date = $day->toDateString();

        $leaves = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', LeaveStatus::Approved->value)
            ->whereDate('from_date', '<=', $date)
            ->whereDate('to_date', '>=', $date)
            ->get(['type']);

        $best = null;
        foreach ($leaves as $leave) {
            $rule = self::LEAVE_PRIORITY[$leave->type->value] ?? null;
            if ($rule === null) {
                continue;
            }
            if ($best === null || $rule['rank'] < $best['rank']) {
                $best = $rule + ['reason' => 'leave:'.$leave->type->value];
            }
        }

        if ($best !== null) {
            return [
                'status' => $best['status'],
                'work_type' => $best['work_type'],
                'reason' => $best['reason'],
            ];
        }

        // BL-OFFSITE-01: только APPROVED даёт присутствие; PENDING здесь не учитывается.
        $approvedOffsite = AttendanceOffsiteRequest::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->where('status', OffsiteRequestStatus::Approved->value)
            ->exists();

        if ($approvedOffsite) {
            return [
                'status' => AttendanceStatus::Present,
                'work_type' => AttendanceWorkType::Offsite,
                'reason' => 'offsite:approved',
            ];
        }

        if ($this->isDayOff($employee, $day)) {
            return [
                'status' => AttendanceStatus::Weekend,
                'work_type' => AttendanceWorkType::DayOff,
                'reason' => 'calendar:day_off',
            ];
        }

        // Офисный день: статус определяется биометрией в AttendanceService.
        return null;
    }

    /** Применяет приоритетный источник к записи табеля (идемпотентно). */
    public function applyTo(AttendanceRecord $record, ?CarbonInterface $day = null): AttendanceRecord
    {
        $day ??= Carbon::parse($record->date)->startOfDay();
        $resolved = $this->resolve($record->employee, $day);

        if ($resolved === null) {
            return $record;
        }

        $record->status = $resolved['status'];
        $record->work_type = $resolved['work_type']->value;

        // Происхождение отметки определяется приоритетным источником.
        // Ручную корректировку (manual) не перезаписываем.
        $source = $resolved['work_type'] === AttendanceWorkType::Offsite
            ? AttendanceSource::OffsiteRequest
            : AttendanceSource::System;
        if ($record->source === null || $record->source === AttendanceSource::Biometric) {
            $record->source = $source;
        }

        // Отпуск/больничный/командировка/выходной/одобренный offsite
        // не должны нести «опоздание», «ранний уход» или переработку.
        $record->late_minutes = 0;
        $record->early_leave_minutes = 0;
        $record->overtime_minutes = 0;

        return $record;
    }

    /** График на день: назначение > график сотрудника. Единая точка для обоих сервисов табеля. */
    public function scheduleFor(Employee $employee, CarbonInterface $day): ?WorkSchedule
    {
        $date = $day->toDateString();
        $assigned = ScheduleAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->value('work_schedule_id');

        return WorkSchedule::find($assigned ?? $employee->work_schedule_id);
    }

    /** Календарь выходных с учётом рабочих дней графика и «работающих» праздников. */
    public function isDayOffFor(CarbonInterface $day, ?int $companyId = null, ?WorkSchedule $schedule = null): bool
    {
        $nonWorking = $schedule !== null
            ? ! $schedule->isWorkingDay($day)
            : in_array((int) $day->dayOfWeekIso, [6, 7], true);

        // Праздник с is_day_off=false («работает») перекрывает выходной по графику.
        if ($this->holidayExists($day, $companyId, false)) {
            return false;
        }

        if ($nonWorking) {
            return true;
        }

        return $this->holidayExists($day, $companyId, true);
    }

    private function isDayOff(Employee $employee, CarbonInterface $day): bool
    {
        return $this->isDayOffFor(
            $day,
            $employee->company_id !== null ? (int) $employee->company_id : null,
            $this->scheduleFor($employee, $day),
        );
    }

    private function holidayExists(CarbonInterface $day, ?int $companyId, bool $isDayOff): bool
    {
        return Holiday::query()
            ->whereDate('date', $day->toDateString())
            ->where('is_day_off', $isDayOff)
            ->where(fn ($query) => $query->whereNull('company_id')
                ->when($companyId !== null, fn ($scope) => $scope->orWhere('company_id', $companyId)))
            ->exists();
    }
}
