<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum OnboardingFlowStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::InProgress;
    }
}
