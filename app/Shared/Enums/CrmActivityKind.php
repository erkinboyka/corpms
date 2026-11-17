<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum CrmActivityKind: string
{
    case Call = 'call';
    case Meeting = 'meeting';
    case Email = 'email';
    case Task = 'task';
    case Note = 'note';
}
