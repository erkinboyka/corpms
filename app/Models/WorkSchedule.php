<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\ScheduleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkSchedule extends Model
{
    protected $table = 'work_schedules';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'type' => ScheduleType::class,
            'allow_overtime' => 'bool',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(ScheduleShift::class, 'work_schedule_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'work_schedule_id');
    }
}
