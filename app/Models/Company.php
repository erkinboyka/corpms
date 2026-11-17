<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $table = 'companies';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'settings' => 'array',
        ];
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'company_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class, 'company_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'company_id');
    }

    public function workSchedules(): HasMany
    {
        return $this->hasMany(WorkSchedule::class, 'company_id');
    }
}
