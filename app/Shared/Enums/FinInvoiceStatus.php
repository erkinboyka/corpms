<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum FinInvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    /** Счёт открыт для оплаты (выставлен и ещё не закрыт). */
    public function isPayable(): bool
    {
        return $this === self::Issued;
    }

    public function isClosed(): bool
    {
        return $this === self::Paid || $this === self::Cancelled;
    }
}
