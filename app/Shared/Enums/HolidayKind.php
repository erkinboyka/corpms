<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum HolidayKind: string
{
    case Public = 'public';
    case Operating = 'operating';
    case Shortened = 'shortened';
}
