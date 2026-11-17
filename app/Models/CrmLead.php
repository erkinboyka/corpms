<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\CrmLeadSource;
use App\Shared\Enums\CrmLeadStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Лид CRM: потенциальный клиент до сделки. Конверсия создаёт клиента и сделку.
 */
class CrmLead extends Model
{
    protected $table = 'crm_leads';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => CrmLeadSource::class,
            'status' => CrmLeadStatus::class,
            'estimated_amount' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(CrmClient::class, 'client_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<CrmDeal, $this> */
    public function deals(): HasMany
    {
        return $this->hasMany(CrmDeal::class, 'lead_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [CrmLeadStatus::New->value, CrmLeadStatus::Qualified->value]);
    }
}
