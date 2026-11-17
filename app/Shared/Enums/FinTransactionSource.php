<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum FinTransactionSource: string
{
    case Manual = 'manual';
    case InvoicePayment = 'invoice_payment';
}
