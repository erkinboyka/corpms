<?php

declare(strict_types=1);

namespace App\Domains\DashboardGen\Services;

use RuntimeException;
use Throwable;

/**
 * Валидация query_spec (перенос из Datavue): probe каждого запроса + проверка
 * обязательных колонок формы + проверка тенант-фильтра :company_id для
 * таблиц, у которых есть company_id.
 */
class WidgetSpecValidator
{
    public function __construct(
        private WidgetQueryRunner $runner,
        /** @var array<string, array<string, array{type: string, samples?: list<string>}>> */
        private array $schema = [],
        /** @var list<string> */
        private array $allowedTables = [],
    ) {
    }

    /** @return list<string> */
    public static function requiredColumns(string $family, ?string $type = null): array
    {
        $shape = WidgetShapeMapper::shapeFor($family);

        $columns = WidgetShapeMapper::SHAPE_COLUMNS[$shape] ?? [];

        if ($shape === WidgetShapeMapper::SHAPE_POINTS && $type === 'bubble') {
            $columns[] = 'z';
        }

        if ($shape === WidgetShapeMapper::SHAPE_COUNTERS && $type === 'with-progress') {
            $columns[] = 'percent';
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{ok: bool, errors: list<string>, columns: list<string>}
     */
    public function validate(array $spec, string $family, ?string $type = null): array
    {
        $queries = $this->queriesOf($spec);

        if ($queries === []) {
            return $this->fail('В спецификации нет ни одного запроса.');
        }

        $columns = [];

        foreach ($queries as $name => $sql) {
            $allowlist = $this->allowlistCheck($sql);
            if ($allowlist !== null) {
                return $this->fail((count($queries) > 1 ? "Запрос «{$name}»: " : '').$allowlist);
            }

            $tenant = $this->tenantCheck($sql);

            if ($tenant !== null) {
                return $this->fail(
                    (count($queries) > 1 ? "Запрос «{$name}»: " : '').$tenant
                );
            }

            try {
                $probe = $this->runner->probe($sql);
            } catch (Throwable $e) {
                return $this->fail($e->getMessage());
            }

            if (!($probe['ok'] ?? false)) {
                $error = self::cleanDatabaseError((string) $probe['error']);

                return $this->fail(
                    count($queries) > 1
                        ? "Запрос «{$name}»: ".$error
                        : $error
                );
            }

            if ($columns === [] && ($probe['columns'] ?? []) !== []) {
                $columns = array_map('strval', $probe['columns']);
            }
        }

        if ($columns === []) {
            return ['ok' => true, 'errors' => [], 'columns' => []];
        }

        $required = self::requiredColumns($family, $type);
        $missing = array_diff($required, $columns);

        if ($missing !== []) {
            return $this->fail(sprintf(
                'Запрос вернул колонки [%s], а виджету «%s» нужны [%s]. Не хватает: %s. '
                . 'Задайте имена через AS — например SELECT country AS %s.',
                implode(', ', $columns),
                $family,
                implode(', ', $required),
                implode(', ', $missing),
                reset($missing)
            ), $columns);
        }

        return ['ok' => true, 'errors' => [], 'columns' => $columns];
    }

    public static function cleanDatabaseError(string $error): string
    {
        $clean = preg_replace('/\s*\(Connection:.*$/s', '', $error) ?? $error;

        return trim($clean) !== '' ? trim($clean) : $error;
    }

    private function allowlistCheck(string $sql): ?string
    {
        if ($this->allowedTables === []) {
            return null;
        }

        $allowed = array_fill_keys(array_map('strtolower', $this->allowedTables), true);
        $blocked = ['information_schema', 'pg_catalog', 'sqlite_master', 'sqlite_temp_master', 'mysql'];

        if (preg_match_all('/\b(?:from|join|into|update|table)\s+([`"\[]?\w+[`"\]]?(?:\.[`"\[]?\w+[`"\]]?)?)/i', $sql, $m)) {
            foreach ($m[1] as $ref) {
                $ref = str_replace(['`', '"', '[', ']'], '', $ref);
                $parts = explode('.', $ref);
                $table = strtolower((string) end($parts));
                $schema = count($parts) > 1 ? strtolower($parts[0]) : null;

                if ($schema !== null && in_array($schema, $blocked, true)) {
                    return "Запрещён доступ к системной схеме «{$schema}».";
                }

                if (in_array($table, $blocked, true)) {
                    return "Запрещён доступ к системной таблице «{$table}».";
                }

                if (!isset($allowed[$table])) {
                    return "Таблица «{$table}» не входит в белый список BI-генератора.";
                }
            }
        }

        return null;
    }

    /**
     * Если запрос использует таблицу с company_id, в нём обязан быть плейсхолдер
     * :company_id — иначе данные других компаний утекут в виджет.
     */
    private function tenantCheck(string $sql): ?string
    {
        if ($this->schema === []) {
            return null;
        }

        foreach ($this->schema as $table => $columns) {
            if (!array_key_exists('company_id', $columns)) {
                continue;
            }

            if (preg_match('/\b'.preg_quote($table, '/').'\b/i', $sql) !== 1) {
                continue;
            }

            if (preg_match('/:company_id\b/', $sql) !== 1) {
                return "Таблица «{$table}» содержит company_id, но запрос её не фильтрует. "
                    . 'Добавь условие WHERE company_id = :company_id (значение подставит платформа).';
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function queriesOf(array $spec): array
    {
        $queries = $spec['queries'] ?? $spec['query'] ?? null;

        if (is_string($queries)) {
            return trim($queries) === '' ? [] : ['main' => trim($queries)];
        }

        if (!is_array($queries)) {
            return [];
        }

        $result = [];

        foreach ($queries as $name => $sql) {
            if (is_string($sql) && trim($sql) !== '') {
                $result[(string) $name] = $sql;
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $columns
     * @return array{ok: bool, errors: list<string>, columns: list<string>}
     */
    private function fail(string $error, array $columns = []): array
    {
        return ['ok' => false, 'errors' => [$error], 'columns' => $columns];
    }
}
