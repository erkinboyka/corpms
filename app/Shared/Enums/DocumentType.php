<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum DocumentType: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
    case Internal = 'internal';
}
