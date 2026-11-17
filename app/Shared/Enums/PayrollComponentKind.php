<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum PayrollComponentKind: string
{
    case Earning = 'earning';
    case Deduction = 'deduction';
}
