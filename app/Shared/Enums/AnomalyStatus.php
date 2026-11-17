<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum AnomalyStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Ignored = 'ignored';
}
