<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum LeaveType: string
{
    case Vacation = 'vacation';
    case Sick = 'sick';
    case BusinessLeave = 'business_leave';
    case DayOff = 'day_off';
    case Other = 'other';
}
