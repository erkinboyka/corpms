<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class DocumentVersion extends Model
{
    protected $table = 'document_versions';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    /** SEC-04: подписанная версия — неизменяемый снимок, её нельзя править или удалять. */
    protected static function booted(): void
    {
        static::updating(function (DocumentVersion $version): void {
            if ((bool) $version->getOriginal('immutable')) {
                throw new LogicException(__('Подписанная версия документа неизменяема'));
            }
        });

        static::deleting(function (DocumentVersion $version): void {
            if ((bool) $version->getOriginal('immutable')) {
                throw new LogicException(__('Подписанная версия документа неизменяема'));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'immutable' => 'bool',
            'sha256' => 'string',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
