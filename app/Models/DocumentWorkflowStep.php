<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\WorkflowAction;
use App\Shared\Enums\WorkflowActorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentWorkflowStep extends Model
{
    protected $table = 'document_workflow_steps';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function casts(): array
    {
        return [
            'actor_type' => WorkflowActorType::class,
            'required_action' => WorkflowAction::class,
            'is_signature_step' => 'bool',
            'is_terminal' => 'bool',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(DocumentWorkflow::class, 'workflow_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
