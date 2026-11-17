<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\CrmClientKind;
use App\Shared\Enums\CrmClientStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Клиент компании (юридическое лицо или частное лицо) — модуль CRM.
 */
class CrmClient extends Model
{
    protected $table = 'crm_clients';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => CrmClientKind::class,
            'status' => CrmClientStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** @return HasMany<CrmContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(CrmContact::class, 'client_id');
    }

    /** @return HasMany<CrmDeal, $this> */
    public function deals(): HasMany
    {
        return $this->hasMany(CrmDeal::class, 'client_id');
    }

    /** @return HasMany<CrmActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class, 'client_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', CrmClientStatus::Active->value);
    }

    public function primaryContact(): ?CrmContact
    {
        return $this->contacts->firstWhere('is_primary', true) ?? $this->contacts->first();
    }
}
