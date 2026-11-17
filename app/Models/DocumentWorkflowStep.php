<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DocumentWorkflowStep extends Model
{
    protected $table = 'document_workflow_steps';
    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок
    protected function casts(): array
    {
        return [
            'actor_type' => \App\Shared\Enums\WorkflowActorType::class,
            'required_action' => \App\Shared\Enums\WorkflowAction::class,
            'is_signature_step' => 'bool',
            'is_terminal' => 'bool'
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
