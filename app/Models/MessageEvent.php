<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\ChatEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Журнал событий диалога — основа синхронизации после обрыва связи.
 *
 * Клиент хранит последний известный event id и запрашивает дельты:
 * получает только изменившиеся сообщения, реакции, прочтения и состав.
 * Порядок гарантирован автоинкрементом id.
 */
class MessageEvent extends Model
{
    protected $table = 'message_events';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => ChatEventType::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'actor_employee_id');
    }
}
