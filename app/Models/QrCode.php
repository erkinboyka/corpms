<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\QrKind;
use App\Shared\Enums\QrStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrCode extends Model
{
    protected $table = 'qr_codes';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function casts(): array
    {
        return [
            'kind' => QrKind::class,
            'status' => QrStatus::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'generation' => 'int',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
