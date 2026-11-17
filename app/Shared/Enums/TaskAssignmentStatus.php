<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum TaskAssignmentStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Completed = 'completed';
    case Returned = 'returned';
    case Rejected = 'rejected';
}
