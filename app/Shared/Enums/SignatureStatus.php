<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum SignatureStatus: string
{
    case Valid = 'valid';
    case Revoked = 'revoked';
    case Invalid = 'invalid';
}
