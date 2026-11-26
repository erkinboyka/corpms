<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Агент Bumi: настройки personality/знаний и правила автоответа.
 * Запись всегда принадлежит компании (company_id) — как и остальные BI/CRM-сущности.
 */
class BumiAgent extends Model
{
    protected $table = 'bumi_agents';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'bool',
            'auto_reply' => 'bool',
            'create_leads' => 'bool',
            'max_replies_per_hour' => 'int',
            'default_owner_id' => 'int',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function defaultOwner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'default_owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<BumiChannel, $this> */
    public function channels(): HasMany
    {
        return $this->hasMany(BumiChannel::class, 'agent_id');
    }

    /** @return HasMany<BumiChannelMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(BumiChannelMessage::class, 'agent_id');
    }
}
