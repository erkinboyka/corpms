<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum AccAccountKind: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    /** Активные счета: сальдо дебетовое (дебет − кредит). */
    public function isDebitPositive(): bool
    {
        return $this === self::Asset || $this === self::Expense;
    }
}
