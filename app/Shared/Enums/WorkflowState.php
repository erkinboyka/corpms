<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum WorkflowState: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
}
