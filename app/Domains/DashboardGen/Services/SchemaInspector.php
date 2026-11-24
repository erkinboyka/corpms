<?php

declare(strict_types=1);

namespace App\Domains\DashboardGen\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Схема белого списка таблиц для генерации (адаптация Datavue под corpms).
 *
 * Возвращает колонки с типами и выборками sample_values — по ним модель
 * пишет WHERE со слово-в-слово значениями, не выдумывая статусы. Работает
 * и на MySQL (прод), и на SQLite (тесты).
 */
class SchemaInspector
{
    private ConnectionInterface $connection;

    public function __construct(?ConnectionInterface $connection = null)
    {
        $this->connection = $connection ?? DB::connection();
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, array<string, array{type: string, samples?: list<string>}>>
     */
    public function schema(array $tables): array
    {
        $existing = $this->existingTables();
        $budget = max(0, (int) config('dashboard_gen.max_sample_queries', 80));
        $limit = max(1, (int) config('dashboard_gen.sample_limit', 5));

        $schema = [];

        foreach ($tables as $table) {
            if (!is_string($table) || !in_array($table, $existing, true)) {
                continue;
            }

            $columns = $this->columnsOf($table);

            if ($columns === []) {
                continue;
            }

            foreach ($columns as $name => $type) {
                $entry = ['type' => $type];

                if ($budget > 0 && $this->isTextual($type)) {
                    $samples = $this->samples($table, $name, $limit);

                    if ($samples !== []) {
                        $entry['samples'] = $samples;
                    }

                    // бюджет тратится на каждую выполненную выборку
                    $budget--;
                }

                $schema[$table][$name] = $entry;
            }
        }

        return $schema;
    }

    /**
     * Компактный текст схемы для промпта генератора плана.
     *
     * @param  array<string, array<string, array{type: string, samples?: list<string>}>>  $schema
     */
    public function describe(array $schema): string
    {
        $lines = [];

        foreach ($schema as $table => $columns) {
            $parts = [];

            foreach ($columns as $name => $meta) {
                $line = "{$name} ({$meta['type']})";

                if (!empty($meta['samples'])) {
                    $line .= ' — значения: '.implode(', ', array_map(
                        fn (string $value): string => '«'.$value.'»',
                        $meta['samples']
                    ));
                }

                $parts[] = $line;
            }

            $lines[] = "{$table}: ".implode(', ', $parts);
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function existingTables(): array
    {
        try {
            return array_values(array_map(
                static fn (array $table): string => (string) ($table['name'] ?? ''),
                Schema::getTables()
            ));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private function columnsOf(string $table): array
    {
        try {
            $columns = $this->connection->getSchemaBuilder()->getColumns($table);
        } catch (Throwable) {
            return [];
        }

        $result = [];

        foreach ($columns as $column) {
            $name = (string) ($column['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $type = (string) ($column['type_name'] ?? $column['type'] ?? 'unknown');
            $result[$name] = $type;
        }

        return $result;
    }

    private function isTextual(string $type): bool
    {
        $type = strtolower($type);

        return str_contains($type, 'char')
            || str_contains($type, 'text')
            || str_contains($type, 'enum')
            || str_contains($type, 'string');
    }

    /**
     * Выборка значений колонки: сканируется ограниченный кусок таблицы,
     * поэтому запрос дешёвый даже на больших данных.
     *
     * @return list<string>
     */
    private function samples(string $table, string $column, int $limit): array
    {
        $sourceRows = max(100, (int) config('dashboard_gen.sample_source_rows', 1000));
        $q = fn (string $identifier): string => '`'.str_replace('`', '``', $identifier).'`';

        $sql = 'SELECT DISTINCT '.$q($column).' AS v FROM ('
            .'SELECT '.$q($column).' FROM '.$q($table).' LIMIT '.$sourceRows
            .') AS sample_source'
            .' WHERE '.$q($column).' IS NOT NULL AND '.$q($column)." <> ''"
            .' ORDER BY v LIMIT '.$limit;

        try {
            $rows = $this->connection->select($sql);
        } catch (Throwable) {
            return [];
        }

        $values = [];

        foreach ($rows as $row) {
            $value = is_object($row) ? ($row->v ?? null) : ($row['v'] ?? null);

            if ($value !== null && $value !== '') {
                $values[] = (string) $value;
            }
        }

        return $values;
    }
}
