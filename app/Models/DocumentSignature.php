<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\SignatureDriver;
use App\Shared\Enums\SignatureStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSignature extends Model
{
    protected $table = 'document_signatures';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function casts(): array
    {
        return [
            'status' => SignatureStatus::class,
            'signature_type' => SignatureDriver::class,
            'signed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'meta' => 'array',
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
