<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\HolidayKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    protected $table = 'holidays';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'kind' => HolidayKind::class,
            'transferred_from' => 'date',
            'is_day_off' => 'bool',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
