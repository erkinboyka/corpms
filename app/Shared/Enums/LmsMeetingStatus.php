<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum LmsMeetingStatus: string
{
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Ended = 'ended';
}
