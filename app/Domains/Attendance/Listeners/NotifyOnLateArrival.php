<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Listeners;

use App\Domains\Attendance\Events\AttendanceRecorded;
use App\Domains\Notifications\Services\NotificationService;
use App\Models\Employee;
use App\Shared\Enums\AttendanceStatus;

/**
 * п.13 ТЗ: уведомления.
 *  - опоздание → информируем сотрудника (app) и руководителя отдела (app + telegram).
 */
class NotifyOnLateArrival
{
    public function __construct(protected NotificationService $notifications)
    {
    }

    public function handle(AttendanceRecorded $event): void
    {
        $record = $event->record;

        if ($record->status !== AttendanceStatus::Late) {
            return;
        }

        $employee = $record->employee;
        $when = $record->clock_in_at?->format('H:i');
        $minutes = (int) $record->late_minutes;
        $name = $employee->last_name . ' ' . $employee->first_name;

        // сотрудник: тихо, in-app
        $this->notifications->notify(
            $employee,
            'attendance.late',
            'Опоздание зафиксировано',
            "Вход {$when}, опоздание {$minutes} мин. Корректировка — по согласованию с руководителем.",
            $record,
        );

        // руководитель отдела: app + telegram
        if ($employee->department_id !== null) {
            $director = Employee::query()->find($employee->department->director_id);

            if ($director !== null && (int) $director->id !== (int) $employee->id) {
                $this->notifications->notify(
                    $director,
                    'attendance.late_director',
                    "Опоздание: {$name}",
                    "Вход {$when}, опоздание {$minutes} мин.",
                    $record,
                    NotificationService::CHANNEL_TELEGRAM,
                );
            }
        }
    }
}
