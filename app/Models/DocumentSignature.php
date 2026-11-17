<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DocumentSignature extends Model
{
    protected $table = 'document_signatures';
    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок
    protected function casts(): array
    {
        return [
            'status' => \App\Shared\Enums\SignatureStatus::class,
            'signature_type' => \App\Shared\Enums\SignatureDriver::class,
            'signed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'meta' => 'array'
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function signatureRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentSignatureRequest::class, 'signature_request_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'signer_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'revoked_by');
    }

}
