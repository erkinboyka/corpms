<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Events;

use App\Models\AttendanceRecord;
use App\Models\BiometricEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Запись посещаемости создана/обновлена биометрией.
 * Позднее: broadcast на Live-дашборд (Reverb), уведомления об опоздании.
 */
class AttendanceRecorded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AttendanceRecord $record,
        public BiometricEvent $event
    ) {}
}
