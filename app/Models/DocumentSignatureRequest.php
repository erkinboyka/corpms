<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\SignatureDriver;
use App\Shared\Enums\SignatureRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentSignatureRequest extends Model
{
    protected $table = 'document_signature_requests';

    protected $guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок

    protected function casts(): array
    {
        return [
            'driver' => SignatureDriver::class,
            'status' => SignatureRequestStatus::class,
            'requires_otp' => 'bool',
            'signers' => 'array',
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'signer_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(DocumentSignature::class, 'signature_request_id');
    }
}
