<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class QrCode extends Model
{
    protected $table = 'qr_codes';
    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок
    protected function casts(): array
    {
        return [
            'kind' => \App\Shared\Enums\QrKind::class,
            'status' => \App\Shared\Enums\QrStatus::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'generation' => 'int'
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

}
