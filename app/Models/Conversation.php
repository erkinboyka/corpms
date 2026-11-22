<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\ConversationType;
use App\Shared\Enums\ConversationVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Диалог BM Chat: личная переписка, группа или канал компании.
 *
 * company_id обязателен — диалог не может существовать вне компании,
 * это закладывает межтенантную изоляцию на уровне схемы.
 */
class Conversation extends Model
{
    use SoftDeletes;

    protected $table = 'conversations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'visibility' => ConversationVisibility::class,
            'last_message_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /** @return HasMany<ConversationMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ConversationMember::class, 'conversation_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    /** @return HasMany<MessageEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(MessageEvent::class, 'conversation_id');
    }

    /** Участник-владелец/админ текущего набора (для быстрых проверок). */
    public function memberFor(int $employeeId): HasOne
    {
        return $this->hasOne(ConversationMember::class, 'conversation_id')
            ->where('employee_id', $employeeId);
    }

    public function isDirect(): bool
    {
        return $this->type === ConversationType::Direct;
    }

    public function isChannel(): bool
    {
        return $this->type === ConversationType::Channel;
    }

    /** Канал компании виден всем сотрудникам компании без явного участия. */
    public function isCompanyWide(): bool
    {
        return $this->isChannel() && $this->visibility === ConversationVisibility::Company;
    }

    public function titleFor(int $employeeId): string
    {
        if ($this->title !== null && $this->title !== '') {
            return $this->title;
        }

        // Личная переписка: имя собеседника вместо пустого заголовка.
        $peer = $this->members()
            ->where('employee_id', '!=', $employeeId)
            ->with('employee')
            ->first()?->employee;

        if ($peer === null) {
            return __('Диалог');
        }

        return trim($peer->first_name.' '.$peer->last_name);
    }
}
