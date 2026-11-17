<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum NotificationChannel: string
{
    case App = 'app';
    case Telegram = 'telegram';
    case Email = 'email';
}
