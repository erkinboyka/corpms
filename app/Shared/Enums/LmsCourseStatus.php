<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum LmsCourseStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
