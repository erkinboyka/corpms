<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Notifications\Services\NotificationService;
use App\Models\AttendanceOffsiteRequest;
use App\Models\AttendanceRecord;
use App\Models\AttendanceStatusHistory;
use App\Models\Employee;
use App\Models\FaceVerificationLog;
use App\Shared\Enums\AttendanceSource;
use App\Shared\Enums\AttendanceStatus;
use App\Shared\Enums\AttendanceWorkType;
use App\Shared\Enums\OffsiteRequestStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Удалённая работа / offsite (п. 27–29, 71 ТЗ).
 *
 * BL-OFFSITE-01: PENDING не создаёт присутствия и не трогает табель.
 * BL-OFFSITE-02: только APPROVED даёт PRESENT + work_type=OFFSITE без face.
 * BL-OFFSITE-03: согласующий не может быть заявителем (self-approval запрещён).
 * Решение руководителя всегда пишется в Audit Log и уведомляет заявителя.
 */
class AttendanceOffsiteService
{
    public function __construct(
        protected AttendanceService $attendance,
        protected AuditService $audit,
        protected NotificationService $notifications,
    ) {}

    /**
     * Подача заявки. Пока статус Pending, табель НЕ изменяется.
     */
    public function request(
        Employee $requester,
        Employee $target,
        string $date,
        string $reason,
    ): AttendanceOffsiteRequest {
        if ((int) $requester->company_id !== (int) $target->company_id) {
            throw new LogicException(__('Сотрудник из другой компании'));
        }

        $day = Carbon::parse($date)->startOfDay()->toDateString();

        $exists = AttendanceOffsiteRequest::query()
            ->where('employee_id', $target->id)
            ->whereDate('date', $day)
            ->exists();

        if ($exists) {
            throw new LogicException(__('Заявка на эту дату уже есть'));
        }

        $offsite = AttendanceOffsiteRequest::create([
            'company_id' => $target->company_id,
            'employee_id' => $target->id,
            'date' => $day,
            'reason' => $reason,
            'status' => OffsiteRequestStatus::Pending->value,
            'requested_by' => $requester->id,
        ]);

        $this->audit->log(
            $requester->user,
            'attendance.offsite.requested',
            'attendance_offsite_request',
            $offsite->id,
            null,
            ['employee_id' => $target->id, 'date' => $day, 'reason' => $reason],
            null,
            (int) $target->company_id,
        );

        foreach ($this->approversFor($target) as $approver) {
            $this->notifications->notify(
                $approver,
                'attendance.offsite.requested',
                __('Заявка на удалённую работу'),
                $this->employeeName($target).' — '.$day.'. '.\Illuminate\Support\Str::limit($reason, 200),
                $offsite,
            );
        }

        return $offsite;
    }

    /**
     * Решение руководителя. $canApproveScope — право из PermissionService
     * (company/department) либо непосредственный руководитель по manager_id.
     */
    public function decide(
        Employee $decider,
        AttendanceOffsiteRequest $request,
        bool $approve,
        ?string $comment = null,
        bool $canApproveScope = false,
    ): AttendanceOffsiteRequest {
        return DB::transaction(function () use ($decider, $request, $approve, $comment, $canApproveScope) {
            /** @var AttendanceOffsiteRequest $locked */
            $locked = AttendanceOffsiteRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== OffsiteRequestStatus::Pending) {
                throw new LogicException(__('Заявка уже рассмотрена'));
            }

            $employee = $locked->employee;

            if ((int) $employee->company_id !== (int) $decider->company_id) {
                throw new LogicException(__('Заявка из другой компании'));
            }

            if ((int) $locked->employee_id === (int) $decider->id) {
                throw new LogicException(__('Нельзя согласовать собственную заявку'));
            }

            $isManager = $employee->manager_id !== null && (int) $employee->manager_id === (int) $decider->id;

            if (! $isManager && ! $canApproveScope) {
                throw new LogicException(__('Нет прав на согласование этой заявки'));
            }

            $locked->forceFill([
                'status' => $approve ? OffsiteRequestStatus::Approved->value : OffsiteRequestStatus::Rejected->value,
                'decided_by' => $decider->id,
                'decided_at' => now(),
                'decision_comment' => $comment,
            ])->save();

            if ($approve) {
                $this->applyApproved($locked, $decider, $comment);
            }

            $this->audit->log(
                $decider->user,
                $approve ? 'attendance.offsite.approved' : 'attendance.offsite.rejected',
                'attendance_offsite_request',
                $locked->id,
                ['status' => OffsiteRequestStatus::Pending->value],
                ['status' => $locked->status->value, 'decided_by' => $decider->id, 'comment' => $comment],
                null,
                (int) $employee->company_id,
            );

            $recipient = $locked->requester ?? $employee;
            $this->notifications->notify(
                $recipient,
                $approve ? 'attendance.offsite.approved' : 'attendance.offsite.rejected',
                $approve ? __('Удалённая работа согласована') : __('Заявка на удалённую работу отклонена'),
                $locked->date->toDateString().($comment !== null && $comment !== '' ? '. '.$comment : ''),
                $locked,
            );

            return $locked;
        });
    }

    /** Отзыв заявки: только заявитель/сотрудник, только пока Pending. */
    public function cancel(Employee $actor, AttendanceOffsiteRequest $request): AttendanceOffsiteRequest
    {
        return DB::transaction(function () use ($actor, $request) {
            /** @var AttendanceOffsiteRequest $locked */
            $locked = AttendanceOffsiteRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== OffsiteRequestStatus::Pending) {
                throw new LogicException(__('Заявка уже рассмотрена'));
            }

            if ((int) $actor->id !== (int) $locked->employee_id && (int) $actor->id !== (int) $locked->requested_by) {
                throw new LogicException(__('Отозвать заявку можно только за себя'));
            }

            $locked->forceFill(['status' => OffsiteRequestStatus::Cancelled->value])->save();

            $this->audit->log(
                $actor->user,
                'attendance.offsite.cancelled',
                'attendance_offsite_request',
                $locked->id,
                ['status' => OffsiteRequestStatus::Pending->value],
                ['status' => OffsiteRequestStatus::Cancelled->value],
                null,
                (int) $locked->company_id,
            );

            return $locked;
        });
    }

    /**
     * Применение согласованной заявки к табелю. Вынесено отдельно, чтобы
     * AttendanceCalculationService мог использовать тот же приоритетный слой.
     */
    public function applyApproved(
        AttendanceOffsiteRequest $request,
        Employee $decider,
        ?string $comment = null,
    ): AttendanceRecord {
        $day = $request->date->startOfDay();
        $date = $day->toDateString();

        /** @var AttendanceRecord|null $record */
        $record = AttendanceRecord::query()
            ->where('employee_id', $request->employee_id)
            ->whereDate('date', $date)
            ->lockForUpdate()
            ->first();

        $fromStatus = $record?->status?->value;

        $record ??= new AttendanceRecord([
            'employee_id' => $request->employee_id,
            'date' => $day,
        ]);

        $record->forceFill([
            'work_type' => AttendanceWorkType::Offsite->value,
            'status' => AttendanceStatus::Present,
            'source' => AttendanceSource::OffsiteRequest,
            'approved_by' => $decider->id,
            'notes' => $comment !== null && $comment !== ''
                ? __('Удалённая работа: ').$comment
                : __('Удалённая работа согласована руководителем'),
            // удалённая работа не проходит face-контроль: биометрические
            // страты не должны давать «опоздание/ранний уход»
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'overtime_minutes' => 0,
        ])->save();

        AttendanceStatusHistory::create([
            'attendance_record_id' => $record->id,
            'employee_id' => $request->employee_id,
            'company_id' => $request->company_id,
            'date' => $date,
            'from_status' => $fromStatus,
            'to_status' => AttendanceStatus::Present->value,
            'work_type' => AttendanceWorkType::Offsite->value,
            'changed_by' => $decider->id,
            'reason' => $request->reason,
        ]);

        // Заявка согласована без face — фиксируем факт в журнале, чтобы
        // проверяющий видел, почему нет записи верификации.
        FaceVerificationLog::create([
            'company_id' => $request->company_id,
            'employee_id' => $request->employee_id,
            'result' => 'skipped_offsite',
            'reason' => 'offsite approved: '.$request->id,
            'created_at' => now(),
        ]);

        return $record;
    }

    /** Запись истории смены статуса табеля (используется расчётным сервисом). */
    public function recordStatusChange(
        AttendanceRecord $record,
        ?string $fromStatus,
        ?Employee $changedBy = null,
        ?string $reason = null,
    ): AttendanceStatusHistory {
        return AttendanceStatusHistory::create([
            'attendance_record_id' => $record->id,
            'employee_id' => $record->employee_id,
            'company_id' => $record->employee->company_id,
            'date' => $record->date->toDateString(),
            'from_status' => $fromStatus,
            'to_status' => $record->status instanceof \BackedEnum ? $record->status->value : (string) $record->status,
            'work_type' => $record->work_type,
            'changed_by' => $changedBy?->id,
            'reason' => $reason,
        ]);
    }

    /** @return Collection<int, Employee> */
    protected function approversFor(Employee $employee): Collection
    {
        $approvers = collect();

        if ($employee->manager_id !== null) {
            $manager = Employee::query()->find($employee->manager_id);

            if ($manager !== null) {
                $approvers->push($manager);
            }
        }

        $directors = Employee::query()
            ->where('company_id', $employee->company_id)
            ->whereHas('roles', fn ($q) => $q->where('slug', 'company_director'))
            ->limit(20)
            ->get();

        return $approvers->concat($directors)->unique('id')->values();
    }

    protected function employeeName(Employee $employee): string
    {
        return trim($employee->last_name.' '.$employee->first_name);
    }
}
