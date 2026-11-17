<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum DeviceDirection: string
{
    case In = 'in';
    case Out = 'out';
    case Both = 'both';
}
