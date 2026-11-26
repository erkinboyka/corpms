<?php

declare(strict_types=1);

namespace App\Domains\DashboardGen\Services;

use App\Domains\DashboardGen\QuerySpec\BiQueryCompiler;
use App\Domains\DashboardGen\QuerySpec\BiQuerySpec;
use RuntimeException;

/**
 * Генерация SQL-спецификации виджета (перенос WidgetQueryAi из Datavue).
 *
 * Отличия от оригинала: dialectHints() вместо провайдеров источников —
 * диалект берётся от текущего соединенияcorpms (mysql в проде, sqlite в
 * тестах); блок фильтров убран (MVP без date_range), но добавлено правило
 * обязательного тенант-фильтра :company_id.
 */
class WidgetQueryAi
{
    public function __construct(private AiClient $ai, private string $driver = 'mysql')
    {
    }

    /**
     * @param  array<string, mixed>  $tablesScheme
     * @return array{spec: ?array, error: ?string, tokens: int}
     */
    public function generate(string $instruction, string $family, ?string $type, array $tablesScheme): array
    {
        $shape = WidgetShapeMapper::shapeFor($family);

        return $this->ask(
            $this->buildPrompt($instruction, $family, $type, $shape, $tablesScheme),
            $this->systemPrompt(),
            $tablesScheme,
        );
    }

    /**
     * @param  array<string, mixed>  $tablesScheme
     * @param  array<string, mixed>  $brokenSpec
     * @return array{spec: ?array, error: ?string, tokens: int}
     */
    public function repair(
        string $instruction,
        string $family,
        ?string $type,
        array $tablesScheme,
        array $brokenSpec,
        string $error,
    ): array {
        $shape = WidgetShapeMapper::shapeFor($family);

        $specJson = json_encode($brokenSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $prompt = $this->buildPrompt($instruction, $family, $type, $shape, $tablesScheme)
            . <<<TEXT


========================
ПРЕДЫДУЩАЯ ПОПЫТКА НЕ СРАБОТАЛА
========================
Спецификация:
{$specJson}

Ошибка:
{$error}

Исправь запрос так, чтобы ошибка ушла. Не меняй смысл виджета.
Если нужных данных в схеме нет — верни спецификацию, дающую пустой результат
правильной формы, и объясни причину в поле "message".
TEXT;

        return $this->ask($prompt, $this->systemPrompt(), $tablesScheme);
    }

    private function systemPrompt(): string
    {
        $dialect = $this->dialectHints()['name'];

        return <<<TEXT
Ты — аналитик, который описывает запросы для виджетов дашборда на {$dialect}.

Ты НЕ пишешь SQL вручную. Ты возвращаешь структурированную спецификацию
"queries", из которой платформа сама соберёт параметризованный SELECT.

Жёсткие правила:
1. Ответ — только JSON, без markdown и без пояснений вне JSON.
2. Используй только таблицы и колонки из переданной схемы. Ничего не выдумывай.
3. Для каждой таблицы с company_id платформа сама добавит фильтр
   company_id = :company_id. Не надо писать его вручную и нельзя подставлять число.
4. Значения фильтров передавай в "value" как обычные строки/числа: они уйдут
   в параметры запроса, а не в текст SQL.
5. Имена выходных колонок задавай в "as" ровно так, как требует форма виджета
   (label/value, series/category/value, name/value, x/y, заголовки таблицы).
6. Если нужно сравнить текст со значением (статус, категория), бери его СЛОВО
   В СЛОВО из "samples" колонки. Нужного значения нет — не выдумывай фильтр.
7. Несколько простых запросов лучше одного сложного: их строки склеиваются.
8. Для дат используй expr "month"/"year"/"date" — платформа учтёт диалект БД.
9. Лимит строк — от 1 до 5000. Для таблиц лимит можно не задавать.

СТРУКТУРА ОДНОГО ЗАПРОСА:
{
  "from": "crm_clients",
  "alias": "c",
  "joins": [
    {"table": "crm_deals", "alias": "d", "type": "left",
     "on": {"left": "c.id", "right": "d.client_id"}}
  ],
  "select": [
    {"expr": "month", "column": "d.created_at", "as": "category"},
    {"expr": "count", "as": "value"}
  ],
  "where": [
    {"column": "d.status", "op": "=", "value": "won"}
  ],
  "group_by": ["category"],
  "order_by": [{"column": "category", "dir": "asc"}],
  "limit": 12
}

Допустимые expr: column, count, sum, avg, min, max, month, year, date, literal.
Допустимые op: =, !=, >, >=, <, <=, like, in, not in, is null, is not null.
TEXT;
    }

    /**
     * @param  array<string, mixed>  $tablesScheme
     */
    private function buildPrompt(
        string $instruction,
        string $family,
        ?string $type,
        string $shape,
        array $tablesScheme,
    ): string {
        $hints = $this->dialectHints();
        $tablesJson = json_encode($tablesScheme, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $typeLine = $type ? "Вариант отрисовки: {$type}" : 'Вариант отрисовки по умолчанию';

        return <<<TEXT
========================
ЧТО НУЖНО ПОКАЗАТЬ
========================
{$instruction}

Семейство виджета: {$family}
{$typeLine}

========================
СХЕМА ИСТОЧНИКА
========================
{$tablesJson}

========================
ПРАВИЛА ДИАЛЕКТА
========================
{$hints['rules']}

========================
ФОРМА РЕЗУЛЬТАТА: {$shape}
========================
{$this->shapeContract($shape, $family, $type)}

ПРИМЕР ПРАВИЛЬНОГО ОТВЕТА ДЛЯ ЭТОЙ ФОРМЫ:
{$this->shapeExample($shape, $type)}

========================
ФОРМАТ ОТВЕТА
========================
Верни ТОЛЬКО JSON:

{
  "queries": { "main": { "from": "...", "select": [...], "where": [...] } },
  "shape": "{$shape}",
  "presentation": {},
  "message": ""
}

- "queries.main" — структурированная спецификация запроса (см. system prompt).
- Для обратной совместимости разрешается строка SQL без ";", но только с
  таблицами/колонками из схемы и без подстановки значений в текст.
- "shape" — строго "{$shape}".
- "presentation" — только если это нужно форме (см. выше), иначе пустой объект.
- "message" — заполняй, только если данных не хватило; иначе пустая строка.
TEXT;
    }

    /**
     * @return array{name: string, rules: string}
     */
    public function dialectHints(): array
    {
        if ($this->driver === 'sqlite') {
            return [
                'name' => 'SQLite',
                'rules' => implode("\n", [
                    '— даты: date(), datetime(), strftime("%Y-%m", колонка) для группировки по месяцам;',
                    '— нет DATE_FORMAT/IFNULL: используй COALESCE вместо IFNULL, CAST(... AS TEXT) для текста;',
                    '— агрегаты: COUNT, SUM, AVG, MIN, MAX; strftime("%Y", даты) — год.',
                    '— имена колонок с регистром/спецсимволами заключай в двойные кавычки.',
                ]),
            ];
        }

        return [
            'name' => 'MySQL 8',
            'rules' => implode("\n", [
                '— даты: DATE_FORMAT(колонка, "%Y-%m") для группировки по месяцам, YEAR(), DATE(), CURDATE();',
                '— текст: IFNULL вместо NULL, CONCAT для склейки, CAST(... AS CHAR);',
                '— агрегаты: COUNT, SUM, AVG, MIN, MAX; оконные функции разрешены.',
                '— имена колонок с регистром/спецсимволами заключай в обратные кавычки.',
            ]),
        ];
    }

    private function shapeContract(string $shape, string $family, ?string $type): string
    {
        return match ($shape) {
            WidgetShapeMapper::SHAPE_SERIES_VALUES => $this->valuesContract($family),

            WidgetShapeMapper::SHAPE_SERIES_MATRIX => $this->matrixContract($family),

            WidgetShapeMapper::SHAPE_POINTS => $type === 'bubble'
                ? "Колонки: series (имя набора), x (число), y (число), z (число — размер точки).\n"
                    . 'Одна строка — одна точка.'
                : "Колонки: series (имя набора), x (число), y (число).\n"
                    . "Одна строка — одна точка. Если набор один, верни в series одно и то же имя.",

            WidgetShapeMapper::SHAPE_COUNTERS => $this->countersContract($type),

            WidgetShapeMapper::SHAPE_ROWS =>
                "Колонки — любые, какие нужны в таблице. Их имена станут заголовками "
                . "столбцов, поэтому задай их через AS по-человечески (например, "
                . '"Клиент", "Выручка"). Порядок колонок = порядок столбцов.'."\n"
                . 'LIMIT НЕ СТАВЬ: платформа сама ограничит число строк. '
                . 'Сортировку через ORDER BY задавай.',

            default => throw new RuntimeException("Неизвестная форма: {$shape}"),
        };
    }

    private function shapeExample(string $shape, ?string $type): string
    {
        return match ($shape) {
            WidgetShapeMapper::SHAPE_SERIES_VALUES => <<<'JSON'
{
  "queries": {
    "main": {
      "from": "crm_clients",
      "alias": "c",
      "select": [
        {"expr": "column", "column": "c.name", "as": "label"},
        {"expr": "count", "as": "value"}
      ],
      "group_by": ["label"],
      "order_by": [{"column": "value", "dir": "desc"}],
      "limit": 10
    }
  },
  "shape": "series_values"
}
JSON,

            WidgetShapeMapper::SHAPE_SERIES_MATRIX => <<<'JSON'
{
  "queries": {
    "main": {
      "from": "fin_transactions",
      "alias": "t",
      "select": [
        {"expr": "literal", "value": "Выручка", "as": "series"},
        {"expr": "month", "column": "t.created_at", "as": "category"},
        {"expr": "sum", "column": "t.amount", "as": "value"}
      ],
      "group_by": ["series", "category"],
      "order_by": [{"column": "category", "dir": "asc"}]
    }
  },
  "shape": "series_matrix"
}
JSON,

            WidgetShapeMapper::SHAPE_POINTS => $type === 'bubble'
                ? <<<'JSON'
{
  "queries": {
    "main": {
      "from": "crm_deals",
      "alias": "d",
      "select": [
        {"expr": "literal", "value": "Сделки", "as": "series"},
        {"expr": "column", "column": "d.probability", "as": "x"},
        {"expr": "column", "column": "d.amount", "as": "y"},
        {"expr": "literal", "value": 1, "as": "z"}
      ]
    }
  },
  "shape": "points"
}
JSON
                : <<<'JSON'
{
  "queries": {
    "main": {
      "from": "crm_deals",
      "alias": "d",
      "select": [
        {"expr": "literal", "value": "Сделки", "as": "series"},
        {"expr": "column", "column": "d.probability", "as": "x"},
        {"expr": "column", "column": "d.amount", "as": "y"}
      ]
    }
  },
  "shape": "points"
}
JSON,

            WidgetShapeMapper::SHAPE_COUNTERS => <<<'JSON'
{
  "queries": {
    "deals": {
      "from": "crm_deals",
      "alias": "d",
      "select": [
        {"expr": "literal", "value": "Сделок", "as": "name"},
        {"expr": "count", "as": "value"}
      ]
    },
    "amount": {
      "from": "crm_deals",
      "alias": "d",
      "select": [
        {"expr": "literal", "value": "Сумма", "as": "name"},
        {"expr": "sum", "column": "d.amount", "as": "value"},
        {"expr": "literal", "value": " TJS", "as": "suffix"}
      ]
    }
  },
  "shape": "counters"
}
JSON,

            WidgetShapeMapper::SHAPE_ROWS => <<<'JSON'
{
  "queries": {
    "main": {
      "from": "purchase_orders",
      "alias": "o",
      "joins": [
        {"table": "suppliers", "alias": "s", "type": "left",
         "on": {"left": "o.supplier_id", "right": "s.id"}}
      ],
      "select": [
        {"expr": "column", "column": "o.number", "as": "number"},
        {"expr": "column", "column": "o.created_at", "as": "date"},
        {"expr": "column", "column": "s.name", "as": "supplier"},
        {"expr": "column", "column": "o.total", "as": "total"}
      ],
      "order_by": [{"column": "date", "dir": "desc"}]
    }
  },
  "shape": "rows"
}
JSON,

            default => '{}',
        };
    }

    private function valuesContract(string $family): string
    {
        $base = "Колонки: label (подпись, текст), value (число).\n"
            . "Одна строка — один сегмент. Сортировку задавай через ORDER BY, "
            . "количество — через LIMIT.";

        return match ($family) {
            'radial' => $base . "\nЭто индикатор выполнения: в value ожидается "
                . "процент от 0 до 100, обычно одна-три строки.",

            'funnel' => $base . "\nЭто воронка: строки должны идти от самого "
                . "широкого этапа к самому узкому (ORDER BY value DESC).",

            default => $base,
        };
    }

    private function matrixContract(string $family): string
    {
        $base = "Колонки: series (имя ряда, текст), category (подпись по оси, текст), "
            . "value (число).\n"
            . "Одна строка — одно пересечение ряда и категории. Платформа сама "
            . "развернёт это в ряды и ось, а недостающие пересечения заполнит нулями — "
            . "перечислять все комбинации не нужно.\n"
            . "Порядок категорий на оси = порядок из ORDER BY, поэтому сортируй "
            . "по категории (для дат — по возрастанию).\n\n"
            . "ГЛАВНОЕ, ГДЕ ЛЕГКО ОШИБИТЬСЯ — что класть в series, а что в category:\n"
            . "  category — то, что откладывается ПО ОСИ и что сравнивают между собой "
            . "(страны, месяцы, товары).\n"
            . "  series — НАБОР значений, каждый своим цветом. Рядов обычно 1–4.\n\n"
            . "Если сравнивается одна величина в разрезе чего-то одного "
            . "(«продажи по месяцам»), ряд ВСЕГДА ОДИН, "
            . "и его имя — строковая константа:\n"
            . "  SELECT 'Выручка' AS series, DATE_FORMAT(...) AS category, SUM(...) AS value\n\n"
            . "Класть категории в series НЕЛЬЗЯ: каждое значение станет "
            . "отдельным рядом со своим цветом, ось окажется из одного деления, "
            . "и график будет нечитаемым.\n"
            . "Несколько рядов нужны только тогда, когда в запросе есть ВТОРОЙ "
            . "разрез — например «суммы по месяцам в разбивке по статусу».";

        return match ($family) {
            'combo' => $base . "\n\nЭто комбинированный график: часть рядов рисуется "
                . "столбцами, часть линией. Укажи это в presentation:\n"
                . '"presentation": { "series_kinds": { "Выручка": "column", "Средний чек": "line" } }',

            'radar' => $base . "\nДля радара категорий обычно 3–8: больше не читается.",

            'heatmap' => $base . "\nДля тепловой карты series — строки карты, "
                . "category — столбцы.",

            default => $base,
        };
    }

    private function countersContract(?string $type): string
    {
        $base = "Колонки: name (подпись счётчика, текст), value (число).\n"
            . "Одна строка — один счётчик. Обычно 2–4 счётчика.\n"
            . "ЛУЧШИЙ СПОСОБ: отдельный запрос на каждый счётчик — так проще "
            . "и надёжнее, чем сшивать их UNION ALL с подгонкой типов колонок. "
            . "Их строки платформа склеит сама, порядок запросов = порядок плиток.\n"
            . "Единицы измерения (TJS, %, шт.) возвращай колонками prefix и suffix.";

        return $type === 'with-progress'
            ? $base . "\nВариант с прогрессом: добавь колонку percent "
                . '(выполнение плана от 0 до 100).'
            : $base;
    }

    /**
     * @param  array<string, mixed>  $tablesScheme
     * @return array{spec: ?array, error: ?string, tokens: int}
     */
    private function ask(string $prompt, string $system, array $tablesScheme): array
    {
        // SQL-спецификация короткая: 1500 токенов достаточно даже с ремонтом,
        // а лишние токены упираются в лимит провайдера на весь запрос.
        $answer = $this->ai->chat($prompt, $system, 1500);

        if ($answer['error'] !== null) {
            return ['spec' => null, 'error' => $answer['error'], 'tokens' => $answer['tokens']];
        }

        $content = $answer['json'];

        if (!is_array($content)) {
            return ['spec' => null, 'error' => 'Не удалось разобрать ответ модели.', 'tokens' => $answer['tokens']];
        }

        $queries = $this->normalizeQueries($content['queries'] ?? null);

        if ($queries === []) {
            return [
                'spec' => null,
                'error' => is_string($content['message'] ?? null) && $content['message'] !== ''
                    ? $content['message']
                    : 'Модель не вернула запрос.',
                'tokens' => $answer['tokens'],
            ];
        }

        $spec = [
            'queries' => $queries,
            'shape' => $content['shape'] ?? null,
            'presentation' => is_array($content['presentation'] ?? null)
                ? $content['presentation']
                : [],
        ];

        // Структурированные запросы компилируются в параметризованный SQL.
        // Строковые запросы из старых спецификаций остаются как есть — их
        // всё равно проверяет WidgetSpecValidator и ReadOnlySqlGuard.
        if ($this->hasStructuredQueries($queries)) {
            try {
                $compiled = (new BiQueryCompiler($tablesScheme))->compile(
                    new BiQuerySpec($spec, $tablesScheme, $this->driver),
                );
                $spec['queries'] = $compiled['queries'];
                $spec['bindings'] = $compiled['bindings'];
                $spec['compiler'] = $compiled['compiler'];
            } catch (RuntimeException $e) {
                return ['spec' => null, 'error' => $e->getMessage(), 'tokens' => $answer['tokens']];
            }
        }

        return [
            'spec' => $spec,
            'error' => null,
            'tokens' => $answer['tokens'],
        ];
    }

    /** @return array<string, array<string, mixed>|string> */
    private function normalizeQueries(mixed $queries): array
    {
        if (is_string($queries) && trim($queries) !== '') {
            return ['main' => trim($queries)];
        }

        if (!is_array($queries)) {
            return [];
        }

        $result = [];

        foreach ($queries as $name => $query) {
            if (is_string($query) && trim($query) !== '') {
                $result[(string) $name] = trim($query);
                continue;
            }

            if (is_array($query)) {
                $result[(string) $name] = $query;
            }
        }

        return $result;
    }

    /** @param array<string, array<string, mixed>|string> $queries */
    private function hasStructuredQueries(array $queries): bool
    {
        foreach ($queries as $query) {
            if (is_array($query)) {
                return true;
            }
        }

        return false;
    }
}
