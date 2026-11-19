<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\ConversationRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Участие сотрудника в диалоге.
 *
 * Хранит прочитанную границу (last_read_message_id) — по ней считается
 * количество непрочитанных без отдельной таблицы на каждое сообщение.
 * Для каналов с company-видимостью строка создаётся только при
 * осознанной подписке или участии в закрытом канале.
 */
class ConversationMember extends Model
{
    protected $table = 'conversation_members';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'role' => ConversationRole::class,
            'last_read_message_id' => 'integer',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function isAdmin(): bool
    {
        return $this->role->isAdmin();
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }
}
