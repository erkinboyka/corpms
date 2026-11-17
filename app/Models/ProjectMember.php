<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\ProjectMemberRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Участник проекта (ресурс): роль и загрузка в процентах.
 */
class ProjectMember extends Model
{
    protected $table = 'project_members';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'role' => ProjectMemberRole::class,
            'allocation_percent' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
