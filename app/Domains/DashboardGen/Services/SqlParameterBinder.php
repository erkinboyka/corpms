<?php

declare(strict_types=1);

namespace App\Domains\DashboardGen\Services;

use RuntimeException;

/**
 * Подстановка именованных плейсхолдеров (:name) в позиционные PDO-биндинги
 * (перенос из Datavue). Строковые литералы в запросе не трогаются.
 */
class SqlParameterBinder
{
    public const TYPE_DATE = 'date';
    public const TYPE_INT = 'int';
    public const TYPE_FLOAT = 'float';
    public const TYPE_STRING = 'string';

    public function apply(string $sql, array $values, array $types = []): array
    {
        $bindings = [];

        $replace = function (string $chunk) use ($values, $types, &$bindings): string {
            return preg_replace_callback(
                '/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/',
                function (array $match) use ($values, $types, &$bindings): string {
                    $name = $match[1];

                    if (!array_key_exists($name, $values)) {
                        return $match[0];
                    }

                    $value = $this->cast($values[$name], $types[$name] ?? self::TYPE_STRING);

                    $bindings[] = $value;

                    return '?';
                },
                $chunk
            );
        };

        $parts = preg_split(
            "/('(?:\\\\.|''|[^'\\\\])*')/",
            $sql,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ($parts === false) {
            return ['sql' => $replace($sql), 'bindings' => $bindings];
        }

        $result = '';

        foreach ($parts as $index => $part) {
            $result .= $index % 2 === 1 ? $part : $replace($part);
        }

        return ['sql' => $result, 'bindings' => $bindings];
    }

    public function cast(mixed $value, string $type): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            self::TYPE_DATE => $this->castDate($value),
            self::TYPE_INT => (int) $value,
            self::TYPE_FLOAT => (float) $value,
            default => (string) $value,
        };
    }

    private function castDate(mixed $value): string
    {
        $value = trim((string) $value);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}(\s\d{2}:\d{2}(:\d{2})?)?$/', $value)) {
            throw new RuntimeException("Некорректная дата: «{$value}». Ожидается ГГГГ-ММ-ДД.");
        }

        return $value;
    }
}
