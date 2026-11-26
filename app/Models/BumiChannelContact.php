<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Внешняя личность клиента, привязанная к каналу и (опционально) к лиду CRM. */
class BumiChannelContact extends Model
{
    protected $table = 'bumi_channel_contacts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_seen_at' => 'datetime',
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

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'crm_lead_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(CrmClient::class, 'crm_client_id');
    }

    /** @return HasMany<BumiChannelMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(BumiChannelMessage::class, 'contact_id');
    }

    public function displayLabel(): string
    {
        return trim((string) ($this->display_name ?: $this->external_id)) !== ''
            ? trim((string) ($this->display_name ?: $this->external_id))
            : __('Клиент');
    }
}
