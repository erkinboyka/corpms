<?php

declare(strict_types=1);

namespace App\Domains\DashboardGen\QuerySpec;

use RuntimeException;

/**
 * Компилятор BiQuerySpec → параметризованный SELECT.
 *
 * Идентификаторы берутся только из проверенной схемы и экранируются под
 * диалект БД. Все значения (кроме :company_id) уходят в именованные биндинги.
 * Полученный SQL всё равно проходит ReadOnlySqlGuard в WidgetQueryRunner —
 * второй барьер на случай ошибки компилятора.
 */
final class BiQueryCompiler
{
    /**
     * @param  array<string, array<string, array{type: string, samples?: list<string>}>>  $schema
     */
    public function __construct(private readonly array $schema) {}

    /**
     * @return array{queries: array<string, string>, bindings: array<string, mixed>, compiler: string}
     */
    public function compile(BiQuerySpec $spec): array
    {
        $spec->validate();

        $queries = [];
        $bindings = [];
        $counter = 0;

        foreach ($spec->queries() as $name => $query) {
            if (is_string($query)) {
                $queries[$name] = $query;
                continue;
            }

            $queries[$name] = $this->compileQuery($spec, $query, $bindings, $counter);
        }

        return [
            'queries' => $queries,
            'bindings' => $bindings,
            'compiler' => 'bi_query_spec_v1',
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $bindings
     */
    private function compileQuery(BiQuerySpec $spec, array $query, array &$bindings, int &$counter): string
    {
        $driver = $spec->driver();
        $aliases = $spec->aliasesFor($query);
        $fromAlias = strtolower((string) $query['alias']);

        $from = $this->q((string) $query['from'], $driver).' AS '.$this->q($fromAlias, $driver);
        $joins = '';

        foreach ($query['joins'] as $join) {
            $type = $join['type'] === 'inner' ? 'INNER JOIN' : 'LEFT JOIN';
            $left = $this->columnSql($spec, $join['on']['left'] ?? null, $aliases, $driver);
            $right = $this->columnSql($spec, $join['on']['right'] ?? null, $aliases, $driver);
            $joins .= ' '.$type.' '.$this->q((string) $join['table'], $driver)
                .' AS '.$this->q((string) $join['alias'], $driver)
                .' ON '.$left.' = '.$right;
        }

        $select = [];
        $selectAliases = [];

        foreach ($query['select'] as $item) {
            $as = (string) ($item['as'] ?? $item['alias']);
            $selectAliases[strtolower($as)] = true;
            $select[] = $this->selectExpression($spec, $item, $aliases, $driver, $bindings, $counter)
                .' AS '.$this->q($as, $driver);
        }

        $conditions = $this->companyConditions($spec, $query, $aliases, $bindings, $counter);

        foreach ($query['where'] as $condition) {
            $conditions[] = $this->conditionSql($spec, $condition, $aliases, $driver, $bindings, $counter);
        }

        $sql = 'SELECT '.implode(', ', $select).' FROM '.$from.$joins;

        if ($conditions !== []) {
            $sql .= ' WHERE '.implode(' AND ', $conditions);
        }

        if ($query['group_by'] !== []) {
            $groups = [];

            foreach ($query['group_by'] as $field) {
                if (is_string($field) && isset($selectAliases[strtolower($field)])) {
                    $groups[] = $this->q($field, $driver);
                    continue;
                }

                $groups[] = $this->columnSql($spec, $field, $aliases, $driver);
            }

            $sql .= ' GROUP BY '.implode(', ', $groups);
        }

        if ($query['order_by'] !== []) {
            $orders = [];

            foreach ($query['order_by'] as $order) {
                $field = (string) $order['column'];
                $direction = strtoupper((string) $order['dir']) === 'DESC' ? 'DESC' : 'ASC';
                $expression = isset($selectAliases[strtolower($field)])
                    ? $this->q($field, $driver)
                    : $this->columnSql($spec, $field, $aliases, $driver);
                $orders[] = $expression.' '.$direction;
            }

            $sql .= ' ORDER BY '.implode(', ', $orders);
        }

        if ($query['limit'] !== null) {
            $sql .= ' LIMIT '.(int) $query['limit'];
        }

        return $sql;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, string>  $aliases
     * @param  array<string, mixed>  $bindings
     */
    private function selectExpression(
        BiQuerySpec $spec,
        array $item,
        array $aliases,
        string $driver,
        array &$bindings,
        int &$counter,
    ): string {
        $expr = strtolower((string) ($item['expr'] ?? $item['type'] ?? BiQuerySpec::EXPR_COLUMN));

        if ($expr === BiQuerySpec::EXPR_LITERAL) {
            return $this->bind($item['value'] ?? null, $bindings, $counter);
        }

        if ($expr === BiQuerySpec::EXPR_COUNT && ($item['column'] ?? '*') === '*') {
            return 'COUNT(*)';
        }

        $column = $this->columnSql($spec, $item['column'] ?? null, $aliases, $driver);

        return match ($expr) {
            BiQuerySpec::EXPR_COLUMN => $column,
            BiQuerySpec::EXPR_COUNT => 'COUNT('.$column.')',
            BiQuerySpec::EXPR_SUM => 'SUM('.$column.')',
            BiQuerySpec::EXPR_AVG => 'AVG('.$column.')',
            BiQuerySpec::EXPR_MIN => 'MIN('.$column.')',
            BiQuerySpec::EXPR_MAX => 'MAX('.$column.')',
            BiQuerySpec::EXPR_YEAR => $driver === 'sqlite'
                ? "strftime('%Y', ".$column.')'
                : 'YEAR('.$column.')',
            BiQuerySpec::EXPR_DATE => $driver === 'sqlite'
                ? 'date('.$column.')'
                : 'DATE('.$column.')',
            BiQuerySpec::EXPR_MONTH => $driver === 'sqlite'
                ? "strftime('%Y-%m', ".$column.')'
                : "DATE_FORMAT(".$column.", '%Y-%m')",
            default => throw new RuntimeException('Неизвестное выражение: '.$expr),
        };
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $aliases
     * @param  array<string, mixed>  $bindings
     * @return list<string>
     */
    private function companyConditions(BiQuerySpec $spec, array $query, array $aliases, array &$bindings, int &$counter): array
    {
        $conditions = [];

        foreach ($aliases as $alias => $table) {
            if (! array_key_exists('company_id', $this->schema[$table] ?? [])) {
                continue;
            }

            $alreadyFiltered = false;

            foreach ($query['where'] as $condition) {
                try {
                    [$filterAlias, $column] = $spec->resolveColumn($condition['column'] ?? null, $aliases);
                } catch (RuntimeException) {
                    continue;
                }

                $op = strtolower(trim((string) ($condition['op'] ?? $condition['operator'] ?? '=')));
                $value = $condition['value'] ?? null;

                // Только явное company_id = :company_id считается tenant-фильтром.
                // Любая другая операция или литерал не должны отключать
                // принудительную проверку компании (иначе можно подменить tenant).
                if ($filterAlias === $alias && $column === 'company_id'
                    && $op === '=' && is_string($value) && trim($value) === ':company_id') {
                    $alreadyFiltered = true;
                    break;
                }
            }

            if ($alreadyFiltered) {
                continue;
            }

            $conditions[] = $this->q($alias, $spec->driver()).'.'.$this->q('company_id', $spec->driver()).' = :company_id';
        }

        return $conditions;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, string>  $aliases
     * @param  array<string, mixed>  $bindings
     */
    private function conditionSql(
        BiQuerySpec $spec,
        array $condition,
        array $aliases,
        string $driver,
        array &$bindings,
        int &$counter,
    ): string {
        $column = $this->columnSql($spec, $condition['column'] ?? null, $aliases, $driver);
        $op = strtolower(trim((string) ($condition['op'] ?? $condition['operator'] ?? '=')));

        if ($op === 'is null' || $op === 'is not null') {
            return $column.($op === 'is null' ? ' IS NULL' : ' IS NOT NULL');
        }

        if (in_array($op, ['in', 'not in'], true)) {
            $values = $condition['value'];
            $placeholders = [];

            foreach ($values as $value) {
                $placeholders[] = $this->bind($value, $bindings, $counter);
            }

            return $column.($op === 'in' ? ' IN (' : ' NOT IN (').implode(', ', $placeholders).')';
        }

        $value = $condition['value'] ?? null;

        if (is_string($value) && preg_match('/^:([A-Za-z_][A-Za-z0-9_]*)$/', $value, $match) === 1) {
            if ($match[1] !== 'company_id') {
                throw new RuntimeException('Разрешён только плейсхолдер :company_id.');
            }

            return $column.' = :company_id';
        }

        $placeholder = $this->bind($value, $bindings, $counter);

        return $column.' '.$op.' '.$placeholder;
    }

    /** @param array<string, mixed> $aliases */
    private function columnSql(BiQuerySpec $spec, mixed $ref, array $aliases, string $driver): string
    {
        [$alias, $column] = $spec->resolveColumn($ref, $aliases);

        return $this->q($alias, $driver).'.'.$this->q($column, $driver);
    }

    /**
     * @param  array<string, mixed>  $bindings
     */
    private function bind(mixed $value, array &$bindings, int &$counter): string
    {
        if (is_array($value) || is_object($value)) {
            throw new RuntimeException('Значение фильтра должно быть скалярным.');
        }

        $counter++;
        $name = 'p'.$counter;
        $bindings[$name] = $value;

        return ':'.$name;
    }

    private function q(string $identifier, string $driver): string
    {
        $quote = $driver === 'sqlite' ? '"' : '`';

        return $quote.str_replace($quote, $quote.$quote, $identifier).$quote;
    }
}
