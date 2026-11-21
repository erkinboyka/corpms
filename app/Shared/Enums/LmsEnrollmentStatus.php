<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum LmsEnrollmentStatus: string
{
    case Enrolled = 'enrolled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
