<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum PermissionScope: string
{
    case Own = 'own';
    case Department = 'department';
    case Company = 'company';

    /** Порядок широты доступа: own < department < company. */
    public function rank(): int
    {
        return match ($this) {
            self::Own => 0,
            self::Department => 1,
            self::Company => 2,
        };
    }
}
