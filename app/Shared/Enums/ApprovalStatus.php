<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case RevisionRequested = 'revision_requested';
    case Skipped = 'skipped';
}
