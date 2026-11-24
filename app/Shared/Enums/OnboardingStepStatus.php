<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum OnboardingStepStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
