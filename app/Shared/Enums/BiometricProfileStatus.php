<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum BiometricProfileStatus: string
{
    case Pending = 'pending';
    case Enrolled = 'enrolled';
    case Disabled = 'disabled';
    case Deleted = 'deleted';
}
