<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONB-01: след выданной потоком роли. pre_existing защищает роли, которые
 * были у сотрудника до онбординга, от случайного отзыва.
 */
class OnboardingAccessGrant extends Model
{
    protected $table = 'onboarding_access_grants';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pre_existing' => 'boolean',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
