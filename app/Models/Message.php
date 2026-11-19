<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\MessageKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Сообщение BM Chat. Источник истины: запись в БД предшествует
 * любому broadcast, поэтому потеря соединения не теряет сообщение.
 */
class Message extends Model
{
    protected $table = 'messages';

    protected $guarded = [];

    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'kind' => MessageKind::class,
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
            'client_id' => 'string',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    /** @return HasMany<MessageAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class, 'message_id');
    }

    /** @return HasMany<MessageReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class, 'message_id');
    }

    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'pinned_by');
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
