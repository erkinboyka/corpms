<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BiometricProfile extends Model
{
    protected $table = 'biometric_profiles';
    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок
    protected function casts(): array
    {
        return [
            'status' => \App\Shared\Enums\BiometricProfileStatus::class,
            'enrolled_at' => 'datetime',
            'expires_at' => 'datetime'
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
