<?php

declare(strict_types=1);

namespace App\Domains\DashboardGen\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Исполнение query_spec виджета (перенос из Datavue под БД corpms).
 *
 * Отличия от оригинала: вместо роутера источников — Laravel-соединение по
 * умолчанию (mysql в проде, sqlite в тестах); нет поиска/сортировки/постраничного
 * вывода (таблица отдаётся целиком до MAX_FETCH_ROWS, листает фронтенд);
 * всегда биндится :company_id — тенант-фильтр, который требует валидатор.
 */
class WidgetQueryRunner
{
    /** Потолок строк для превью и probe. */
    public const MAX_ROWS = 50;

    /** Сколько строк виджет получает целиком. */
    public const MAX_FETCH_ROWS = 5000;

    public const SAMPLE_ROWS = 20;

    private SqlParameterBinder $binder;

    public function __construct(
        private ?ConnectionInterface $connection = null,
        private ?int $companyId = null,
    ) {
        $this->connection ??= DB::connection();
        $this->binder = new SqlParameterBinder();
    }

    /**
     * @param  array{queries?: mixed, shape?: mixed, presentation?: array}  $spec
     * @return array{ok: bool, data?: array, meta?: array, error?: string}
     */
    public function run(array $spec, string $family, ?string $type = null): array
    {
        $shape = $spec['shape'] ?? null;

        if (!in_array($shape, WidgetShapeMapper::SHAPES, true)) {
            return ['ok' => false, 'error' => 'Неизвестная форма данных: '.json_encode($shape)];
        }

        $queries = $this->normalizeQueries($spec['queries'] ?? null);

        if ($queries === []) {
            return ['ok' => false, 'error' => 'В спецификации нет ни одного запроса.'];
        }

        $truncated = false;
        $rows = [];

        try {
            foreach ($queries as $sql) {
                $prepared = $this->prepare($sql);
                $fetched = $this->fetch($prepared);

                $truncated = $truncated || $fetched['truncated'];

                foreach ($fetched['rows'] as $row) {
                    $rows[] = $row;
                }
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if ($truncated && $shape === WidgetShapeMapper::SHAPE_SERIES_MATRIX) {
            $rows = $this->withoutIncompleteTail($rows);
        }

        try {
            $data = (new WidgetShapeMapper())->map(
                family: $family,
                type: $type,
                shape: $shape,
                rows: $rows,
                presentation: is_array($spec['presentation'] ?? null) ? $spec['presentation'] : [],
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Не удалось разложить результат: '.$e->getMessage()];
        }

        $validationError = $this->validateMappedContent($family, $type, $data);
        if ($validationError !== null) {
            return ['ok' => false, 'error' => $validationError];
        }

        return [
            'ok' => true,
            'data' => $data,
            'meta' => ['truncated' => $truncated, 'max_rows' => self::MAX_FETCH_ROWS],
        ];
    }

    /** Проба запроса: возвращает колонки результата или ошибку. */
    public function probe(string $sql): array
    {
        try {
            $applied = $this->binder->apply($sql, $this->placeholderValues($sql));
            $safe = ReadOnlySqlGuard::sanitize($applied['sql'], null);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            $rows = ReadOnlySqlGuard::normalizeRows(
                $this->select('SELECT * FROM ('.$safe.') AS widget_probe LIMIT 1', $applied['bindings'])
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'columns' => $rows === [] ? [] : array_keys($rows[0])];
    }

    /** Безопасное исполнение одиночного запроса (только SELECT/WITH, с LIMIT). */
    public function execute(string $sql, array $bindings = []): array
    {
        $safe = ReadOnlySqlGuard::sanitize($sql, self::MAX_ROWS);

        return ReadOnlySqlGuard::normalizeRows($this->select($safe, $bindings));
    }

    /** @return array<string, string> */
    private function normalizeQueries(mixed $queries): array
    {
        if (is_string($queries) && trim($queries) !== '') {
            return ['main' => trim($queries)];
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

        if (isset($result['main'])) {
            $result = ['main' => $result['main']] + $result;
        }

        return $result;
    }

    /**
     * @return array{sql: string, bindings: array<int, mixed>}
     */
    private function prepare(string $sql): array
    {
        $applied = $this->binder->apply($sql, $this->placeholderValues($sql));

        $clean = ReadOnlySqlGuard::sanitize($applied['sql'], null);

        return ['sql' => $clean, 'bindings' => $applied['bindings']];
    }

    /**
     * Значения для всех плейсхолдеров запроса. :company_id всегда подставлен —
     * если тенант неизвестен, подставляется NULL (ничего не совпадёт), а не
     * ошибка синтаксиса и не пропуск данных.
     *
     * @return array<string, mixed>
     */
    private function placeholderValues(string $sql): array
    {
        $values = ['company_id' => $this->companyId];

        foreach ($this->placeholderNames($sql) as $name) {
            if (!array_key_exists($name, $values)) {
                throw new RuntimeException("Неизвестный плейсхолдер :{$name}. Разрешён только :company_id.");
            }
        }

        return $values;
    }

    /** @return array<int, string> */
    private function placeholderNames(string $sql): array
    {
        preg_match_all('/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, truncated: bool}
     */
    private function fetch(array $prepared): array
    {
        $sql = $prepared['sql'];
        $bindings = $prepared['bindings'];

        $hasOwnLimit = preg_match('/\blimit\s+\d+/i', $sql) === 1;

        if (!$hasOwnLimit) {
            $sql .= ' LIMIT '.(self::MAX_FETCH_ROWS + 1);
        }

        $rows = ReadOnlySqlGuard::normalizeRows($this->select($sql, $bindings));

        $truncated = false;

        if (count($rows) > self::MAX_FETCH_ROWS) {
            $truncated = true;
            $rows = array_slice($rows, 0, self::MAX_FETCH_ROWS);
        }

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * Обрезанный по потолку набор оканчивается посреди категории: у неё есть
     * не все ряды, и недостающие превратились бы в нули. Целая категория
     * лучше, чем категория с неверными значениями.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function withoutIncompleteTail(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $last = $rows[array_key_last($rows)]['category'] ?? null;

        while ($rows !== [] && ($rows[array_key_last($rows)]['category'] ?? null) === $last) {
            array_pop($rows);
        }

        return $rows;
    }

    /**
     * Лёгкая post-run проверка: результат должен соответствовать форме виджета,
     * иначе ошибка ловится здесь, а не в ApexCharts/JS.
     */
    private function validateMappedContent(string $family, ?string $type, mixed $data): ?string
    {
        if (!is_array($data)) {
            return 'Результат виджета имеет неверный формат.';
        }

        return match ($family) {
            'table' => $this->validateTableContent($data),
            'mini-counters', 'radial' => $this->validateCountersContent($data, $type === 'with-progress'),
            'pie', 'funnel', 'treemap' => $this->validateSeriesValuesContent($data),
            'bar', 'line', 'radar', 'combo', 'heatmap' => $this->validateSeriesMatrixContent($data),
            'scatter' => $this->validatePointsContent($data, $type === 'bubble'),
            default => null,
        };
    }

    /** @param array<string,mixed> $data */
    private function validateTableContent(array $data): ?string
    {
        if (!is_array($data['headers'] ?? null) || !is_array($data['rows'] ?? null)) {
            return 'Таблица должна содержать headers и rows.';
        }
        $h = count($data['headers']);
        foreach ($data['rows'] as $i => $row) {
            if (!is_array($row) || count($row) !== $h) {
                return "Строка таблицы {$i} не соответствует числу колонок.";
            }
        }
        return null;
    }

    /** @param array<string,mixed> $data */
    private function validateCountersContent(array $data, bool $needPercent): ?string
    {
        $counters = $data['counters'] ?? null;
        if (!is_array($counters)) return 'Счётчики должны содержать counters[].';
        foreach ($counters as $i => $c) {
            if (!is_array($c) || !array_key_exists('name', $c) || !is_numeric($c['value'] ?? null)) {
                return "Счётчик {$i} должен содержать name и числовой value.";
            }
            if ($needPercent && !is_numeric($c['percent'] ?? null)) {
                return "Счётчик {$i} должен содержать числовой percent.";
            }
        }
        return null;
    }

    /** @param array<string,mixed> $data */
    private function validateSeriesValuesContent(array $data): ?string
    {
        $s = $data['series'] ?? null;
        $l = $data['labels'] ?? null;
        if (!is_array($s) || !is_array($l)) return 'Виджет должен содержать series[] и labels[].';
        if (count($s) !== count($l)) return 'Количество series и labels должно совпадать.';
        foreach ($s as $v) {
            if (!is_numeric($v)) return 'Значения series должны быть числами.';
        }
        return null;
    }

    /** @param array<string,mixed> $data */
    private function validateSeriesMatrixContent(array $data): ?string
    {
        $series = $data['series'] ?? null;
        $cats = $data['categories'] ?? $data['labels'] ?? $data['x_categories'] ?? null;
        if (!is_array($series) || !is_array($cats)) return 'Виджет должен содержать series[] и categories[].';
        foreach ($series as $i => $s) {
            if (!is_array($s) || !is_array($s['data'] ?? null)) return "Серия {$i} должна содержать data[].";
            if (count($s['data']) !== count($cats)) return "Серия {$i} не совпадает с числом категорий.";
            foreach ($s['data'] as $v) {
                if (!is_numeric($v)) return "Серия {$i} содержит нечисловое значение.";
            }
        }
        return null;
    }

    /** @param array<string,mixed> $data */
    private function validatePointsContent(array $data, bool $bubble): ?string
    {
        $series = $data['series'] ?? null;
        if (!is_array($series)) return 'Scatter должен содержать series[].';
        $need = $bubble ? 3 : 2;
        foreach ($series as $i => $s) {
            if (!is_array($s) || !is_array($s['data'] ?? null)) return "Серия {$i} должна содержать data[].";
            foreach ($s['data'] as $j => $p) {
                if (!is_array($p) || count($p) < $need) return "Точка {$i}/{$j} имеет неверный формат.";
                for ($k = 0; $k < $need; $k++) {
                    if (!is_numeric($p[$k] ?? null)) return "Точка {$i}/{$j} содержит нечисловое значение.";
                }
            }
        }
        return null;
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return array<int, mixed>
     */
    private function select(string $sql, array $bindings = []): array
    {
        return $this->connection->select($sql, $bindings);
    }
}
