<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum RfidCardStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
