<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum TaskStatus: string
{
    case New = 'new';
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Returned = 'returned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Overdue = 'overdue';
}
