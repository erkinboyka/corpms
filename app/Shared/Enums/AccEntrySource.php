<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum AccEntrySource: string
{
    case Manual = 'manual';
    case Finance = 'finance';
}
