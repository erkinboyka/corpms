<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum QrKind: string
{
    case Profile = 'profile';
    case Badge = 'badge';
    case Card = 'card';
    case Signing = 'signing';
}
