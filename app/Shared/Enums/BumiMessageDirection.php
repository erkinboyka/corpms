<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum BumiMessageDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return $this === self::In ? __('Клиент') : __('Bumi');
    }
}
