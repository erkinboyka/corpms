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
use App\Models\User;
use App\Support\NumberGenerator;
use App\Support\SettingsService;
use Illuminate\Database\Eloquent\Model;
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
    /** BL-02/BL-06: единый лимит для публичного QR-кода и внутреннего OTP. */
    private const OTP_MAX_ATTEMPTS = 5;

    public function __construct(
        protected AuditService $audit,
        protected WorkflowEngine $engine,
        protected NotificationService $notifications,
        protected NumberGenerator $numbers,
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
            throw new LogicException(__('Не указаны подписанты'));
        }

        $version = $document->currentVersion;
        if ($version === null) {
            throw new LogicException(__('В документе нет текущей версии'));
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
            throw new LogicException(__('Для этого запроса OTP не требуется'));
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
                __('OTP-код для подписи документа'),
                __('Документ «').($document->title ?? __('без названия')).'». Код подтверждения: '.$code
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
        // BL-02: неудачная попытка OTP должна сохраняться, даже если основная
        // транзакция подписи позже откатится. Поэтому счётчик пишется до неё.
        if ($request->requires_otp) {
            $this->verifyOtp($request, $otp, $signer);
        }

        return DB::transaction(function () use ($signer, $request, $ip, $userAgent) {
            $document = Document::query()->lockForUpdate()->findOrFail($request->document_id);
            $request = DocumentSignatureRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->signer_id !== $signer->id) {
                throw new LogicException(__('Запрос подписи адресован другому сотруднику'));
            }

            if (! in_array($request->status->value, ['pending', 'partial'], true)) {
                throw new LogicException('Запрос подписи уже закрыт ('.$request->status->value.')');
            }

            if (! in_array($document->status->value, ['approved', 'signing'], true)) {
                throw new LogicException('Документ не готов к подписанию (статус '.$document->status->value.')');
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
        // DB-13/BL-01: токен «забирается» атомарно до подписи. Все изменения —
        // в одной транзакции с sign(), поэтому ошибка подписи/аудита не оставляет
        // погашенный токен, а два конкурентных запроса не создают две подписи.
        return DB::transaction(function () use ($signer, $request, $plainToken, $ip, $userAgent) {
            $hash = hash('sha256', $plainToken);
            $claimed = QrToken::query()
                ->where('token_hash', $hash)
                ->where('created_by', $signer->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['used_at' => now()]);

            $token = QrToken::query()->where('token_hash', $hash)->first();
            if ($token === null) {
                throw new RuntimeException(__('QR-токен не найден'));
            }

            if ($claimed === 0) {
                throw new RuntimeException($token->used_at !== null
                    ? __('QR-токен уже использован')
                    : __('QR-токен отозван'));
            }

            if ($token->expires_at !== null && now()->gt($token->expires_at)) {
                throw new RuntimeException(__('QR-токен истёк'));
            }

            if ($token->purpose !== 'sign' || $token->subject_type !== $request::class || (int) $token->subject_id !== (int) $request->id) {
                throw new RuntimeException(__('QR-токен не относится к этому запросу подписи'));
            }

            $signature = $this->sign($signer, $request, null, $ip, $userAgent);

            $signature->forceFill([
                'meta' => ['qr_token_id' => $token->id, 'driver_extra' => 'qr_approval'],
            ])->save();

            return $signature;
        });
    }

    /**
     * Быстрая подпись по QR: готовит запрос на самого себя и выпускает одноразовый токен.
     *
     * Обычный запрос подписи доступен только после согласования (п.59 ТЗ), поэтому
     * здесь делается явное исключение: черновик/отклонённая версия переводится в
     * `signing` отдельным действием quick_qr_signature с записью в журнал переходов и аудит.
     *
     * @return array{0: DocumentSignatureRequest, 1: string, 2: string} запрос, открытый токен и код подтверждения
     */
    public function prepareQrSignature(Employee $signer, Document $document): array
    {
        if (in_array($document->status->value, ['signed', 'archived'], true)) {
            throw new LogicException(__('Документ уже подписан'));
        }

        $version = $document->currentVersion;
        if ($version === null) {
            throw new LogicException(__('В документе нет текущей версии'));
        }

        return DB::transaction(function () use ($signer, $document, $version) {
            // Переиспользуем уже ожидающий запрос этого подписанта, чтобы не
            // плодить дубли. Код подтверждения обязателен даже при QR-подписи.
            $request = $document->signatureRequests()
                ->where('signer_id', $signer->id)
                ->whereIn('status', ['pending', 'partial'])
                ->latest()
                ->first();

            if ($request === null) {
                $request = DocumentSignatureRequest::create([
                    'document_id' => $document->id,
                    'document_version_id' => $version->id,
                    'driver' => 'qr_approval',
                    'requested_by' => $signer->id,
                    'signer_id' => $signer->id,
                    'order_index' => (int) $document->signatureRequests()->max('order_index') + 1,
                    'requires_otp' => false,
                    'status' => 'pending',
                ]);
            } else {
                $request->update(['requires_otp' => false, 'driver' => 'qr_approval']);
            }

            $from = $document->status->value;
            $this->engine->assertQuickQrSignature($from);
            if (! in_array($from, ['approved', 'signing'], true)) {
                $document->update(['status' => 'signing']);
                $this->engine->record(
                    WorkflowEngine::SUBJECT_DOCUMENT,
                    $document->id,
                    $from,
                    'signing',
                    'quick_qr_signature',
                    $signer->user,
                    __('Быстрая подпись по QR'),
                );
            }

            // Старые неиспользованные QR этого запроса больше не действуют:
            // в любой момент валиден только последний выданный код.
            QrToken::query()
                ->where('purpose', 'sign')
                ->where('subject_type', $request::class)
                ->where('subject_id', $request->id)
                ->where('created_by', $signer->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $plain = Str::random(48);
            $ttlHours = max(1, (int) app(SettingsService::class)->get('corpms.signature.qr_token_ttl_hours', 24));
            $code = (string) random_int(100000, 999999);
            $codeTtl = max(1, (int) app(SettingsService::class)->get('corpms.signature.otp_ttl', 5));

            QrToken::create([
                'token_hash' => hash('sha256', $plain),
                'subject_type' => $request::class,
                'subject_id' => $request->id,
                'purpose' => 'sign',
                'one_time' => true,
                'expires_at' => now()->addHours($ttlHours),
                'created_by' => $signer->id,
                'meta' => [
                    'document_id' => $document->id,
                    'driver' => 'qr_approval',
                    'otp_code' => hash('sha256', $code),
                    'otp_expires_at' => now()->addMinutes($codeTtl)->toDateTimeString(),
                    'otp_attempts' => 0,
                ],
            ]);

            // Код уходит подписанту в уведомление; без него подпись невозможна.
            try {
                $this->notifications->notify(
                    $signer,
                    'signature.otp',
                    __('Код подтверждения для подписи документа'),
                    __('Документ «').($document->title ?? __('без названия')).'». '
                        .__('Код подтверждения').': '.$code,
                    $document,
                );
            } catch (Throwable $e) {
                Log::warning('signature qr code delivery failed', ['error' => $e->getMessage(), 'document_id' => $document->id]);
            }

            $this->audit->log($signer->user, 'signature.qr_prepared', 'document', $document->id, null, [
                'signature_request_id' => $request->id,
                'ttl_hours' => $ttlHours,
                'code_ttl_minutes' => $codeTtl,
            ]);

            return [$request, $plain, $code];
        });
    }

    /**
     * Проверяет токен для показа экрана подписи (не расходует его).
     *
     * @return array{token: QrToken, signer: Employee, request: DocumentSignatureRequest, document: Document}|null
     */
    public function resolveQrSignToken(string $plainToken): ?array
    {
        if ($plainToken === '') {
            return null;
        }

        $token = QrToken::query()->where('token_hash', hash('sha256', $plainToken))->first();
        if ($token === null
            || $token->purpose !== 'sign'
            || $token->subject_type !== DocumentSignatureRequest::class
            || $token->revoked_at !== null
            || ($token->expires_at !== null && now()->gt($token->expires_at))
            || ($token->one_time && $token->used_at !== null)
        ) {
            return null;
        }

        $signer = $token->createdBy;
        if ($signer === null || $signer->status->value !== 'active') {
            return null;
        }

        $request = DocumentSignatureRequest::query()->find($token->subject_id);
        if ($request === null || $request->signer_id !== $signer->id) {
            return null;
        }

        $document = Document::query()->find($request->document_id);
        if ($document === null || (int) $document->company_id !== (int) $signer->company_id) {
            return null;
        }

        return ['token' => $token, 'signer' => $signer, 'request' => $request, 'document' => $document];
    }

    /**
     * Подпись по токену из QR без входа в систему: владелец токена — подписант.
     * Обязательно требует код, отправленный подписанту при генерации QR.
     */
    public function signWithQrToken(string $plainToken, ?string $code = null, ?string $ip = null, ?string $userAgent = null): DocumentSignature
    {
        $resolved = $this->resolveQrSignToken($plainToken);
        if ($resolved === null) {
            throw new RuntimeException(__('QR-код подписи недействителен или истёк'));
        }

        $this->verifyQrCode($resolved['token'], $code, $resolved['signer']);

        return $this->signByQrToken($resolved['signer'], $resolved['request'], $plainToken, $ip, $userAgent);
    }

    /** Проверка отправленного кода подтверждения (BL-02: 5 попыток, атомарно). */
    protected function verifyQrCode(QrToken $token, ?string $code, ?Employee $signer = null): void
    {
        $meta = $token->meta ?? [];
        $stored = $meta['otp_code'] ?? null;
        if ($stored === null) {
            throw new RuntimeException(__('Код подтверждения не выдан. Сгенерируйте новый QR-код'));
        }

        $expiresAt = $meta['otp_expires_at'] ?? null;
        if ($expiresAt !== null && now()->gt(Carbon::parse($expiresAt))) {
            throw new RuntimeException(__('Код подтверждения истёк. Сгенерируйте новый QR-код'));
        }

        [, $attempts, $blocked] = $this->recordOtpAttempt(
            $token,
            $signer?->user,
            'qr',
            ['revoked_at' => now()],
        );

        $valid = $code !== null && hash_equals((string) $stored, hash('sha256', $code));
        if (! $valid) {
            $this->audit->log($signer?->user, 'signature.otp_failed', class_basename($token), $token->id, null, [
                'context' => 'qr',
                'attempts' => $attempts,
            ]);
        }

        if ($blocked) {
            throw new RuntimeException(__('Слишком много попыток. Сгенерируйте новый QR-код'));
        }

        if (! $valid) {
            throw new RuntimeException(__('Неверный код подтверждения'));
        }
    }

    public function decline(Employee $signer, DocumentSignatureRequest $request, string $reason): DocumentSignatureRequest
    {
        if ($request->signer_id !== $signer->id) {
            throw new LogicException(__('Запрос подписи адресован другому сотруднику'));
        }

        if (! in_array($request->status->value, ['pending', 'partial'], true)) {
            throw new LogicException(__('Запрос уже закрыт'));
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
            throw new LogicException(__('Отозвать можно только действующую подпись'));
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

    protected function verifyOtp(DocumentSignatureRequest $request, ?string $otp, Employee $signer): void
    {
        $meta = $request->meta ?? [];

        $stored = $meta['otp_code'] ?? null;
        $expiresAt = $meta['otp_expires_at'] ?? null;

        if ($stored === null) {
            throw new LogicException(__('OTP-код не выдан. Сначала запросите его'));
        }

        if ($expiresAt !== null && now()->gt(Carbon::parse($expiresAt))) {
            throw new RuntimeException(__('OTP-код истёк'));
        }

        [, $attempts, $blocked] = $this->recordOtpAttempt($request, $signer->user, 'internal');

        $valid = $otp !== null && hash_equals((string) $stored, hash('sha256', $otp));
        if (! $valid) {
            $this->audit->log($signer->user, 'signature.otp_failed', class_basename($request), $request->id, null, [
                'context' => 'internal',
                'attempts' => $attempts,
            ]);
        }

        if ($blocked) {
            throw new RuntimeException(__('Слишком много попыток. Запрос подписи заблокирован'));
        }

        if (! $valid) {
            throw new RuntimeException(__('Неверный OTP-код'));
        }
    }

    /**
     * BL-02/BL-06: атомарный инкремент попыток под row-level блокировкой.
     * Счётчик фиксируется в отдельной транзакции, чтобы неверный код оставался
     * учтённым даже при откате транзакции подписи. Пятая попытка блокирует объект.
     *
     * @param  array<string, mixed>  $blockAttributes  дополнительные поля модели при блокировке (например, revoked_at)
     * @return array{0: array<string, mixed>, 1: int, 2: bool} meta, число попыток, признак блокировки
     */
    protected function recordOtpAttempt(
        Model $model,
        ?User $user,
        string $context,
        array $blockAttributes = [],
    ): array {
        return DB::transaction(function () use ($model, $user, $context, $blockAttributes) {
            $locked = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
            $meta = $locked->meta ?? [];
            $attempts = (int) ($meta['otp_attempts'] ?? 0);

            if (isset($meta['otp_blocked_at'])) {
                return [$meta, $attempts, true];
            }

            $attempts++;
            $meta['otp_attempts'] = $attempts;
            $blocked = $attempts >= self::OTP_MAX_ATTEMPTS;
            if ($blocked) {
                $meta['otp_blocked_at'] = now()->toDateTimeString();
            }

            $locked->forceFill(array_merge(['meta' => $meta], $blocked ? $blockAttributes : []))
                ->saveQuietly();

            if ($blocked) {
                $this->audit->log($user, 'signature.otp_blocked', class_basename($model), $model->getKey(), null, [
                    'context' => $context,
                    'attempts' => $attempts,
                ]);
            }

            return [$meta, $attempts, $blocked];
        });
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
        $prefix = 'SIG-'.$year.'-';

        $value = $this->numbers->next('signature.id', $year, fn () => $this->numbers->maxSuffix(
            $prefix,
            DocumentSignature::query()
                ->where('signature_id', 'like', $prefix.'%')
                ->pluck('signature_id'),
        ));

        return sprintf('%s%06d', $prefix, $value);
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
