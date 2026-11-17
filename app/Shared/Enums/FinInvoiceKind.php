<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum FinInvoiceKind: string
{
    case Invoice = 'invoice';
    case Act = 'act';
}
