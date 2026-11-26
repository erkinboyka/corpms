<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Статусы заявки на удалённую работу (п. 27–29 ТЗ).
 * Присутствие даёт ТОЛЬКО Approved.
 */
enum OffsiteRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
