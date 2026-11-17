<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Analytics\Services\AnalyticsService;
use App\Domains\Audit\Services\AuditService;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Task;
use App\Support\PermissionService;
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

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $definition['description'],
                    'parameters' => $definition['schema'],
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
                        'query' => ['type' => 'string', 'description' => 'Поисковый запрос'],
                    ],
                    'required' => ['query'],
                ],
                'handler' => fn (Employee $asker, array $args): array => $this->searchDocuments($asker, (string) $args['query']),
            ],

            'search_tasks' => [
                'description' => 'Поиск задач компании по названию. Возвращает заголовок, статус и ссылку.',
                'permission' => 'tasks.view',
                'critical' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Поисковый запрос'],
                    ],
                    'required' => ['query'],
                ],
                'handler' => fn (Employee $asker, array $args): array => $this->searchTasks($asker, (string) $args['query']),
            ],

            'attendance_summary' => [
                'description' => 'Сводка посещаемости за сегодня в области доступа вызывающего.',
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
                'description' => 'Статистика задач по статусам в области доступа вызывающего.',
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
                        'title' => ['type' => 'string', 'description' => 'Название задачи'],
                        'description' => ['type' => 'string', 'description' => 'Описание задачи'],
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
        $access = $this->analytics->accessibleEmployees($asker->user, 'documents.view');
        $ids = $access['employees']->pluck('id')->all();

        $documents = Document::query()
            ->where('company_id', $asker->company_id)
            ->where(fn ($w) => $w->where('title', 'like', "%{$query}%")
                ->orWhere('document_number', 'like', "%{$query}%")
                ->orWhere('reg_number', 'like', "%{$query}%"))
            ->when($access['scope_value'] !== 'company', fn ($w) => $w
                ->where(fn ($scope) => $scope->whereIn('author_id', $ids)
                    ->orWhereHas('signatureRequests', fn ($signature) => $signature->whereIn('signer_id', $ids))))
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
        $access = $this->analytics->accessibleEmployees($asker->user, 'tasks.view');
        $ids = $access['employees']->pluck('id')->all();

        $tasks = Task::query()
            ->where('company_id', $asker->company_id)
            ->where(fn ($w) => $w->where('title', 'like', "%{$query}%")
                ->orWhere('number', 'like', "%{$query}%"))
            ->when($access['scope_value'] !== 'company', fn ($w) => $w
                ->whereHas('assignments', fn ($assignment) => $assignment->whereIn('employee_id', $ids)->limit(1)))
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
            'tasks' => $this->analytics->taskStats($access['employees']),
        ];
    }

    /** Конфигурационный kill-switch (corpms.ai.tools). */
    protected function enabled(): bool
    {
        return (bool) config('corpms.ai.tools', true);
    }
}
