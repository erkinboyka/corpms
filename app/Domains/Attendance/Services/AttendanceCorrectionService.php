<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Notifications\Services\NotificationService;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Shared\Enums\CorrectionStatus;
use App\Support\PermissionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Корректировки посещаемости (п.28 ТЗ): заявки на исправление отметок
 * (приход/уход/табель) с согласованием уполномоченным сотрудником.
 *
 * Права: attendance.correct — подать заявку; attendance.corrections.approve — решить.
 */
class AttendanceCorrectionService
{
    /** Допустимые поля корректировки. */
    public const FIELDS = ['clock_in_at', 'clock_out_at'];

    public function __construct(
        protected AttendanceService $attendance,
        protected PermissionService $permissions,
        protected AuditService $audit,
        protected NotificationService $notifications,
    ) {}

    /**
     * Подача заявки на корректировку.
     *
     * @throws LogicException если поле недопустимо или есть незакрытая заявка по этому полю
     */
    public function request(
        Employee $requester,
        Employee $target,
        string $field,
        string $date,
        ?Carbon $oldValue,
        ?Carbon $newValue,
        string $reason,
    ): AttendanceCorrection {
        if (! in_array($field, self::FIELDS, true)) {
            throw new LogicException('Недопустимое поле корректировки: '.$field);
        }

        $openExists = AttendanceCorrection::query()
            ->where('employee_id', $target->id)
            ->where('field', $field)
            ->whereDate('date', $date)
            ->where('status', CorrectionStatus::Pending->value)
            ->exists();

        if ($openExists) {
            throw new LogicException(__('По этой отметке уже есть заявка на рассмотрении'));
        }

        $correction = AttendanceCorrection::create([
            'employee_id' => $target->id,
            'date' => $date,
            'field' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'reason' => $reason,
            'status' => CorrectionStatus::Pending->value,
            'requested_by' => $requester->id,
        ]);

        $this->audit->log($requester->user, 'attendance.correction.requested', 'attendance_correction', $correction->id, null, [
            'employee_id' => $target->id,
            'field' => $field,
            'date' => $date,
        ]);

        return $correction;
    }

    /**
     * Решение по заявке. При согласовании — обновляет табель и пересчитывает показатели.
     */
    public function decide(
        Employee $decider,
        AttendanceCorrection $correction,
        bool $approve,
        ?string $comment = null,
    ): AttendanceCorrection {
        if ($correction->status !== CorrectionStatus::Pending) {
            throw new LogicException(__('Заявка уже рассмотрена'));
        }

        return DB::transaction(function () use ($decider, $correction, $approve, $comment) {
            $correction->forceFill([
                'status' => $approve ? CorrectionStatus::Approved->value : CorrectionStatus::Rejected->value,
                'decided_by' => $decider->id,
                'decided_at' => now(),
                'decision_comment' => $comment,
            ])->save();

            if ($approve) {
                $record = AttendanceRecord::query()
                    ->where('employee_id', $correction->employee_id)
                    ->whereDate('date', $correction->date->toDateString())
                    ->first();

                if ($record === null) {
                    $record = AttendanceRecord::create([
                        'employee_id' => $correction->employee_id,
                        'date' => $correction->date->toDateString(),
                    ]);
                }

                $record->forceFill([
                    $correction->field => $correction->new_value,
                    'corrected_by_employee_id' => $decider->id,
                ])->save();
                $this->attendance->recompute($record);
            }

            $this->audit->log($decider->user, $approve ? 'attendance.correction.approved' : 'attendance.correction.rejected', 'attendance_correction', $correction->id, null, [
                'employee_id' => $correction->employee_id,
                'field' => $correction->field,
            ]);

            // уведомим заявителя о решении
            if ($correction->requestedBy !== null) {
                $this->notifications->notify(
                    $correction->requestedBy,
                    'attendance.correction.decided',
                    $approve ? __('Корректировка согласована') : __('Корректировка отклонена'),
                    sprintf('%s: %s (%s)', trim($correction->employee->last_name.' '.$correction->employee->first_name), $correction->field, $comment ?? '—'),
                    $correction,
                );
            }

            return $correction;
        });
    }
}
