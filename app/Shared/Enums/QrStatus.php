<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum QrStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Blocked = 'blocked';
}
