<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TelegramLink extends Model
{
    protected $table = 'telegram_links';
    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'notifications_enabled' => 'bool'
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

}
