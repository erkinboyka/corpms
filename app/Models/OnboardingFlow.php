<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\OnboardingFlowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ONB-01: один поток адаптации на сотрудника (профиль → документы → обучение → задачи → доступы).
 */
class OnboardingFlow extends Model
{
    protected $table = 'onboarding_flows';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OnboardingFlowStatus::class,
            'payload' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<OnboardingStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(OnboardingStep::class, 'flow_id');
    }

    /** @return HasMany<OnboardingAccessGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(OnboardingAccessGrant::class, 'flow_id');
    }
}
