<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum EmployeeStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Dismissed = 'dismissed';
}
