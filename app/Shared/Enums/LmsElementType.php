<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum LmsElementType: string
{
    case Path = 'path';
    case Rect = 'rect';
    case Ellipse = 'ellipse';
    case Text = 'text';
    case Sticky = 'sticky';
}
