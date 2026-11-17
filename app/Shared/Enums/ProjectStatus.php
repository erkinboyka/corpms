<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum ProjectStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** Проект ещё в работе (не закрыт). */
    public function isOpen(): bool
    {
        return $this !== self::Completed && $this !== self::Cancelled;
    }
}
