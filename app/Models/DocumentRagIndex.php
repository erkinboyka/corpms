<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RAG-01: состояние индекса одного документа — отпечаток исходных данных,
 * поколение индекса и результат извлечения текста из вложения.
 */
class DocumentRagIndex extends Model
{
    protected $table = 'document_rag_indexes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'extracted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
