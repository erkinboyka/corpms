<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum PayrollPeriodStatus: string
{
    case Draft = 'draft';
    case Calculated = 'calculated';
    case Approved = 'approved';
    case Paid = 'paid';
}
