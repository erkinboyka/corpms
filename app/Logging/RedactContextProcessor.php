<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * TEST-10: не допускаем ПДн и секретов в context/extra логов.
 *
 * Сообщение (message) формируется из статических шаблонов без данных; здесь
 * вычищаются структурированные данные. Идентификаторы заменяются на [REDACTED],
 * корреляционный correlation_id (если есть) сохраняется как есть.
 */
final class RedactContextProcessor implements ProcessorInterface
{
    private const MASK = '[REDACTED]';

    /** @var array<int, string> */
    private const EXACT_KEYS = [
        'employeeid', 'userid', 'authorid', 'recipientid', 'companyid',
        'email', 'phone', 'passport', 'snils', 'inn', 'fio',
        'firstname', 'lastname', 'middlename', 'birthdate', 'address',
        'ip', 'ipaddress', 'clientip', 'useragent',
        'question', 'answer', 'body', 'payload', 'raw', 'prompt',
        'documentbody', 'note', 'comment', 'bio', 'fingerprint',
    ];

    /** @var array<int, string> */
    private const KEY_FRAGMENTS = [
        'password', 'token', 'secret', 'authorization', 'cookie',
        'apikey', 'credential', 'sessionid', 'webhook', 'bottoken',
        'privatekey', 'passphrase', 'signaturekey', 'otp', 'code',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->clean($record->context),
            extra: $this->clean($record->extra),
        );
    }

    private function clean(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return self::MASK;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $key));
                $out[$key] = $this->isSensitive($normalized)
                    ? self::MASK
                    : $this->clean($item, $depth + 1);
            }

            return $out;
        }

        if (is_string($value) && preg_match('/(?:^|\s)eyJ[A-Za-z0-9_-]{10,}/', $value) === 1) {
            return self::MASK;
        }

        return $value;
    }

    private function isSensitive(string $key): bool
    {
        if (in_array($key, self::EXACT_KEYS, true)) {
            return true;
        }

        foreach (self::KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
