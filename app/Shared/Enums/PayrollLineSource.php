<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum PayrollLineSource: string
{
    case Auto = 'auto';
    case Manual = 'manual';
}
