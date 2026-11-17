<?php

declare(strict_types=1);

namespace App\Domains\Documents\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Notifications\Services\NotificationService;
use App\Domains\Workflow\WorkflowEngine;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\DocumentSignatureRequest;
use App\Models\DocumentVersion;
use App\Models\Employee;
use App\Models\QrToken;
use App\Support\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Электронная подпись (п.61–63 ТЗ).
 *
 *  - internal: хэш-подпись формата sha256(version_hash|signer|sig_id|nonce|key)
 *  - otp: код подтверждения (META запроса), позже уйдёт в уведомления (Telegram)
 *  - qr_approval: одноразовый QR-токен из qr_tokens (purpose=sign)
 *
 * Подписывать можно только согласованный документ (status=approved).
 * Версия после подписи становится immutable.
 */
class SignatureService
{
    public function __construct(
        protected AuditService $audit,
        protected WorkflowEngine $engine,
        protected NotificationService $notifications,
    ) {}

    /* -------------------- запросы подписи -------------------- */

    /**
     * Создаёт запросы подписи по списку подписантов (в порядке следования).
     */
    public function requestSignatures(Employee $requester, Document $document, array $signerIds, bool $requiresOtp = false, string $driver = 'internal'): void
    {
        $from = $document->status->value;
        $this->engine->assert(WorkflowEngine::SUBJECT_DOCUMENT, $from, 'signing', 'Подпись доступна только после согласования (п.59 ТЗ)');

        if ($signerIds === []) {
            throw new LogicException('Не указаны подписанты');
        }

        $version = $document->currentVersion;
        if ($version === null) {
            throw new LogicException('В документе нет текущей версии');
        }

        DB::transaction(function () use ($requester, $document, $version, $signerIds, $requiresOtp, $driver, $from) {
            foreach (array_values($signerIds) as $index => $signerId) {
                $signatureRequest = DocumentSignatureRequest::create([
                    'document_id' => $document->id,
                    'document_version_id' => $version->id,
                    'driver' => $driver,
                    'requested_by' => $requester->id,
                    'signer_id' => (int) $signerId,
                    'order_index' => $index + 1,
                    'requires_otp' => $requiresOtp,
                    'status' => 'pending',
                ]);

                // OTP-код выпускается сразу при запросе и доставляется подписанту.
                // Раньше код никогда не выдавался, и verifyOtp() всегда отвечал
                // «OTP-код не выдан» — подпись с requires_otp была невозможна.
                if ($requiresOtp) {
                    $this->issueAndDeliverOtp($signatureRequest, $document);
                }
            }

            $document->update(['status' => 'signing']);
            $this->engine->record(WorkflowEngine::SUBJECT_DOCUMENT, $document->id, $from, 'signing', 'request_signatures', $requester->user, null);

            $this->audit->log($requester->user, 'signature.requested', 'document', $document->id, null, [
                'signers' => array_map('intval', $signerIds),
                'version' => $version->version,
                'requires_otp' => $requiresOtp,
            ]);
        });
    }

    /** Выдача OTP-кода (в ТЗ — через уведомление; здесь возвращаем, чтобы канал доставкиподписал отдельно). */
    public function issueOtp(DocumentSignatureRequest $request): string
    {
        if (! $request->requires_otp) {
            throw new LogicException('Для этого запроса OTP не требуется');
        }

        $code = (string) random_int(100000, 999999);

        $request->forceFill([
            'meta' => array_merge($request->meta ?? [], [
                'otp_code' => hash('sha256', $code),
                'otp_expires_at' => now()->addMinutes((int) app(SettingsService::class)->get('corpms.signature.otp_ttl', 5))->toDateTimeString(),
                'otp_attempts' => 0,
            ]),
        ])->save();

        return $code;
    }

    /**
     * Выпускает OTP и доставляет его подписанту через шину уведомлений.
     *
     * Код хранится только хэшем (sha256) в meta запроса; в уведомление попадает
     * открытый код. Сбой канала не должен ломать создание запроса подписи.
     */
    protected function issueAndDeliverOtp(DocumentSignatureRequest $request, Document $document): void
    {
        try {
            $code = $this->issueOtp($request);

            $signer = $request->signer;
            if ($signer === null) {
                return;
            }

            $this->notifications->notify(
                $signer,
                'signature.otp',
                'OTP-код для подписи документа',
                'Документ «'.($document->title ?? 'без названия').'». Код подтверждения: '.$code
                    .'. Действует '.(int) app(SettingsService::class)->get('corpms.signature.otp_ttl', 5).' мин.',
                $document,
            );
        } catch (Throwable $e) {
            // доставка — best effort: запрос подписи не должен падать из-за канала
            Log::warning('signature otp delivery failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* -------------------- подписание -------------------- */

    public function sign(Employee $signer, DocumentSignatureRequest $request, ?string $otp = null, ?string $ip = null, ?string $userAgent = null): DocumentSignature
    {
        $document = $request->document;

        return DB::transaction(function () use ($signer, $request, $document, $otp, $ip, $userAgent) {
            if ($request->signer_id !== $signer->id) {
                throw new LogicException('Запрос подписи адресован другому сотруднику');
            }

            if (! in_array($request->status->value, ['pending', 'partial'], true)) {
                throw new LogicException('Запрос подписи уже закрыт ('.$request->status.')');
            }

            if (! in_array($document->status->value, ['approved', 'signing'], true)) {
                throw new LogicException('Документ не готов к подписанию (статус '.$document->status->value.')');
            }

            if ($request->requires_otp) {
                $this->verifyOtp($request, $otp);
            }

            $version = DocumentVersion::query()->findOrFail($request->document_version_id);
            $documentHash = $version->sha256;

            $signatureId = $this->nextSignatureId();
            $signatureHash = $this->computeSignatureHash($documentHash, $signer->id, $signatureId);

            $signature = DocumentSignature::create([
                'document_id' => $document->id,
                'document_version_id' => $version->id,
                'signature_request_id' => $request->id,
                'signer_id' => $signer->id,
                'signature_id' => $signatureId,
                'document_hash' => $documentHash,
                'signature_hash' => $signatureHash,
                'signature_type' => $request->driver,
                'status' => 'valid',
                'signed_at' => now(),
                'ip' => $ip ?? ($this->requestIp() ?? '0.0.0.0'),
                'user_agent' => mb_substr($userAgent ?? $this->requestUserAgent(), 0, 512),
                'session_id' => mb_substr(($this->requestSession()), 0, 64),
                'meta' => null,
            ]);

            $version->update(['immutable' => true]);
            $request->update(['status' => 'signed']);

            $this->refreshDocumentSignedStatus($document, $signer);

            $this->audit->log($signer->user, 'signature.signed', 'document', $document->id, null, [
                'signature_id' => $signatureId,
                'document_hash' => $documentHash,
            ]);

            return $signature;
        });
    }

    /** Подпись через QR (п.62): одноразовый токен, владелец = created_by, subject = запрос подписи. */
    public function signByQrToken(Employee $signer, DocumentSignatureRequest $request, string $plainToken, ?string $ip = null, ?string $userAgent = null): DocumentSignature
    {
        $token = QrToken::query()->where('token_hash', hash('sha256', $plainToken))->first();

        if ($token === null || $token->created_by !== $signer->id) {
            throw new RuntimeException('QR-токен не найден');
        }

        if ($token->revoked_at !== null) {
            throw new RuntimeException('QR-токен отозван');
        }

        if ($token->expires_at !== null && now()->gt($token->expires_at)) {
            throw new RuntimeException('QR-токен истёк');
        }

        if ($token->used_at !== null && $token->one_time) {
            throw new RuntimeException('QR-токен уже использован');
        }

        if ($token->purpose !== 'sign' || $token->subject_type !== $request::class || (int) $token->subject_id !== (int) $request->id) {
            throw new RuntimeException('QR-токен не относится к этому запросу подписи');
        }

        $signature = $this->sign($signer, $request, null, $ip, $userAgent);

        $token->forceFill(['used_at' => now()])->save();

        $signature->forceFill([
            'meta' => ['qr_token_id' => $token->id, 'driver_extra' => 'qr_approval'],
        ])->save();

        return $signature;
    }

    public function decline(Employee $signer, DocumentSignatureRequest $request, string $reason): DocumentSignatureRequest
    {
        if ($request->signer_id !== $signer->id) {
            throw new LogicException('Запрос подписи адресован другому сотруднику');
        }

        if (! in_array($request->status->value, ['pending', 'partial'], true)) {
            throw new LogicException('Запрос уже закрыт');
        }

        DB::transaction(function () use ($signer, $request, $reason) {
            $request->update(['status' => 'declined', 'decline_reason' => $reason]);

            $document = $request->document;
            $from = $document->status->value;
            // при повторном отказе документ уже на доработке — статус не меняется
            if ($this->engine->can(WorkflowEngine::SUBJECT_DOCUMENT, $from, 'revision')) {
                $document->update(['status' => 'revision']);
                $this->engine->record(WorkflowEngine::SUBJECT_DOCUMENT, $document->id, $from, 'revision', 'decline', $signer->user, $reason);
            }

            $this->audit->log($signer->user, 'signature.declined', 'document', $request->document_id, null, [
                'reason' => $reason,
            ]);
        });

        return $request;
    }

    /* -------------------- проверка/отзыв -------------------- */

    /** Верификация: хэш версии не менялся и цепочка подписи воспроизводится. */
    public function verify(DocumentSignature $signature): DocumentSignature
    {
        if ($signature->status->value === 'revoked') {
            return $signature;
        }

        $version = DocumentVersion::query()->find($signature->document_version_id);
        $versionHash = $version?->sha256;

        $chainOk = $versionHash !== null
            && hash_equals((string) $signature->document_hash, (string) $versionHash)
            && hash_equals((string) $signature->signature_hash, $this->computeSignatureHash($versionHash, $signature->signer_id, $signature->signature_id));

        $signature->update(['status' => $chainOk ? 'valid' : 'invalid']);

        if (! $chainOk) {
            $this->audit->log(null, 'signature.tampered', 'document', $signature->document_id, null, [
                'signature_id' => $signature->signature_id,
            ]);
        }

        return $signature->fresh();
    }

    public function revoke(Employee $revoker, DocumentSignature $signature, string $reason): DocumentSignature
    {
        if ($signature->status->value !== 'valid') {
            throw new LogicException('Отозвать можно только действующую подпись');
        }

        $signature->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_by' => $revoker->id,
            'meta' => array_merge($signature->meta ?? [], ['revoke_reason' => $reason]),
        ])->save();

        $this->audit->log($revoker->user, 'signature.revoked', 'document', $signature->document_id, null, [
            'signature_id' => $signature->signature_id,
            'reason' => $reason,
        ]);

        return $signature->fresh();
    }

    /* -------------------- внутренние -------------------- */

    protected function verifyOtp(DocumentSignatureRequest $request, ?string $otp): void
    {
        $meta = $request->meta ?? [];

        $stored = $meta['otp_code'] ?? null;
        $expiresAt = $meta['otp_expires_at'] ?? null;

        if ($stored === null) {
            throw new LogicException('OTP-код не выдан. Сначала запросите его');
        }

        if ($expiresAt !== null && now()->gt(Carbon::parse($expiresAt))) {
            throw new RuntimeException('OTP-код истёк');
        }

        $attempts = (int) ($meta['otp_attempts'] ?? 0) + 1;
        $request->forceFill(['meta' => array_merge($meta, ['otp_attempts' => $attempts])]);
        $request->saveQuietly();

        if ($otp === null || hash('sha256', $otp) !== $stored) {
            throw new RuntimeException('Неверный OTP-код');
        }
    }

    /**
     * Детерминированный хэш подписи.
     *
     * ВАЖНО: входят только воспроизводимые компоненты (хэш версии документа,
     * подписант, уникальный signature_id, ключ приложения). Раньше сюда попадал
     * now()->format('ymdHis'), из-за чего verify() на секунде позже считал подпись
     * недействительной — верификация всегда возвращала invalid.
     */
    protected function computeSignatureHash(string $documentHash, int $signerId, string $signatureId): string
    {
        return hash('sha256', implode('|', [
            $documentHash,
            $signerId,
            $signatureId,
            config('app.key'),
        ]));
    }

    /** Документ становится signed, когда все requests по нему подписаны. */
    protected function refreshDocumentSignedStatus(Document $document, ?Employee $signer = null): void
    {
        $pending = DocumentSignatureRequest::query()
            ->where('document_id', $document->id)
            ->where('status', 'pending')
            ->count();

        if ($pending === 0) {
            $from = $document->status->value;
            // переход к signed — только из статусов, которые его допускают
            // (например, при отказе второго подписанта документ остаётся на доработке)
            if ($this->engine->can(WorkflowEngine::SUBJECT_DOCUMENT, $from, 'signed')) {
                $document->update(['status' => 'signed']);
                $this->engine->record(WorkflowEngine::SUBJECT_DOCUMENT, $document->id, $from, 'signed', 'sign', $signer?->user, null);
            }
        }
    }

    protected function nextSignatureId(): string
    {
        $year = now()->format('Y');
        $count = DocumentSignature::query()->whereYear('signed_at', $year)->count() + 1;

        return sprintf('SIG-%s-%06d', $year, $count);
    }

    protected function requestIp(): ?string
    {
        if (app()->bound('request')) {
            $request = app('request');

            return method_exists($request, 'ip') ? $request->ip() : null;
        }

        return null;
    }

    protected function requestUserAgent(): ?string
    {
        if (app()->bound('request')) {
            $request = app('request');

            return method_exists($request, 'userAgent') ? $request->userAgent() : 'console';
        }

        return null;
    }

    protected function requestSession(): string
    {
        if (app()->bound('request')) {
            $request = app('request');

            if (method_exists($request, 'hasSession') && $request->hasSession()) {
                return (string) $request->session()->getId();
            }
        }

        return Str::uuid()->toString();
    }
}
