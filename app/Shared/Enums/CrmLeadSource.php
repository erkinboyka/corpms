<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum CrmLeadSource: string
{
    case Web = 'web';
    case Call = 'call';
    case Referral = 'referral';
    case Event = 'event';
    case Telegram = 'telegram';
    case Instagram = 'instagram';
    case WhatsApp = 'whatsapp';
    case Other = 'other';
}
