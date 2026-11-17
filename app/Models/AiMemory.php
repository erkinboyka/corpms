<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * D6 (AI Memory): запись памяти AI-ассистента о сотруднике.
 *
 * employee_id + memory_key уникальны — повторная команда «запомни X»
 * обновляет значение, а не дублирует запись.
 */
class AiMemory extends Model
{
    protected $table = 'ai_memories';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
