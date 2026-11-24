<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum PurchaseObligationStatus: string
{
    case Open = 'open';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    /** Обязательство можно оплачивать. */
    public function isPayable(): bool
    {
        return $this === self::Open || $this === self::PartiallyPaid;
    }

    public function isClosed(): bool
    {
        return $this === self::Paid || $this === self::Cancelled;
    }
}
