<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\ScheduleType;
use Carbon\CarbonInterface;
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
            'work_days' => 'array',
        ];
    }

    /** @return array<int, int> ISO-дни недели: 1 = Пн … 7 = Вс. */
    public function workingDays(): array
    {
        $days = is_array($this->work_days) ? $this->work_days : [1, 2, 3, 4, 5];
        $days = array_values(array_unique(array_map('intval', $days)));
        $days = array_values(array_filter($days, static fn (int $day): bool => $day >= 1 && $day <= 7));
        sort($days);

        return $days !== [] ? $days : [1, 2, 3, 4, 5];
    }

    public function isWorkingDay(CarbonInterface $day): bool
    {
        return in_array((int) $day->dayOfWeekIso, $this->workingDays(), true);
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
