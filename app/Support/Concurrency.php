<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * H-BL: общая проверка «это конфликт уникального индекса?».
 *
 * Коды SQLSTATE и текст сообщения отличаются между MySQL (1062/23000) и
 * SQLite (UNIQUE constraint failed), поэтому проверяем и то, и другое.
 * Используется только для повторной попытки идемпотентной операции —
 * настоящие ошибки запроса продолжают пробрасываться как есть.
 */
final class Concurrency
{
    public static function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) $exception->errorInfo[0];
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $message = mb_strtolower($exception->getMessage());

        return $sqlState === '23000'
            && ($driverCode === '1062'
                || str_contains($message, 'unique constraint failed')
                || str_contains($message, 'duplicate entry'));
    }
}
