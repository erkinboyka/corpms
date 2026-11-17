<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum CrmClientStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
