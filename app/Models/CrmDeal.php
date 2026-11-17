<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\CrmDealStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Сделка CRM: деньги и стадия воронки. Закрытые стадии фиксируют closed_at.
 */
class CrmDeal extends Model
{
    protected $table = 'crm_deals';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'stage' => CrmDealStage::class,
            'amount' => 'decimal:2',
            'probability' => 'integer',
            'expected_close_date' => 'date',
            'closed_at' => 'date',
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

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<CrmActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class, 'deal_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('stage', [CrmDealStage::Won->value, CrmDealStage::Lost->value]);
    }

    public function isOpen(): bool
    {
        return $this->stage->isClosed() === false;
    }
}
