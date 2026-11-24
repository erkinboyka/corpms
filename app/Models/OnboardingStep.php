<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\OnboardingStepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONB-01: состояние отдельного шага потока найма.
 */
class OnboardingStep extends Model
{
    protected $table = 'onboarding_steps';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OnboardingStepStatus::class,
            'payload' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(OnboardingFlow::class, 'flow_id');
    }
}
