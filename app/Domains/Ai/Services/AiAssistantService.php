<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Ai\Jobs\RetryAiAnswer;
use App\Domains\Analytics\Services\AnalyticsService;
use App\Domains\Audit\Services\AuditService;
use App\Models\AiConversation;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Task;
use App\Support\PermissionService;
use App\Support\SettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * AI-ассистент аналитики (п.68 ТЗ): вопросы по данным компании.
 *
 * Принцип grounded: ответ строится только на собранных фактах (scope вызывающего).
 * Если настроен OpenAI-совместимый LLM (corpms.ai.*) — факты передаются в промпт,
 * иначе работает встроенный локальный аналитик (детерминированный разбор фактов).
 * Каждый вопрос-ответ сохраняется в ai_conversations.
 */
class AiAssistantService
{
    public function __construct(
        protected AnalyticsService $analytics,
        protected PermissionService $permissions,
        protected AuditService $audit,
        protected DocumentRagService $rag,
        protected AiToolRegistry $tools,
    ) {}

    public function ask(Employee $asker, string $question): AiConversation
    {
        $facts = $this->collectFacts($asker);
        // память диалога: предыдущие реплики уходят в контекст LLM (S10)
        $history = $this->historyMessages($asker);
        // D4 (RAG): релевантные фрагменты документов компании
        $rag = $this->ragContext($asker, $question);

        $answer = null;
        $usedLlm = $this->llmEnabled() && $this->llmAnswer($facts, $question, $history, $answer, false, $rag, $asker);

        if (! $usedLlm) {
            $answer = $this->localAnalyst($facts, $question);
        }

        $conversation = AiConversation::create([
            'employee_id' => $asker->id,
            'question' => $question,
            'answer' => $answer,
            'query_used' => $facts,
            // честный флаг: true — ответил LLM на фактах, false — локальный фолбэк
            'grounded' => $usedLlm,
        ]);

        if ($asker->user !== null) {
            $this->audit->log($asker->user, 'ai.asked', 'ai_conversation', $conversation->id, null, [
                'question' => $question,
                'llm' => $usedLlm,
            ]);
        }

        // D3: LLM настроен, но синхронно не ответил — фоновый повтор в очереди
        if (! $usedLlm) {
            $this->queueRetry($conversation);
        }

        return $conversation;
    }

    public function historyFor(Employee $asker, int $limit = 20, ?int $excludeId = null): Collection
    {
        return AiConversation::query()
            ->where('employee_id', $asker->id)
            ->when($excludeId !== null, fn ($query) => $query->where('id', '!=', $excludeId))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Последние реплики диалога как messages для LLM (хронологически, старые раньше).
     *
     * @return array<int, array{role: string, content: string}>
     */
    protected function historyMessages(Employee $asker, int $limit = 6, ?int $excludeId = null): array
    {
        $messages = [];

        foreach ($this->historyFor($asker, $limit, $excludeId)->reverse() as $item) {
            $messages[] = ['role' => 'user', 'content' => (string) $item->question];
            $messages[] = ['role' => 'assistant', 'content' => (string) $item->answer];
        }

        return $messages;
    }

    /**
     * D4 (RAG): фрагменты документов для промпта LLM. Сбои поиска/индексации
     * не должны ломать ответ — тогда контекст документов просто пустой.
     *
     * @return array<int, array{document_id: int, title: string, number: string, score: float, content: string}>
     */
    protected function ragContext(Employee $asker, string $question): array
    {
        if (! $this->llmEnabled()) {
            return []; // без LLM контекст документов не используется — не тратим запросы
        }

        try {
            return $this->rag->retrieve($asker, $question);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Блок «Релевантные фрагменты документов» для системного промпта.
     *
     * @param  array<int, array{document_id: int, title: string, number: string, score: float, content: string}>  $rag
     */
    protected function ragSystemMessage(array $rag): string
    {
        if ($rag === []) {
            return '';
        }

        $lines = [];
        foreach ($rag as $index => $item) {
            $lines[] = sprintf(
                '%d. %s (%s): %s',
                $index + 1,
                $item['title'],
                $item['number'],
                $item['content'],
            );
        }

        return "\nРелевантные фрагменты документов компании (используй, только если они относятся к вопросу):\n"
            .implode("\n", $lines);
    }

    /* -------------------- факты -------------------- */

    /**
     * Факты в пределах scope analytics.view (или личные данные, если права нет).
     */
    protected function collectFacts(Employee $asker): array
    {
        $canScope = $this->permissions->check($asker->user, 'analytics.view') !== false;

        if ($canScope) {
            $access = $this->analytics->accessibleEmployees($asker->user, 'analytics.view');
            $employees = $access['employees'];

            return [
                'scope' => $access['scope_value'],
                'headcount' => $this->analytics->headcount($employees),
                'attendance_today' => $this->analytics->attendanceSummary($employees),
                'tasks' => $this->analytics->taskStats($employees),
                'leaves' => $this->analytics->leaveStats($employees),
            ];
        }

        // нет права на общую аналитику — отвечаем только по себе
        $ownToday = AttendanceRecord::query()
            ->where('employee_id', $asker->id)
            ->whereDate('date', today())
            ->first();

        $ownTasks = Task::query()
            ->whereHas('assignments', fn ($q) => $q->where('employee_id', $asker->id))
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return [
            'scope' => 'own',
            'my_attendance_today' => $ownToday === null ? 'нет отметки' : [
                'status' => $ownToday->status->value,
                'clock_in_at' => $ownToday->clock_in_at?->format('H:i'),
                'late_minutes' => $ownToday->late_minutes,
            ],
            'my_tasks' => $ownTasks->all(),
        ];
    }

    /* -------------------- LLM -------------------- */

    protected function llmEnabled(): bool
    {
        $settings = app(SettingsService::class);

        return $settings->get('corpms.ai.base_url') !== ''
            && $settings->get('corpms.ai.api_key') !== '';
    }

    /**
     * Запрос к OpenAI-совместимому API с историей диалога в контексте.
     * D5 (tool calling): один раунд вызова инструментов — ответ модели с
     * tool_calls исполняется через AiToolRegistry, результат добавляется в
     * сообщения, затем следует второй запрос за финальным текстом.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @param  array<int, array{document_id: int, title: string, number: string, score: float, content: string}>  $rag
     *
     * @param-out string|null $answerText
     */
    protected function llmAnswer(array $facts, string $question, array $history = [], ?string &$answerText = null, bool $background = false, array $rag = [], ?Employee $asker = null): bool
    {
        $settings = app(SettingsService::class);
        $baseUrl = rtrim((string) $settings->get('corpms.ai.base_url'), '/');
        $apiKey = (string) $settings->get('corpms.ai.api_key');
        $model = (string) ($settings->get('corpms.ai.model') ?? 'gpt-4o-mini');
        $timeout = (int) ($settings->get('corpms.ai.timeout') ?? 30);
        if (! $background) {
            $timeout = $this->syncTimeout($timeout); // D3: синхронный вызов не ждёт дольше потолка
        }

        $system = 'Ты ассистент HR-аналитики корпоративной системы. '
            .'Отвечай только на основе приведённых фактов; если фактов не хватает — скажи об этом. '
            .'Учитывай предыдущие реплики диалога: уточнения и местоимения относи к уже заданным вопросам.'
            .$this->ragSystemMessage($rag);

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'system', 'content' => 'Факты: '.json_encode($facts, JSON_UNESCAPED_UNICODE)],
            ...array_slice($history, -12),
            ['role' => 'user', 'content' => $question],
        ];

        $response = $this->postWithTools($messages, $baseUrl, $apiKey, $model, $timeout, $asker);
        if ($response === null) {
            return false;
        }

        $content = $response['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            return false;
        }

        $answerText = trim($content);

        return true;
    }

    /**
     * POST /chat/completions с одним раундом tool calling (D5).
     *
     * Возвращает декодированное финальное сообщение модели или null при сбое.
     * Инструменты передаются только вызывающему, у которого есть права;
     * критические операции возвращают подтверждение (без записи в БД).
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<string, mixed>|null
     */
    protected function postWithTools(array $messages, string $baseUrl, string $apiKey, string $model, int $timeout, ?Employee $asker): ?array
    {
        $tools = $asker !== null ? $this->tools->toolsFor($asker) : [];

        $payload = ['model' => $model, 'messages' => $messages];
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout($timeout)
                ->post($baseUrl.'/chat/completions', $payload);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $message = $response->json('choices.0.message');
        if (! is_array($message)) {
            return null;
        }

        $toolCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];
        if ($toolCalls === [] || $tools === [] || $asker === null) {
            return $message;
        }

        // один раунд инструментов: выполняем вызовы модели и уходим за финальным ответом
        $messages[] = [
            'role' => 'assistant',
            'content' => $message['content'] ?? '',
            'tool_calls' => $toolCalls,
        ];

        foreach ($toolCalls as $call) {
            $name = (string) data_get($call, 'function.name', '');
            $rawArguments = data_get($call, 'function.arguments', []);
            $arguments = is_string($rawArguments) ? json_decode($rawArguments, true) : $rawArguments;

            $result = $this->tools->execute($asker, $name, is_array($arguments) ? $arguments : []);

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => (string) data_get($call, 'id', ''),
                'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
            ];
        }

        // финальный запрос уже без tools — защита от зацикливания
        try {
            $response = Http::withToken($apiKey)
                ->timeout($timeout)
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $final = $response->json('choices.0.message');

        return is_array($final) ? $final : null;
    }

    /* -------------------- D3: потолок ожидания и повтор в очереди -------------------- */

    /**
     * Потолок синхронного ожидания LLM, сек (corpms.ai.sync_timeout).
     * 0/отрицательное значение — без потолка; в фоновом job не применяется.
     */
    public function syncTimeout(int $configured): int
    {
        $cap = (int) config('corpms.ai.sync_timeout', 8);

        return $cap > 0 ? min($configured, $cap) : $configured;
    }

    /**
     * LLM настроен, но синхронный вызов не дал grounded-ответа —
     * повтор уходит в очередь (worker `php artisan queue:work`).
     */
    protected function queueRetry(AiConversation $conversation, bool $panel = false): void
    {
        if (! $this->llmEnabled()) {
            return;
        }

        try {
            RetryAiAnswer::dispatch($conversation->id, $panel);
        } catch (Throwable) {
            // очередь недоступна — ответ пользователя уже готов, запрос не роняем
        }
    }

    /**
     * Фоновый повтор LLM-вызова (D3): обновляет answer и grounded диалога.
     *
     * @return bool true — LLM ответил, диалог обновлён
     */
    public function retryLlm(AiConversation $conversation): bool
    {
        $employee = Employee::find($conversation->employee_id);
        if ($employee === null || ! $this->llmEnabled()) {
            return false;
        }

        $facts = $this->collectFacts($employee);
        // текущий диалог исключаем из истории — иначе его фолбэк-ответ
        // попадёт в контекст повторного вызова
        $history = $this->historyMessages($employee, 6, $conversation->id);

        $update = $this->retryLlmUpdate($conversation, $facts, $history);
        if ($update === null) {
            return false;
        }

        $conversation->update($update);

        if ($employee->user !== null) {
            $this->audit->log($employee->user, 'ai.retried', 'ai_conversation', $conversation->id, null, [
                'question' => (string) $conversation->question,
                'llm' => true,
            ]);
        }

        return true;
    }

    /**
     * Повторный LLM-вызов → массив обновления диалога (или null при сбое).
     * Переопределяется панелью (дополнительно разбирает fenced-json блоки).
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<string, mixed>|null
     */
    protected function retryLlmUpdate(AiConversation $conversation, array $facts, array $history): ?array
    {
        $answer = null;
        $employee = Employee::find($conversation->employee_id);
        $rag = $employee !== null
            ? $this->ragContext($employee, (string) $conversation->question)
            : [];

        if (! $this->llmAnswer($facts, (string) $conversation->question, $history, $answer, true, $rag, $employee)) {
            return null;
        }

        return ['answer' => $answer, 'grounded' => true];
    }

    /* -------------------- локальный аналитик -------------------- */

    /**
     * Без LLM: детерминированный ответ на основе фактов.
     */
    protected function localAnalyst(array $facts, string $question): string
    {
        $q = mb_strtolower($question);
        $lines = [];

        if ($this->mentions($q, ['опоздан', 'посещаем', 'приход', 'на месте', 'присутст'])) {
            $a = $facts['attendance_today'] ?? null;
            if (is_array($a)) {
                $lines[] = sprintf(
                    'Сегодня на месте %d из %d, опоздали %d, удалённо %d, без отметок %d.',
                    $a['present'], $a['total'], $a['late'], $a['remote'], $a['absent_from_schedule'],
                );
            } elseif (is_array($facts['my_attendance_today'] ?? null)) {
                $me = $facts['my_attendance_today'];
                $lines[] = sprintf('Ваша отметка сегодня: %s (приход %s, опоздание %d мин).', $me['status'], $me['clock_in_at'] ?? '—', $me['late_minutes']);
            } else {
                $lines[] = 'Сегодня у вас нет отметок.';
            }
        }

        if ($this->mentions($q, ['задач', 'таск', 'поручен'])) {
            $tasks = $facts['tasks'] ?? $facts['my_tasks'] ?? [];
            if ($tasks !== []) {
                $parts = [];
                foreach (['new' => 'новых', 'in_progress' => 'в работе', 'review' => 'на проверке', 'overdue' => 'просрочено', 'completed' => 'завершено'] as $k => $label) {
                    if (($tasks[$k] ?? 0) > 0) {
                        $parts[] = $label.': '.$tasks[$k];
                    }
                }
                if ($parts !== []) {
                    $lines[] = 'Задачи — '.implode(', ', $parts).'.';
                } else {
                    $lines[] = 'Открытых задач нет.';
                }
            } else {
                $lines[] = 'Открытых задач нет.';
            }
        }

        if ($this->mentions($q, ['отпуск', 'отпуска', 'vacation'])) {
            $leaves = $facts['leaves'] ?? [];
            if ($leaves !== []) {
                $lines[] = sprintf('Заявки на отпуск: ожидает %d, согласовано %d, отклонено %d.', $leaves['pending'] ?? 0, $leaves['approved'] ?? 0, $leaves['rejected'] ?? 0);
            } else {
                $lines[] = 'Заявок на отпуск нет.';
            }
        }

        if ($this->mentions($q, ['сотрудник', 'числен', 'штат', 'людей', 'человек', 'работников'])) {
            $headcount = $facts['headcount'] ?? null;
            if (is_array($headcount)) {
                $lines[] = sprintf('Численность: активных %d из %d в области доступа.', $headcount['active'], $headcount['total']);
            }
        }

        if ($lines === []) {
            // общий обзор
            $headcount = $facts['headcount'] ?? null;
            if (is_array($headcount)) {
                $lines[] = sprintf('Численность: %d активных сотрудников. Сегодня на месте %d, опоздали %d.', $headcount['active'], $facts['attendance_today']['present'] ?? 0, $facts['attendance_today']['late'] ?? 0);
            }
            $lines[] = 'Уточните вопрос: посещаемость, задачи, отпуска или численность.';
        }

        return implode(' ', $lines);
    }

    protected function mentions(string $lowerQuestion, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($lowerQuestion, $needle)) {
                return true;
            }
        }

        return false;
    }
}
