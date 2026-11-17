<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum WorkflowAction: string
{
    case Approve = 'approve';
    case Review = 'review';
    case Sign = 'sign';
}
