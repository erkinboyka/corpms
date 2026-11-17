<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum AccDocumentKind: string
{
    case Invoice = 'invoice';
    case Act = 'act';
    case PaymentOrder = 'payment_order';
    case Other = 'other';
}
