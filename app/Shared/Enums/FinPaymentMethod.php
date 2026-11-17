<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum FinPaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Card = 'card';
    case Other = 'other';
}
