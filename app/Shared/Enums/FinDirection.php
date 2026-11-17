<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum FinDirection: string
{
    case Income = 'income';
    case Expense = 'expense';

    /** Знак операции в балансе счёта. */
    public function sign(): int
    {
        return $this === self::Income ? 1 : -1;
    }
}
