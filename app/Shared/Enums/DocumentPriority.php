<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum DocumentPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
