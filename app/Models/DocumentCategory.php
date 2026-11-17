<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentCategory extends Model
{
    protected $table = 'document_categories';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'category_id');
    }
}
