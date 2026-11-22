<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * BE-07/PERF-08: единая нормализация поискового запроса и безопасный LIKE.
 *
 * - длина ≤ 120 символов (валидация на входе);
 * - %, _ и \ экранируются до сборки шаблона LIKE;
 * - ESCAPE '\' задаётся явно, чтобы SQLite и MySQL вели себя одинаково.
 */
final class SearchQuery
{
    private function __construct(public readonly string $value) {}

    public static function fromRequest(Request $request, string $key = 'q'): self
    {
        $validated = $request->validate([$key => ['nullable', 'string', 'max:120']]);

        return self::fromString((string) ($validated[$key] ?? ''));
    }

    public static function fromString(?string $raw): self
    {
        $value = trim((string) $raw);

        if (mb_strlen($value) > 120) {
            throw ValidationException::withMessages([
                'q' => __('Поисковый запрос не может быть длиннее 120 символов.'),
            ]);
        }

        return new self($value);
    }

    /** Добавляет условие LIKE по любому из переданных столбцов. */
    public function applyLike(EloquentBuilder|QueryBuilder $query, array $columns): void
    {
        $this->applyLikeTerms($query, $columns, [$this->value]);
    }

    /** Поиск по словам: каждое слово — отдельное AND-условие. */
    public function applyLikeTerms(EloquentBuilder|QueryBuilder $query, array $columns, ?array $terms = null): void
    {
        $query = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $terms ??= $this->terms();

        foreach ($terms as $term) {
            $pattern = '%'.self::escape((string) $term).'%';

            $query->where(function (QueryBuilder $nested) use ($columns, $pattern): void {
                foreach ($columns as $column) {
                    $wrapped = $nested->getGrammar()->wrap((string) $column);
                    $nested->orWhereRaw("{$wrapped} LIKE ? ESCAPE '\\'", [$pattern]);
                }
            });
        }
    }

    /** @return array<int, string> */
    public function terms(): array
    {
        return preg_split('/\s+/u', $this->value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function escape(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
