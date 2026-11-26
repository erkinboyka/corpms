<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/** Тип рабочего дня в табеле (п. 27 ТЗ). */
enum AttendanceWorkType: string
{
    case Office = 'office';
    case Offsite = 'offsite';
    case BusinessTrip = 'business_trip';
    case Sick = 'sick';
    case Leave = 'leave';
    case DayOff = 'day_off';
}
