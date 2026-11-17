<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\DeviceDirection;
use App\Shared\Enums\DeviceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BiometricDevice extends Model
{
    protected $table = 'biometric_devices';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function casts(): array
    {
        return [
            'direction' => DeviceDirection::class,
            'status' => DeviceStatus::class,
            'last_seen_at' => 'datetime',
            'capabilities' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BiometricEvent::class, 'device_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(BiometricEnrollment::class, 'device_id');
    }
}
