<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum BiWidgetKind: string
{
    case Kpi = 'kpi';
    case Trend = 'trend';
}
