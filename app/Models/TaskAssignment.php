<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\AssigneeType;
use App\Shared\Enums\TaskAssignmentRole;
use App\Shared\Enums\TaskAssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskAssignment extends Model
{
    protected $table = 'task_assignments';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function casts(): array
    {
        return [
            'assignee_type' => AssigneeType::class,
            'role' => TaskAssignmentRole::class,
            'assignment_status' => TaskAssignmentStatus::class,
            'acknowledged_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
