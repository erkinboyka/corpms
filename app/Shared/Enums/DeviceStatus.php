<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum DeviceStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Disabled = 'disabled';
}
