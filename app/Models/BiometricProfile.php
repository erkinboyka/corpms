<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\BiometricProfileStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricProfile extends Model
{
    protected $table = 'biometric_profiles';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function casts(): array
    {
        return [
            'status' => BiometricProfileStatus::class,
            'enrolled_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'enrolled_by');
    }
}
