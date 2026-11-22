<?php

declare(strict_types=1);

namespace App\Domains\Audit\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

/**
 * Централизованный аудит (п. 60 ТЗ): все значимые действия пишутся в audit_logs.
 * Вызывается из сервисов; при недоступности request (queue/console) — пишем без ip/ua.
 */
class AuditService
{
    /** Запись события аудита. */
    public function log(
        ?User $user,
        string $action,
        string $objectType,
        ?int $objectId = null,
        mixed $oldValue = null,
        mixed $newValue = null,
        ?string $sessionId = null,
    ): AuditLog {
        $entry = new AuditLog([
            'user_id' => $user?->id,
            // DB-S3: tenant-ключ пишется сразу, чтобы фильтр дашборда и
            // аналитики не требовал join с users/employees на каждый запрос.
            'company_id' => $user?->employee?->company_id,
            'action' => $action,
            'old_value' => $this->normalize($oldValue),
            'new_value' => $this->normalize($newValue),
            'ip' => $this->currentIp(),
            'user_agent' => $this->currentUserAgent(),
            'session_id' => $sessionId ?? $this->currentSessionId(),
            'created_at' => now(),
        ]);

        // morph-поля проставляем вручную, чтобы не тянуть relation через create
        $entry->setAttribute('object_type', $objectType);
        $entry->setAttribute('object_id', $objectId);
        $entry->save();

        return $entry;
    }

    /** Запись для конкретной модели-объекта (удобнее: сам подставляет type/id). */
    public function logModel(
        ?User $user,
        string $action,
        object $object,
        mixed $oldValue = null,
        mixed $newValue = null,
    ): AuditLog {
        return $this->log(
            $user,
            $action,
            class_basename($object),
            $object->getKey(),
            $oldValue,
            $newValue,
        );
    }

    protected function normalize(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $this->maskSensitive($value);
        }

        if ($value instanceof Throwable) {
            return ['message' => $value->getMessage()];
        }

        return ['value' => $value];
    }

    /**
     * Маскировка секретных значений в структуре аудита (защита в глубину):
     * поля с именами password/secret/token/api_key и подобными пишутся как «••••••»,
     * чтобы журнал оставался безопасным даже при ошибке вызывающего кода.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    protected function maskSensitive(array $data): array
    {
        $result = [];

        foreach ($data as $key => $item) {
            if (is_string($key) && preg_match('/password|passwd|secret|token|api[_-]?key|private[_-]?key|credential|otp/iu', $key) === 1) {
                $result[$key] = $item === null || $item === '' ? $item : '••••••';

                continue;
            }

            $result[$key] = is_array($item) ? $this->maskSensitive($item) : $item;
        }

        return $result;
    }

    protected function currentRequest(): ?Request
    {
        if (app()->bound('request')) {
            $request = app('request');

            if ($request instanceof Request) {
                return $request;
            }
        }

        return null;
    }

    protected function currentIp(): ?string
    {
        return $this->currentRequest()?->ip();
    }

    protected function currentUserAgent(): ?string
    {
        $ua = $this->currentRequest()?->userAgent();

        return $ua !== null ? mb_substr($ua, 0, 512) : null;
    }

    protected function currentSessionId(): ?string
    {
        $request = $this->currentRequest();

        if ($request === null || $request->hasSession() === false) {
            return null;
        }

        return mb_substr((string) $request->session()->getId(), 0, 64);
    }
}
