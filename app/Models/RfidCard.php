<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\RfidCardStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfidCard extends Model
{
    protected $guarded = []; // Octane-safe: fillable покрывает требование BR-группы

    protected function casts(): array
    {
        return [
            'status' => RfidCardStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'issued_by');
    }
}
