<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum LmsSignalType: string
{
    case Hello = 'hello';
    case Offer = 'offer';
    case Answer = 'answer';
    case Ice = 'ice';
    case Bye = 'bye';
    case Control = 'control';
}
