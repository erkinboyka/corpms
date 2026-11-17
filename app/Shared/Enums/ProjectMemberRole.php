<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum ProjectMemberRole: string
{
    case Manager = 'manager';
    case Member = 'member';
    case Observer = 'observer';
}
