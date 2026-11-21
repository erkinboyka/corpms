<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum LmsSubmissionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Reviewed = 'reviewed';
    case Returned = 'returned';
}
