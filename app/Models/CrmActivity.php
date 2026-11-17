<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\CrmActivityKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Активность CRM: звонок, встреча, письмо, задача или заметка.
 * Привязана к сделке, лиду или клиенту (минимум одна связь задаётся формой).
 */
class CrmActivity extends Model
{
    protected $table = 'crm_activities';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => CrmActivityKind::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(CrmDeal::class, 'deal_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
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

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('completed_at');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function isOverdue(): bool
    {
        return $this->isCompleted() === false && $this->due_at !== null && $this->due_at->isPast();
    }
}
