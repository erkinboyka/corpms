<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum AttendanceStatus: string
{
    case OnTime = 'on_time';
    case Late = 'late';
    case Absent = 'absent';
    case EarlyLeave = 'early_leave';
    case Overtime = 'overtime';
    case Present = 'present';
    case Weekend = 'weekend';
    case Holiday = 'holiday';
}
