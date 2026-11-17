<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum StockMovementType: string
{
    case In = 'in';
    case Out = 'out';
    case Adjustment = 'adjustment';
    case WriteOff = 'write_off';
}
