<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Accounting\Services\AccountingService;
use App\Domains\Analytics\Services\AnalyticsService;
use App\Domains\Audit\Services\AuditService;
use App\Domains\Bi\Services\BiService;
use App\Domains\Crm\Services\CrmService;
use App\Domains\Finance\Services\FinanceService;
use App\Domains\Inventory\Services\InventoryService;
use App\Domains\Projects\Services\ProjectService;
use App\Models\CrmClient;
use App\Models\CrmDeal;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Shared\Enums\CrmDealStage;
use App\Support\PermissionService;
use Carbon\CarbonImmutable;
use Closure;

/**
 * D5 (tool calling): белый список backend-инструментов для LLM (п.68 ТЗ).
 *
 * Принципы безопасности:
 * - инструмент виден модели только при наличии права у вызывающего (toolsFor);
 * - аргументы валидируются по схеме (обязательные поля, типы, длины, посторонние ключи);
 * - каждый вызов пишется в audit_logs (ai.tool.called / ai.tool.denied / ai.tool.invalid);
 * - критические операции (create) НЕ выполняются: возвращают deep-link подтверждения,
 *   пользователь завершает действие в существующем UI — ИИ не пишет в БД.
 */
class AiToolRegistry
{
    public function __construct(
        protected PermissionService $permissions,
        protected AnalyticsService $analytics,
        protected AuditService $audit,
        protected CrmService $crm,
        protected FinanceService $finance,
        protected InventoryService $inventory,
        protected ProjectService $projects,
        protected AccountingService $accounting,
        protected BiService $bi,
    ) {}

    /* -------------------- публичный API -------------------- */

    /**
     * Инструменты, доступные вызывающему, в формате OpenAI tools.
     *
     * @return array<int, array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public function toolsFor(Employee $asker): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $tools = [];
        foreach ($this->definitions() as $name => $definition) {
            if ($asker->user === null || ! $this->permissions->check($asker->user, $definition['permission'])) {
                continue;
            }

            $schema = $definition['schema'];
            if (($schema['properties'] ?? []) === []) {
                $schema['properties'] = new \stdClass;
            }
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $definition['description'],
                    'parameters' => $schema,
                ],
            ];
        }

        return $tools;
    }

    /**
     * Выполнить инструмент: конфиг → схема аргументов → права → обработчик → аудит.
     *
     * @return array{ok: bool, result: mixed, error: ?string}
     */
    public function execute(Employee $asker, string $name, mixed $arguments): array
    {
        $definition = $this->definitions()[$name] ?? null;

        if ($definition === null || ! $this->enabled()) {
            return $this->deny($asker, 'ai.tool.invalid', $name, $arguments, $definition === null ? 'unknown_tool' : 'disabled');
        }

        $args = is_array($arguments) ? $arguments : [];
        $error = $this->validate($definition['schema'], $args);
        if ($error !== null) {
            return $this->deny($asker, 'ai.tool.invalid', $name, $args, $error);
        }

        if ($asker->user === null || ! $this->permissions->check($asker->user, $definition['permission'])) {
            return $this->deny($asker, 'ai.tool.denied', $name, $args, 'forbidden');
        }

        try {
            $result = ($definition['handler'])($asker, $args);
        } catch (\Throwable) {
            return $this->deny($asker, 'ai.tool.invalid', $name, $args, 'failed');
        }

        $this->audit->log($asker->user, 'ai.tool.called', 'ai_tool', null, null, [
            'tool' => $name,
            'args' => $args,
            'critical' => $definition['critical'],
        ]);

        return ['ok' => true, 'result' => $result, 'error' => null];
    }

    /* -------------------- определения инструментов -------------------- */

    /**
     * Белый список инструментов: name → определение.
     *
     * @return array<string, array{
     *     description: string,
     *     permission: string,
     *     critical: bool,
     *     schema: array{type: string, properties: array<string, array<string, mixed>>, required: array<int, string>},
     *     handler: Closure
     * }>
     */
    protected function definitions(): array
    {
        return [
            'search_documents' => [
                'description' => 'Поиск документов компании по названию или номеру. Возвращает заголовок, номер и ссылку.',
                'permission' => 'documents.view',
                'critical' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => __('Поисковый запрос')],
                    ],
                    'required' => ['query'],
                ],
                'handler' => fn (Employee $asker, array $args): array => $this->searchDocuments($asker, (string) $args['query']),
            ],

            'search_tasks' => [
                'description' => __('Поиск задач компании по названию. Возвращает заголовок, статус и ссылку.'),
                'permission' => 'tasks.view',
                'critical' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => __('Поисковый запрос')],
                    ],
                    'required' => ['query'],
                ],
                'handler' => fn (Employee $asker, array $args): array => $this->searchTasks($asker, (string) $args['query']),
            ],

            'attendance_summary' => [
                'description' => __('Сводка посещаемости за сегодня в области доступа вызывающего.'),
                'permission' => 'attendance.view',
                'critical' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
                'handler' => fn (Employee $asker, array $args): array => $this->attendanceSummary($asker),
            ],

            'task_stats' => [
                'description' => __('Статистика задач по статусам в области доступа вызывающего.'),
                'permission' => 'analytics.view',
                'critical' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
                'handler' => fn (Employee $asker, array $args): array => $this->taskStats($asker),
            ],

            'create_task' => [
                'description' => 'Подготовить создание задачи. Требует подтверждения пользователем: возвращает черновик и ссылку, запись в систему не выполняется.',
                'permission' => 'tasks.create',
                'critical' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => __('Название задачи')],
                        'description' => ['type' => 'string', 'description' => __('Описание задачи')],
                    ],
                    'required' => ['title'],
                ],
                'handler' => fn (Employee $asker, array $args): array => [
                    'needs_confirmation' => true,
                    'message' => 'Создание задачи требует подтверждения — подтвердите данные в форме, ИИ не пишет в систему без вашего действия.',
                    'confirm_url' => route('tasks.mine').'#new-task',
                    'draft' => [
                        'title' => (string) $args['title'],
                        'description' => isset($args['description']) ? (string) $args['description'] : null,
                    ],
                ],
            ],

            'crm_pipeline_summary' => [
                'description' => __('Сводка воронки CRM: открытые сделки и суммы по стадиям. Только чтение.'),
                'permission' => 'crm.view',
                'critical' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
                'handler' => fn (Employee $asker, array $args): array => $this->crmPipelineSummary($asker),
            ],

            'crm_clients_search' => [
                'description' => 'Поиск клиентов CRM по названию, ИНН, телефону или e-mail. Возвращает до 5 записей со ссылками.',
                'permission' => 'crm.view',
                'critical' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => __('Поисковый запрос')],
                    ],
                    'required' => ['query'],
                ],
                'handler' => fn (Employee $asker, array $args): array => $this->crmClientsSearch($asker, (string) $args['query']),
            ],

            'finance_summary' => [
                'description' => 'Финансовая сводка компании за месяц (по умолчанию текущий): доходы, расходы, прибыль, баланс, неоплаченные счета. Только чтение.',
                'permission' => 'finance.view',
                'critical' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'month' => ['type' => 'string', 'description' => __('Месяц YYYY-MM; по умолчанию текущий')],
                    ],
                    'required' => [],
                ],
                'handler' => fn (Employee $asker, array $args): array => $this->financeSummary($asker, isset($args['month']) ? (string) $args['month'] : null),
            ],

            'inventory_stock_summary' => [
                'description' => 'Остатки склада: число товаров, позиции ниже минимума и топ товаров по остатку. Только чтение.',
                'permission' => 'inventory.view',
                'critical' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
                'handler' => fn (Employee $asker, array $args): array => $this->inventoryStockSummary($asker),
            ],

            'projects_summary' => [
                'description' => 'Портфель проектов: открытые проекты, статус, прогресс по задачам и бюджет план/факт. Только чтение.',
                'permission' => 'projects.view',
                'critical' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
                'handler' => fn (Employee $asker, array $args): array => $this->projectsSummary($asker),
            ],

            'accounting_trial_balance' => [
                'description' => 'Оборотно-сальдовая ведомость за месяц: итоги дебета/кредита и топ счетов по обороту. Только чтение.',
                'permission' => 'accounting.view',
                'critical' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'month' => ['type' => 'string', 'description' => __('Месяц YYYY-MM; по умолчанию текущий')],
                    ],
                    'required' => [],
                ],
                'handler' => fn (Employee $asker, array $args): array => $this->accountingTrialBalance($asker, isset($args['month']) ? (string) $args['month'] : null),
            ],

            'bi_metrics_summary' => [
                'description' => 'Сводка витрины BI за месяц: ключевые метрики и прогноз доходов на следующий месяц. Только чтение.',
                'permission' => 'bi.view',
                'critical' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'month' => ['type' => 'string', 'description' => __('Месяц YYYY-MM; по умолчанию текущий')],
                    ],
                    'required' => [],
                ],
                'handler' => fn (Employee $asker, array $args): array => $this->biMetricsSummary($asker, isset($args['month']) ? (string) $args['month'] : null),
            ],
        ];
    }

    /* -------------------- валидация -------------------- */

    /**
     * Проверка аргументов по схеме (JSON Schema в упрощённом виде).
     * Возвращает текст ошибки или null, если аргументы корректны.
     */
    protected function validate(array $schema, array $args): ?string
    {
        $properties = $schema['properties'] ?? [];

        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $args) || $args[$key] === null || $args[$key] === '') {
                return 'missing:'.$key;
            }
        }

        foreach ($args as $key => $value) {
            if (! isset($properties[$key])) {
                return 'unexpected:'.$key; // посторонние ключи — отказ, а не тихое игнорирование
            }

            $type = $properties[$key]['type'] ?? 'string';
            $matches = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                default => is_scalar($value),
            };

            if (! $matches) {
                return 'type:'.$key;
            }

            if (is_string($value) && mb_strlen($value) > 500) {
                return 'length:'.$key;
            }
        }

        return null;
    }

    /**
     * Отказ с аудитом (invalid/forbidden/failed).
     *
     * @return array{ok: bool, result: mixed, error: ?string}
     */
    protected function deny(Employee $asker, string $action, string $name, mixed $args, string $error): array
    {
        $this->audit->log($asker->user, $action, 'ai_tool', null, null, [
            'tool' => $name,
            'args' => is_array($args) ? $args : ['raw' => (string) $args],
            'error' => $error,
        ]);

        return ['ok' => false, 'result' => null, 'error' => $error];
    }

    /* -------------------- обработчики -------------------- */

    /**
     * Поиск документов в пределах компании и scope documents.view
     * (та же логика, что в intentSearch/RAG — с явной группировкой OR).
     */
    protected function searchDocuments(Employee $asker, string $query): array
    {
        $documents = BumiRecords::documents($asker)
            ->where(fn ($w) => $w->where('title', 'like', "%{$query}%")
                ->orWhere('document_number', 'like', "%{$query}%")
                ->orWhere('reg_number', 'like', "%{$query}%"))
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return [
            'count' => $documents->count(),
            'items' => $documents->map(fn (Document $document): array => [
                'title' => (string) $document->title,
                'number' => (string) $document->document_number,
                'url' => route('documents.show', $document),
            ])->all(),
        ];
    }

    /** Поиск задач в пределах компании и scope tasks.view. */
    protected function searchTasks(Employee $asker, string $query): array
    {
        $tasks = BumiRecords::tasks($asker)
            ->where(fn ($w) => $w->where('title', 'like', "%{$query}%")
                ->orWhere('number', 'like', "%{$query}%"))
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return [
            'count' => $tasks->count(),
            'items' => $tasks->map(fn (Task $task): array => [
                'title' => (string) $task->title,
                'status' => $task->status->value,
                'url' => route('tasks.show', $task),
            ])->all(),
        ];
    }

    /** Сводка посещаемости за сегодня в scope attendance.view. */
    protected function attendanceSummary(Employee $asker): array
    {
        $access = $this->analytics->accessibleEmployees($asker->user, 'attendance.view');

        return [
            'date' => today()->toDateString(),
            'scope' => $access['scope_value'],
        ] + $this->analytics->attendanceSummary($access['employees']);
    }

    /** Статистика задач по статусам в scope analytics.view. */
    protected function taskStats(Employee $asker): array
    {
        $access = $this->analytics->accessibleEmployees($asker->user, 'analytics.view');

        return [
            'scope' => $access['scope_value'],
            'tasks' => $this->analytics->taskStats($access['employees'], exclusive: true),
        ];
    }

    /** Конфигурационный kill-switch (corpms.ai.tools). */
    /** Сводка воронки CRM: открытые сделки и суммы по стадиям. */
    protected function crmPipelineSummary(Employee $asker): array
    {
        $rows = CrmDeal::query()
            ->where('company_id', $asker->company_id)
            ->selectRaw('stage, COUNT(*) as total, SUM(amount) as amount')
            ->groupBy('stage')
            ->get();

        $open = CrmDeal::query()
            ->where('company_id', $asker->company_id)
            ->open()
            ->selectRaw('COUNT(*) as total, SUM(amount) as amount')
            ->first();

        return [
            'open_deals' => (int) ($open->total ?? 0),
            'open_amount' => (float) ($open->amount ?? 0),
            'stages' => $rows->map(static function ($row): array {
                $stage = $row->stage instanceof CrmDealStage ? $row->stage->value : (string) $row->stage;

                return [
                    'stage' => $stage,
                    'count' => (int) $row->total,
                    'amount' => (float) $row->amount,
                ];
            })->all(),
            'url' => route('crm.deals.index'),
        ];
    }

    /** Поиск клиентов CRM по названию/ИНН/телефону/e-mail. */
    protected function crmClientsSearch(Employee $asker, string $query): array
    {
        $clients = CrmClient::query()
            ->where('company_id', $asker->company_id)
            ->where(static fn ($sub) => $sub
                ->where('name', 'like', "%{$query}%")
                ->orWhere('inn', 'like', "%{$query}%")
                ->orWhere('phone', 'like', "%{$query}%")
                ->orWhere('email', 'like', "%{$query}%"))
            ->orderBy('name')
            ->limit(5)
            ->get();

        return [
            'count' => $clients->count(),
            'items' => $clients->map(static fn (CrmClient $client): array => [
                'name' => (string) $client->name,
                'status' => $client->status->value,
                'url' => route('crm.clients.show', $client),
            ])->all(),
        ];
    }

    /** Финансовая сводка компании за месяц. */
    protected function financeSummary(Employee $asker, ?string $month): array
    {
        $month = $this->monthBounds($month)[0];

        $metrics = $this->finance->metrics((int) $asker->company_id, $month);

        return [
            'month' => $month,
            'income' => $metrics['income'],
            'expense' => $metrics['expense'],
            'profit' => $metrics['profit'],
            'balance' => $metrics['balance'],
            'unpaid_invoices' => $metrics['unpaid'],
            'currency' => $metrics['currency'],
            'by_currency' => $metrics['by_currency'],
            'currency_note' => $metrics['mixed_currencies']
                ? 'Totals across currencies are unavailable. Use by_currency. Legacy invoices and budgets have no assigned currency; do not infer or convert their amounts.'
                : 'Amounts use the ledger currency. No exchange conversion has been applied.',
            'url' => route('finance.transactions.index', ['month' => $month]),
        ];
    }

    /** Остатки склада и позиции ниже минимума. */
    protected function inventoryStockSummary(Employee $asker): array
    {
        $products = $this->inventory->productsWithStock((int) $asker->company_id, onlyGoods: true);

        $low = $products->filter(
            static fn ($product): bool => $product->min_stock > 0
                && (float) $product->getAttribute('stock_total') < (float) $product->min_stock,
        );

        $items = ($low->isNotEmpty() ? $low : $products)
            ->take(5)
            ->map(static fn ($product): array => [
                'name' => (string) $product->name,
                'sku' => (string) $product->sku,
                'stock' => (float) $product->getAttribute('stock_total'),
                'min' => (float) $product->min_stock,
                'unit' => (string) $product->unit,
            ])
            ->values();

        return [
            'products' => $products->count(),
            'low_stock' => $low->count(),
            'items' => $items->all(),
            'url' => route('inventory.index'),
        ];
    }

    /** Портфель открытых проектов с прогрессом и бюджетом. */
    protected function projectsSummary(Employee $asker): array
    {
        $projects = Project::query()
            ->where('company_id', $asker->company_id)
            ->open()
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return [
            'open_projects' => Project::query()
                ->where('company_id', $asker->company_id)
                ->open()
                ->count(),
            'items' => $projects->map(function (Project $project): array {
                $progress = $this->projects->progress($project);
                $budget = $this->projects->budget($project);

                return [
                    'name' => (string) $project->name,
                    'status' => $project->status->value,
                    'progress' => $progress['percent'],
                    'budget' => $budget['planned'],
                    'spent' => $budget['spent'],
                    'url' => route('projects.show', $project),
                ];
            })->all(),
            'url' => route('projects.index'),
        ];
    }

    /** Оборотно-сальдовая ведомость за месяц: итоги и топ счетов. */
    protected function accountingTrialBalance(Employee $asker, ?string $month): array
    {
        [$month, $from, $to] = $this->monthBounds($month);

        $rows = $this->accounting->trialBalance((int) $asker->company_id, $from, $to);

        $debit = (float) $rows->sum(static fn ($row): float => (float) $row->debit_turnover);
        $credit = (float) $rows->sum(static fn ($row): float => (float) $row->credit_turnover);

        $accounts = $rows
            ->filter(static fn ($row): bool => ((float) $row->debit_turnover + (float) $row->credit_turnover) > 0)
            ->sortByDesc(static fn ($row): float => (float) $row->debit_turnover + (float) $row->credit_turnover)
            ->take(5)
            ->map(static fn ($row): array => [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'debit' => (float) $row->debit_turnover,
                'credit' => (float) $row->credit_turnover,
            ])
            ->values();

        return [
            'month' => $month,
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
            'accounts' => $accounts->all(),
            'url' => route('accounting.reports.index', ['month' => $month]),
        ];
    }

    /** Сводка витрины BI и прогноз доходов. */
    protected function biMetricsSummary(Employee $asker, ?string $month): array
    {
        [$month, $from, $to] = $this->monthBounds($month);

        $forecast = $this->bi->forecast((int) $asker->company_id, 'finance_income', 1);

        return [
            'month' => $month,
            'metrics' => $this->bi->totals((int) $asker->company_id, $from, $to),
            'income_forecast' => $forecast[0] ?? null,
            'url' => route('bi.index', ['month' => $month]),
        ];
    }

    /**
     * Границы месяца по строке YYYY-MM (невалидное значение — текущий месяц).
     *
     * @return array{0:string, 1:string, 2:string}
     */
    protected function monthBounds(?string $month): array
    {
        $month = $month !== null && preg_match('/^\d{4}-\d{2}$/', $month) === 1
            ? $month
            : now()->format('Y-m');

        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01');

        return [$month, $start->startOfMonth()->toDateString(), $start->endOfMonth()->toDateString()];
    }

    protected function enabled(): bool
    {
        return (bool) config('corpms.ai.tools', true);
    }
}
