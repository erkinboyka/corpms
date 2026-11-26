<?php

declare(strict_types=1);

namespace App\Domains\DashboardGen\QuerySpec;

use RuntimeException;

/**
 * Структурированная спецификация запроса BI-виджета (аналог Velora QuerySpec).
 *
 * Модель описывает не SQL, а намерение: from/joins/select/where/group_by/
 * order_by/limit. Имена таблиц и колонок проверяются по белому списку схемы,
 * значения уходят в параметры. Строковый запрос из старых спецификаций
 * допускается только для обратной совместимости и всё равно проходит
 * ReadOnlySqlGuard в WidgetQueryRunner.
 */
final class BiQuerySpec
{
    public const EXPR_COLUMN = 'column';
    public const EXPR_COUNT = 'count';
    public const EXPR_SUM = 'sum';
    public const EXPR_AVG = 'avg';
    public const EXPR_MIN = 'min';
    public const EXPR_MAX = 'max';
    public const EXPR_MONTH = 'month';
    public const EXPR_YEAR = 'year';
    public const EXPR_DATE = 'date';
    public const EXPR_LITERAL = 'literal';

    public const OPERATORS = [
        '=', '!=', '<>', '>', '>=', '<', '<=',
        'like', 'not like', 'in', 'not in', 'is null', 'is not null',
    ];

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, array<string, array{type: string, samples?: list<string>}>>  $schema
     */
    public function __construct(
        public readonly array $raw,
        private readonly array $schema,
        private readonly string $driver = 'mysql',
    ) {}

    public function driver(): string
    {
        return $this->driver === 'sqlite' ? 'sqlite' : 'mysql';
    }

    public function isStructured(): bool
    {
        foreach ($this->queries() as $query) {
            if (is_array($query)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<string, mixed>|string>
     */
    public function queries(): array
    {
        $source = $this->raw['queries'] ?? $this->raw['query'] ?? null;

        if (is_string($source) && trim($source) !== '') {
            return ['main' => trim($source)];
        }

        if (! is_array($source)) {
            return [];
        }

        $queries = [];

        foreach ($source as $name => $query) {
            if (is_string($query) && trim($query) !== '') {
                $queries[(string) $name] = trim($query);
                continue;
            }

            if (is_array($query)) {
                $queries[(string) $name] = $this->normalizeQuery($query);
            }
        }

        return $queries;
    }

    public function validate(): void
    {
        foreach ($this->queries() as $name => $query) {
            if (is_string($query)) {
                continue;
            }

            $this->validateQuery($query, (string) $name);
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{from: ?string, alias: string, joins: list<array<string, mixed>>, select: list<array<string, mixed>>, where: list<array<string, mixed>>, group_by: list<mixed>, order_by: list<array<string, mixed>>, limit: ?int}
     */
    public function normalizeQuery(array $query): array
    {
        $from = $query['from'] ?? $query['table'] ?? null;
        $from = is_string($from) ? trim($from) : '';
        $alias = $query['alias'] ?? ($from !== '' ? $from : null);
        $alias = is_string($alias) && trim($alias) !== '' ? trim($alias) : $from;

        $joins = [];
        foreach ((array) ($query['joins'] ?? []) as $join) {
            if (! is_array($join)) {
                continue;
            }

            $joins[] = [
                'table' => is_string($join['table'] ?? null) ? trim((string) $join['table']) : '',
                'alias' => is_string($join['alias'] ?? null) && trim((string) $join['alias']) !== ''
                    ? trim((string) $join['alias'])
                    : (is_string($join['table'] ?? null) ? trim((string) $join['table']) : ''),
                'type' => strtolower((string) ($join['type'] ?? 'left')) === 'inner' ? 'inner' : 'left',
                'on' => is_array($join['on'] ?? null) ? $join['on'] : [],
            ];
        }

        $select = [];
        foreach ((array) ($query['select'] ?? $query['metrics'] ?? []) as $item) {
            if (is_array($item)) {
                $select[] = $item;
            }
        }

        $where = [];
        foreach ((array) ($query['where'] ?? $query['filters'] ?? []) as $condition) {
            if (is_array($condition)) {
                $where[] = $condition;
            }
        }

        $orderBy = [];
        foreach ((array) ($query['order_by'] ?? []) as $order) {
            if (is_string($order)) {
                $orderBy[] = ['column' => $order, 'dir' => 'asc'];
                continue;
            }

            if (is_array($order)) {
                $orderBy[] = [
                    'column' => $order['column'] ?? $order['field'] ?? $order['alias'] ?? null,
                    'dir' => strtolower((string) ($order['dir'] ?? $order['direction'] ?? 'asc')),
                ];
            }
        }

        $limit = $query['limit'] ?? null;
        $limit = is_numeric($limit) ? (int) $limit : null;

        return [
            'from' => $from,
            'alias' => (string) $alias,
            'joins' => $joins,
            'select' => $select,
            'where' => $where,
            'group_by' => array_values((array) ($query['group_by'] ?? [])),
            'order_by' => $orderBy,
            'limit' => $limit,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, string> alias => table
     */
    public function aliasesFor(array $query): array
    {
        $aliases = [];

        if ($query['from'] !== null && $query['from'] !== '') {
            $aliases[strtolower((string) $query['alias'])] = (string) $query['from'];
        }

        foreach ($query['joins'] as $join) {
            if ($join['table'] === '') {
                continue;
            }

            $aliases[strtolower((string) $join['alias'])] = (string) $join['table'];
        }

        return $aliases;
    }

    /**
     * @param  array<string, string>  $aliases
     * @return array{0: string, 1: string} [alias, column]
     */
    public function resolveColumn(mixed $ref, array $aliases, string $context = ''): array
    {
        $prefix = $context !== '' ? $context.': ' : '';

        if (! is_string($ref) || trim($ref) === '') {
            throw new RuntimeException($prefix.'Не указана колонка.');
        }

        $ref = trim($ref);
        $parts = explode('.', $ref, 2);

        if (count($parts) === 2) {
            $alias = strtolower(trim($parts[0]));
            $column = trim($parts[1]);
        } else {
            if (count($aliases) !== 1) {
                throw new RuntimeException($prefix.'У колонки «'.$ref.'» нет псевдонима таблицы (например, c.name).');
            }

            $alias = array_key_first($aliases);
            $column = $ref;
        }

        $column = trim($column, '`"[]');

        if (! isset($aliases[$alias])) {
            throw new RuntimeException($prefix.'Неизвестный псевдоним «'.$alias.'».');
        }

        $table = $aliases[$alias];

        if (! isset($this->schema[$table]) || ! array_key_exists($column, $this->schema[$table])) {
            throw new RuntimeException($prefix.'Колонка «'.$alias.'.'.$column.'» отсутствует в схеме.');
        }

        return [$alias, $column];
    }

    /** @param array<string, mixed> $query */
    private function validateQuery(array $query, string $name): void
    {
        $prefix = "Запрос «{$name}»";

        if ($query['from'] === null || $query['from'] === '' || ! isset($this->schema[$query['from']])) {
            throw new RuntimeException($prefix.': таблица «'.(string) $query['from'].'» не входит в белый список.');
        }

        $this->assertAlias($query['alias'], $prefix);
        $aliases = $this->aliasesFor($query);

        foreach ($query['joins'] as $join) {
            if ($join['table'] === '' || ! isset($this->schema[$join['table']])) {
                throw new RuntimeException($prefix.': таблица JOIN «'.(string) $join['table'].'» не входит в белый список.');
            }

            $this->assertAlias($join['alias'], $prefix);

            if (isset($aliases[strtolower((string) $join['alias'])])) {
                throw new RuntimeException($prefix.': псевдоним «'.$join['alias'].'» уже используется.');
            }

            $left = $join['on']['left'] ?? null;
            $right = $join['on']['right'] ?? null;
            $this->resolveColumn($left, $aliases, $prefix.' JOIN');
            $this->resolveColumn($right, $aliases, $prefix.' JOIN');
            $aliases[strtolower((string) $join['alias'])] = (string) $join['table'];
        }

        if ($query['select'] === []) {
            throw new RuntimeException($prefix.': нет колонок в select.');
        }

        $selectAliases = [];

        foreach ($query['select'] as $item) {
            $expr = strtolower((string) ($item['expr'] ?? $item['type'] ?? self::EXPR_COLUMN));
            $as = $item['as'] ?? $item['alias'] ?? null;
            $this->assertAlias($as, $prefix.' select');
            $as = (string) $as;

            if (isset($selectAliases[strtolower($as)])) {
                throw new RuntimeException($prefix.': псевдоним результата «'.$as.'» повторяется.');
            }
            $selectAliases[strtolower($as)] = true;

            if (! in_array($expr, [
                self::EXPR_COLUMN, self::EXPR_COUNT, self::EXPR_SUM, self::EXPR_AVG,
                self::EXPR_MIN, self::EXPR_MAX, self::EXPR_MONTH, self::EXPR_YEAR,
                self::EXPR_DATE, self::EXPR_LITERAL,
            ], true)) {
                throw new RuntimeException($prefix.': неизвестное выражение «'.$expr.'».');
            }

            if ($expr === self::EXPR_LITERAL) {
                continue;
            }

            if ($expr === self::EXPR_COUNT && ($item['column'] ?? '*') === '*') {
                continue;
            }

            $this->resolveColumn($item['column'] ?? null, $aliases, $prefix.' select');
        }

        foreach ($query['where'] as $condition) {
            $op = strtolower(trim((string) ($condition['op'] ?? $condition['operator'] ?? '=')));

            if (! in_array($op, self::OPERATORS, true)) {
                throw new RuntimeException($prefix.': неизвестная операция «'.$op.'» в фильтре.');
            }

            $this->resolveColumn($condition['column'] ?? null, $aliases, $prefix.' where');

            if (in_array($op, ['is null', 'is not null'], true)) {
                continue;
            }

            if (in_array($op, ['in', 'not in'], true)) {
                if (! is_array($condition['value'] ?? null) || ($condition['value'] ?? []) === []) {
                    throw new RuntimeException($prefix.': операция IN требует непустой список значений.');
                }

                continue;
            }

            if (! array_key_exists('value', $condition)) {
                throw new RuntimeException($prefix.': в фильтре нет значения.');
            }
        }

        foreach ($query['group_by'] as $field) {
            if (is_string($field) && isset($selectAliases[strtolower($field)])) {
                continue;
            }

            $this->resolveColumn($field, $aliases, $prefix.' group_by');
        }

        foreach ($query['order_by'] as $order) {
            if (! is_string($order['column'] ?? null) || trim((string) $order['column']) === '') {
                throw new RuntimeException($prefix.': в order_by не указано поле.');
            }

            if (! in_array($order['dir'], ['asc', 'desc'], true)) {
                throw new RuntimeException($prefix.': направление сортировки должно быть asc или desc.');
            }

            if (isset($selectAliases[strtolower((string) $order['column'])])) {
                continue;
            }

            $this->resolveColumn($order['column'], $aliases, $prefix.' order_by');
        }

        if ($query['limit'] !== null && ($query['limit'] < 1 || $query['limit'] > 5000)) {
            throw new RuntimeException($prefix.': limit должен быть от 1 до 5000.');
        }
    }

    private function assertAlias(mixed $alias, string $context): void
    {
        if (! is_string($alias) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new RuntimeException($context.': некорректный псевдоним «'.(string) $alias.'».');
        }
    }
}
