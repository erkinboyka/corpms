<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum AnomalyType: string
{
    case MissingCheckout = 'missing_checkout';
    case NoShow = 'no_show';
    case Duplicate = 'duplicate';
    case DirectionMismatch = 'direction_mismatch';
    case Unrecognized = 'unrecognized';
}
