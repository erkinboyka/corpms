<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum AssigneeType: string
{
    case Employee = 'employee';
    case Department = 'department';
}
