<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Accounting\Services\AccountingService;
use App\Domains\Analytics\Services\AnalyticsService;
use App\Domains\Audit\Services\AuditService;
use App\Domains\Bi\Services\BiService;
use App\Domains\Crm\Services\CrmService;
use App\Domains\DashboardGen\QuerySpec\BiQueryCompiler;
use App\Domains\DashboardGen\QuerySpec\BiQuerySpec;
use App\Domains\DashboardGen\Services\BiToolContext;
use App\Domains\DashboardGen\Services\SchemaInspector;
use App\Domains\DashboardGen\Services\WidgetCatalog;
use App\Domains\DashboardGen\Services\WidgetShapeMapper;
use App\Domains\DashboardGen\Services\WidgetSpecCompiler;
use App\Domains\DashboardGen\Services\WidgetSpecValidator;
use App\Domains\DashboardGen\Services\WidgetQueryRunner;
use App\Domains\Finance\Services\FinanceService;
use App\Domains\Inventory\Services\InventoryService;
use App\Domains\Projects\Services\ProjectService;
use App\Models\BiDashboard;
use App\Models\BiWidget;
use App\Models\CrmClient;
use App\Models\CrmDeal;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Shared\Enums\CrmDealStage;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

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
    public function toolsFor(Employee $asker, ?string $group = null): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $tools = [];
        foreach ($this->definitions() as $name => $definition) {
            // BI-инструменты меняют дашборд и доступны только в контексте /bi.
            $toolGroup = $definition['group'] ?? null;
            if ($group === 'bi' && $toolGroup !== 'bi') {
                continue;
            }
            if ($group !== 'bi' && $toolGroup === 'bi') {
                continue;
            }

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
    public function execute(Employee $asker, string $name, mixed $arguments, ?BiToolContext $context = null): array
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
            $result = ($definition['handler'])($asker, $args, $context);
        } catch (AuthorizationException) {
            return $this->deny($asker, 'ai.tool.denied', $name, $args, 'forbidden');
        } catch (RuntimeException $e) {
            // BI-инструменты объясняют отказ (неизвестное семейство, плохой
            // фильтр и т.п.) — передаём текст модели и в аудит, и в ответ.
            $detail = trim($e->getMessage());

            return $this->deny($asker, 'ai.tool.invalid', $name, $args, $detail !== '' ? mb_substr($detail, 0, 300) : 'failed');
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
     *     group?: string,
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

            // ---- Инструменты Bumi в режиме /bi (только с контекстом дашборда) ----

            'add_widget' => [
                'description' => __('Добавить виджет на текущий BI-дашборд. spec — структурированная спецификация запроса (queries/shape/presentation), не SQL.'),
                'permission' => 'bi.manage@company',
                'critical' => true,
                'group' => 'bi',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => __('Название виджета')],
                        'family' => ['type' => 'string', 'description' => __('Семейство виджета из каталога')],
                        'type' => ['type' => 'string', 'description' => __('Вариант отрисовки')],
                        'spec' => ['type' => 'object', 'description' => __('Структурированная спецификация запроса')],
                        'span' => ['type' => 'integer', 'description' => __('Ширина виджета: 4, 6 или 12')],
                    ],
                    'required' => ['title', 'family', 'spec'],
                ],
                'handler' => fn (Employee $asker, array $args, ?BiToolContext $context): array => $this->biAddWidget($asker, $args, $context),
            ],

            'update_widget' => [
                'description' => __('Изменить название, семейство, вариант отрисовки, спецификацию запроса или фильтры существующего виджета текущего BI-дашборда.'),
                'permission' => 'bi.manage@company',
                'critical' => true,
                'group' => 'bi',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'widget_id' => ['type' => 'integer', 'description' => __('ID виджета текущего дашборда')],
                        'title' => ['type' => 'string'],
                        'family' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                        'spec' => ['type' => 'object'],
                        'filters' => ['type' => 'array'],
                    ],
                    'required' => ['widget_id'],
                ],
                'handler' => fn (Employee $asker, array $args, ?BiToolContext $context): array => $this->biUpdateWidget($asker, $args, $context),
            ],

            'set_filter' => [
                'description' => __('Установить фильтры дашборда или отдельного виджета. Массив объектов {column, op, value}. Передавайте widget_id только чтобы отфильтровать один виджет.'),
                'permission' => 'bi.manage@company',
                'critical' => true,
                'group' => 'bi',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'filters' => ['type' => 'array', 'description' => __('Список фильтров')],
                        'widget_id' => ['type' => 'integer', 'description' => __('ID виджета; без него фильтр применяется к дашборду')],
                    ],
                    'required' => ['filters'],
                ],
                'handler' => fn (Employee $asker, array $args, ?BiToolContext $context): array => $this->biSetFilter($asker, $args, $context),
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
                'object', 'array' => is_array($value),
                default => is_scalar($value),
            };

            if (! $matches) {
                return 'type:'.$key;
            }

            if (is_string($value) && mb_strlen($value) > 500) {
                return 'length:'.$key;
            }

            if (is_array($value) && ! $this->withinArrayLimits($value)) {
                return 'length:'.$key;
            }
        }

        return null;
    }

    /**
     * Ограничиваем структурированные аргументы (spec/filters) по размеру
     * и глубине: модель не должна иметь возможность прислать multi-MB JSON.
     *
     * @param  array<mixed>  $value
     */
    private function withinArrayLimits(array $value, int $depth = 0): bool
    {
        if ($depth > 10 || count($value) > 50) {
            return false;
        }

        foreach ($value as $entry) {
            if (is_array($entry) && ! $this->withinArrayLimits($entry, $depth + 1)) {
                return false;
            }
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

        return $encoded !== false && strlen($encoded) <= 20000;
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

    /* -------------------- инструменты Bumi /bi -------------------- */

    /** Дашборд берётся из контекста маршрута, а не из аргументов модели. */
    protected function requireBiDashboard(Employee $asker, ?BiToolContext $context): BiDashboard
    {
        if ($context === null) {
            throw new RuntimeException('context_required');
        }

        $dashboard = $context->dashboard;
        TenantGuard::ensure($dashboard, $asker);
        Gate::authorize('update', $dashboard);

        return $dashboard;
    }

    /**
     * Скомпилировать структурированную спецификацию и проверить её пробым
     * запросом. Модель никогда не передаёт готовый SQL.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    protected function compileWidgetSpec(Employee $asker, array $raw, string $family, ?string $type): array
    {
        $catalog = app(WidgetCatalog::class);

        if (! $catalog->hasFamily($family)) {
            throw new RuntimeException('unknown_family');
        }
        if (! $catalog->hasType($family, $type)) {
            throw new RuntimeException('unknown_type');
        }

        $shape = $raw['shape'] ?? WidgetShapeMapper::shapeFor($family);
        if (! is_string($shape) || ! in_array($shape, WidgetShapeMapper::SHAPES, true)) {
            throw new RuntimeException('unknown_shape');
        }

        $queries = $raw['queries'] ?? $raw['query'] ?? null;
        if (! is_array($queries) || $queries === []) {
            throw new RuntimeException('missing_queries');
        }

        $structured = [
            'queries' => $queries,
            'shape' => $shape,
            'presentation' => is_array($raw['presentation'] ?? null) ? $raw['presentation'] : [],
        ];

        $compiler = $this->widgetSpecCompiler();
        $spec = $compiler->compile($structured);
        $schema = $compiler->schema();

        $validator = new WidgetSpecValidator(
            new WidgetQueryRunner(companyId: (int) $asker->company_id),
            $schema,
            (array) config('dashboard_gen.tables', []),
        );
        $check = $validator->validate($spec, $family, $type);

        if (! ($check['ok'] ?? false)) {
            throw new RuntimeException(implode(' ', $check['errors']));
        }

        return $spec;
    }

    protected function widgetSpecCompiler(): WidgetSpecCompiler
    {
        return WidgetSpecCompiler::forCurrentConnection();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function biAddWidget(Employee $asker, array $args, ?BiToolContext $context): array
    {
        $dashboard = $this->requireBiDashboard($asker, $context);
        $family = (string) ($args['family'] ?? '');
        $type = isset($args['type']) && $args['type'] !== '' ? (string) $args['type'] : null;
        $spec = $this->compileWidgetSpec($asker, (array) ($args['spec'] ?? []), $family, $type);

        if ($dashboard->widgets()->count() >= (int) config('dashboard_gen.max_widgets', 12)) {
            throw new RuntimeException('too_many_widgets');
        }

        $span = (int) ($args['span'] ?? 4);
        $span = in_array($span, [4, 6, 12], true) ? $span : 4;

        $widget = $dashboard->widgets()->create([
            'title' => mb_substr((string) $args['title'], 0, 120),
            'widget_family' => $family,
            'widget_type' => $type,
            'content_mode' => 'sql',
            'query_spec' => $spec,
            'span' => $span,
            'status' => 'ready',
            'position' => (int) $dashboard->widgets()->max('position') + 1,
        ]);

        $dashboard->increment('version');
        $context->capture([
            'kind' => 'delete_widget',
            'widget_id' => (int) $widget->id,
        ]);

        return [
            'kind' => 'widget',
            'widget_id' => (int) $widget->id,
            'title' => (string) $widget->title,
            'family' => $family,
            'text' => 'Виджет «'.$widget->title.'» добавлен на дашборд.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function biUpdateWidget(Employee $asker, array $args, ?BiToolContext $context): array
    {
        $dashboard = $this->requireBiDashboard($asker, $context);
        $widget = $dashboard->widgets()->findOrFail((int) $args['widget_id']);

        $before = $widget->only([
            'title', 'widget_family', 'widget_type', 'query_spec', 'filters', 'span',
        ]);

        $family = isset($args['family']) ? (string) $args['family'] : (string) $widget->widget_family;
        $type = array_key_exists('type', $args)
            ? ($args['type'] !== '' ? (string) $args['type'] : null)
            : $widget->widget_type;

        $attributes = [];

        if (array_key_exists('title', $args)) {
            $attributes['title'] = mb_substr((string) $args['title'], 0, 120);
        }

        if (array_key_exists('spec', $args)) {
            $attributes['query_spec'] = $this->compileWidgetSpec($asker, (array) $args['spec'], $family, $type);
            $attributes['content_mode'] = 'sql';
        }

        if (array_key_exists('family', $args)) {
            $attributes['widget_family'] = $family;
        }
        if (array_key_exists('type', $args)) {
            $attributes['widget_type'] = $type;
        }
        if (array_key_exists('filters', $args)) {
            $attributes['filters'] = $this->normalizeFilters($args['filters']);
        }

        if ($attributes === []) {
            throw new RuntimeException('nothing_to_update');
        }

        $widget->forceFill($attributes)->save();
        $dashboard->increment('version');
        $context->capture([
            'kind' => 'restore_widget',
            'widget_id' => (int) $widget->id,
            'attributes' => $before,
        ]);

        return [
            'kind' => 'widget',
            'widget_id' => (int) $widget->id,
            'title' => (string) $widget->title,
            'text' => 'Виджет «'.$widget->title.'» обновлён.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function biSetFilter(Employee $asker, array $args, ?BiToolContext $context): array
    {
        $dashboard = $this->requireBiDashboard($asker, $context);
        $filters = $this->normalizeFilters($args['filters'] ?? []);

        if (isset($args['widget_id'])) {
            $widget = $dashboard->widgets()->findOrFail((int) $args['widget_id']);
            $before = $widget->filters ?? [];
            $widget->forceFill(['filters' => $filters])->save();

            $context->capture([
                'kind' => 'restore_filters',
                'target' => 'widget',
                'widget_id' => (int) $widget->id,
                'filters' => $before,
            ]);

            return [
                'kind' => 'filter',
                'widget_id' => (int) $widget->id,
                'text' => 'Фильтр виджета «'.$widget->title.'» обновлён.',
            ];
        }

        $before = $dashboard->filters ?? [];
        $dashboard->forceFill(['filters' => $filters])->save();
        $dashboard->increment('version');
        $context->capture([
            'kind' => 'restore_filters',
            'target' => 'dashboard',
            'filters' => $before,
        ]);

        return [
            'kind' => 'filter',
            'text' => count($filters) === 0 ? 'Фильтры дашборда сброшены.' : 'Фильтры дашборда применены.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function normalizeFilters(mixed $filters): array
    {
        if (! is_array($filters)) {
            throw new RuntimeException('invalid_filters');
        }

        $result = [];

        foreach (array_slice($filters, 0, 12) as $filter) {
            if (! is_array($filter)) {
                throw new RuntimeException('invalid_filters');
            }

            $column = (string) ($filter['column'] ?? '');
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $column) !== 1) {
                throw new RuntimeException('invalid_filter_column');
            }

            $op = strtolower(trim((string) ($filter['op'] ?? $filter['operator'] ?? '=')));
            if (! in_array($op, BiQuerySpec::OPERATORS, true)) {
                throw new RuntimeException('invalid_filter_operator');
            }

            $item = ['column' => $column, 'op' => $op];

            if (! in_array($op, ['is null', 'is not null'], true)) {
                $value = $filter['value'] ?? null;

                if (is_array($value)) {
                    $item['value'] = array_values(array_map(
                        static fn (mixed $entry): mixed => is_scalar($entry) ? $entry : null,
                        array_slice($value, 0, 30),
                    ));
                } elseif (is_scalar($value)) {
                    $item['value'] = $value;
                } else {
                    throw new RuntimeException('invalid_filter_value');
                }
            }

            $result[] = $item;
        }

        return $result;
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
