<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Реакция на сообщение: один сотрудник — одна реакция одного emoji.
 * Повторное нажатие снимает реакцию (уникальный индекс гарантирует
 * отсутствие дублей при параллельных запросах).
 */
class MessageReaction extends Model
{
    protected $table = 'message_reactions';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
