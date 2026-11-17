<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum CrmClientKind: string
{
    case Company = 'company';
    case Person = 'person';
}
