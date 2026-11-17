<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\Confidentiality;
use App\Shared\Enums\DocumentPriority;
use App\Shared\Enums\DocumentStatus;
use App\Shared\Enums\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    protected $table = 'documents';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'type' => DocumentType::class,
            'priority' => DocumentPriority::class,
            'confidentiality' => Confidentiality::class,
            'due_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'author_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'document_id');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class, 'id', 'current_version_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(DocumentApproval::class, 'document_id');
    }

    public function signatureRequests(): HasMany
    {
        return $this->hasMany(DocumentSignatureRequest::class, 'document_id');
    }

    public function workflowState(): HasOne
    {
        return $this->hasOne(DocumentWorkflowState::class, 'document_id');
    }

    /** D4 (RAG): чанки документа для AI-поиска. */
    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class, 'document_id');
    }
}
