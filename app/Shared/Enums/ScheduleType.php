<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum ScheduleType: string
{
    case Standard = 'standard';
    case Flexible = 'flexible';
    case Shift = 'shift';
    case Custom = 'custom';
}
