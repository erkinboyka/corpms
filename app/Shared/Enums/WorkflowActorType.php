<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum WorkflowActorType: string
{
    case Role = 'role';
    case Department = 'department';
    case Employee = 'employee';
    case Author = 'author';
    case Ceo = 'ceo';
}
