<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum TaskType: string
{
    case Task = 'task';
    case Order = 'order';
    case DocumentTask = 'document_task';
    case ApprovalTask = 'approval_task';
    case DocumentPrep = 'document_prep';
    case DocumentSign = 'document_sign';
    case DocumentReview = 'document_review';
    case Service = 'service';
}
