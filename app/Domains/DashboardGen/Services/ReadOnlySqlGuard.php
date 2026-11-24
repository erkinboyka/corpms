<?php

declare(strict_types=1);

namespace App\Domains\DashboardGen\Services;

use RuntimeException;

/**
 * Read-only guard для SQL виджетов (перенос из Datavue).
 *
 * Разрешает только один SELECT/WITH без ";", снимает комментарии, запрещает
 * мутирующие ключевые слова и при необходимости добавляет LIMIT.
 */
class ReadOnlySqlGuard
{
    private const FORBIDDEN_KEYWORDS = [
        'insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate',
        'replace', 'grant', 'revoke', 'attach', 'detach', 'copy', 'export',
        'import', 'install', 'load', 'call', 'merge', 'upsert', 'vacuum',
        'pragma', 'set', 'reset', 'begin', 'commit', 'rollback',
        'outfile', 'dumpfile', 'infile', 'load_file',
    ];

    public static function sanitize(string $sql, ?int $maxRows): string
    {
        $clean = trim($sql);

        $clean = preg_replace('/--[^\n]*/', ' ', $clean);
        $clean = preg_replace('/#[^\n]*/', ' ', $clean);
        $clean = preg_replace('/\/\*.*?\*\//s', ' ', $clean);
        $clean = trim($clean);
        $clean = rtrim($clean, "; \t\n\r");

        if ($clean === '') {
            throw new RuntimeException('Пустой SQL-запрос.');
        }

        if (str_contains($clean, ';')) {
            throw new RuntimeException('Разрешён только один SQL-запрос без ";".');
        }

        if (preg_match('/\binto\s+(out|dump)file\b/i', $clean) === 1) {
            throw new RuntimeException('SQL содержит запрещённую конструкцию INTO OUTFILE/DUMPFILE.');
        }

        if (preg_match('/\bload_file\s*\(/i', $clean) === 1) {
            throw new RuntimeException('SQL содержит запрещённую функцию LOAD_FILE.');
        }

        if (!preg_match('/^\s*(select|with)\b/i', $clean)) {
            throw new RuntimeException('Разрешены только запросы SELECT/WITH.');
        }

        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $clean)) {
                throw new RuntimeException("Запрещённая операция в запросе: {$keyword}.");
            }
        }

        if ($maxRows !== null && $maxRows > 0) {
            if (preg_match('/\blimit\s+(\d+)/i', $clean, $m)) {
                $current = (int) $m[1];
                if ($current > $maxRows) {
                    $clean = preg_replace('/\blimit\s+\d+/i', 'LIMIT '.$maxRows, $clean, 1) ?? $clean;
                }
            } else {
                $clean .= ' LIMIT '.$maxRows;
            }
        }

        return $clean;
    }

    public static function stripTrailingLimit(string $sql): string
    {
        return preg_replace('/\s+limit\s+\d+(\s+offset\s+\d+)?\s*$/i', '', trim($sql)) ?? $sql;
    }

    public static function normalizeRows(mixed $rows): array
    {
        if ($rows === null) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            if (is_object($row)) {
                $result[] = get_object_vars($row);
                continue;
            }

            $result[] = is_array($row) ? $row : [$row];
        }

        return $result;
    }
}
