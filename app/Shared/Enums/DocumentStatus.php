<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Review = 'review';
    case Revision = 'revision';
    case Approved = 'approved';
    case Signing = 'signing';
    case Signed = 'signed';
    case Registered = 'registered';
    case Archived = 'archived';
    case Rejected = 'rejected';
}
