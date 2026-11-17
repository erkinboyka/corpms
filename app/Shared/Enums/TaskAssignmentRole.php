<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum TaskAssignmentRole: string
{
    case Assignee = 'assignee';
    case Coassignee = 'coassignee';
}
