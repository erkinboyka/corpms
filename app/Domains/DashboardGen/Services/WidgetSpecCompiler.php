<?php

declare(strict_types=1);

namespace App\Domains\DashboardGen\Services;

use App\Domains\DashboardGen\QuerySpec\BiQueryCompiler;
use App\Domains\DashboardGen\QuerySpec\BiQuerySpec;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Компиляция и повторная компиляция query_spec виджета.
 *
 * В query_spec вместе с готовым SQL хранится исходная структурированная
 * спецификация в "source": это позволяет применять фильтры Bumi без генерации
 * нового SQL моделью и без правки готового запроса строками.
 */
final class WidgetSpecCompiler
{
    public function __construct(
        private readonly SchemaInspector $schemaInspector,
        private readonly string $driver = 'mysql',
    ) {}

    public static function forCurrentConnection(): self
    {
        return new self(
            app(SchemaInspector::class),
            DB::getDriverName() === 'sqlite' ? 'sqlite' : 'mysql',
        );
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  array<string, array<string, mixed>|string>|null  $source
     * @return array<string, mixed>
     */
    public function compile(array $structured, ?array $source = null): array
    {
        $schema = $this->schema();
        $compiled = (new BiQueryCompiler($schema))->compile(
            new BiQuerySpec($structured, $schema, $this->driver),
        );

        $shape = $structured['shape'] ?? null;
        if (! is_string($shape) || ! in_array($shape, WidgetShapeMapper::SHAPES, true)) {
            throw new RuntimeException('Неизвестная форма данных виджета.');
        }

        return [
            'source' => $source ?? $this->structuredQueries($structured),
            'queries' => $compiled['queries'],
            'bindings' => $compiled['bindings'],
            'compiler' => $compiled['compiler'],
            'shape' => $shape,
            'presentation' => is_array($structured['presentation'] ?? null) ? $structured['presentation'] : [],
        ];
    }

    /**
     * Пересобрать SQL с учётом фильтров дашборда/виджета.
     * Фильтр, колонки которого нет в запросе, молча пропускается: один дашборд
     * может содержать виджеты с разными наборами таблиц.
     *
     * @param  array<string, mixed>  $spec
     * @param  list<array<string, mixed>>  $filters
     * @return array<string, mixed>
     */
    public function apply(array $spec, array $filters): array
    {
        $source = $spec['source'] ?? null;

        if (! is_array($source) || $source === [] || $filters === []) {
            return $spec;
        }

        $schema = $this->schema();
        $queries = [];

        foreach ($source as $name => $query) {
            if (! is_array($query)) {
                $queries[(string) $name] = $query;
                continue;
            }

            $draft = new BiQuerySpec(['queries' => [(string) $name => $query]], $schema, $this->driver);
            $aliases = $draft->aliasesFor($draft->normalizeQuery($query));
            $where = is_array($query['where'] ?? null) ? $query['where'] : [];

            foreach ($filters as $filter) {
                try {
                    $draft->resolveColumn($filter['column'] ?? null, $aliases);
                } catch (RuntimeException) {
                    continue;
                }

                $where[] = $filter;
            }

            $query['where'] = $where;
            $queries[(string) $name] = $query;
        }

        $structured = [
            'queries' => $queries,
            'shape' => $spec['shape'] ?? null,
            'presentation' => $spec['presentation'] ?? [],
        ];

        try {
            $result = $this->compile($structured, $source);
        } catch (RuntimeException) {
            return $spec;
        }

        return $result;
    }

    /** @return array<string, array<string, array{type: string, samples?: list<string>}>> */
    public function schema(): array
    {
        return $this->schemaInspector->schema((array) config('dashboard_gen.tables', []));
    }

    /**
     * @param  array<string, mixed>  $structured
     * @return array<string, array<string, mixed>|string>
     */
    private function structuredQueries(array $structured): array
    {
        $queries = $structured['queries'] ?? $structured['query'] ?? [];

        return is_array($queries) ? $queries : [];
    }
}
