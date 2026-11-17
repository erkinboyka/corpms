<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum SignatureRequestStatus: string
{
    case Pending = 'pending';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Signed = 'signed';
    case Declined = 'declined';
    case Revision = 'revision';
    case Cancelled = 'cancelled';
    case Partial = 'partial';
}
