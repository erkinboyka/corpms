<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum BiometricEventStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
