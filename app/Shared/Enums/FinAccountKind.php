<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum FinAccountKind: string
{
    case Cash = 'cash';
    case Bank = 'bank';
}
