<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum CrmLeadStatus: string
{
    case New = 'new';
    case Qualified = 'qualified';
    case Converted = 'converted';
    case Lost = 'lost';

    /** Лид ещё в работе: его можно квалифицировать, потерять или конвертировать. */
    public function isOpen(): bool
    {
        return $this === self::New || $this === self::Qualified;
    }
}
