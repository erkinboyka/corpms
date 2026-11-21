<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\LmsSignalType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsSignal extends Model
{
    protected $table = 'lms_signals';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => LmsSignalType::class,
            'payload' => 'array',
            'consumed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(LmsMeeting::class, 'meeting_id');
    }

    public function fromEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    public function toEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }
}
