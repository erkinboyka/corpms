<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum LmsAssignmentStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
}
