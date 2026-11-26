<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum AttendanceSource: string
{
    case Biometric = 'biometric';
    case Qr = 'qr';
    case Kiosk = 'kiosk';
    case Manual = 'manual';
    case OffsiteRequest = 'offsite_request';
    case System = 'system';
}
