<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum Confidentiality: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Confidential = 'confidential';
    case Secret = 'secret';
}
