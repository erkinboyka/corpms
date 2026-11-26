<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\BumiMessageDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Сообщение клиента или ответ Bumi в разрезе внешнего канала. */
class BumiChannelMessage extends Model
{
    protected $table = 'bumi_channel_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'direction' => BumiMessageDirection::class,
            'payload' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(BumiChannel::class, 'channel_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(BumiChannelContact::class, 'contact_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(BumiAgent::class, 'agent_id');
    }

    public function isFromClient(): bool
    {
        return $this->direction === BumiMessageDirection::In;
    }

    /** Идемпотентный ключ клиента без префикса visitor. */
    public function clientMessageId(): ?string
    {
        $external = (string) ($this->payload['external_message_id'] ?? '');
        $separator = strpos($external, ':');

        return $separator === false ? ($external !== '' ? $external : null) : substr($external, $separator + 1);
    }

    /** Человеческий статус: в истории не показываем сырые значения БД. */
    public function statusLabel(): string
    {
        return match ((string) $this->status) {
            'queued' => __('В очереди'),
            'received' => __('Получено'),
            'pending' => __('Отправляется'),
            'sent' => __('Отправлено'),
            'answered' => __('Отвечено'),
            'failed' => __('Ошибка доставки'),
            'delivery_failed' => __('Ответ не доставлен'),
            'rate_limited' => __('Лимит ответов'),
            default => __('Неизвестный статус'),
        };
    }

    public function statusClass(): string
    {
        $status = (string) $this->status;

        if (in_array($status, ['sent', 'answered'], true)) {
            return 'crm-chip--green';
        }

        if (in_array($status, ['failed', 'delivery_failed'], true)) {
            return 'crm-chip--red';
        }

        if (in_array($status, ['pending', 'rate_limited'], true)) {
            return 'crm-chip--orange';
        }

        return 'crm-chip--grey';
    }
}
