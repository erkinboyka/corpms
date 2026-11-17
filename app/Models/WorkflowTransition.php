<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись журнала переходов WorkflowEngine (Этап B).
 * Одна строка = один успешный переход статусной машины субъекта.
 */
class WorkflowTransition extends Model
{
    protected $table = 'workflow_transitions';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function casts(): array
    {
        return [
            'actor_user_id' => 'integer',
            'subject_id' => 'integer',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
