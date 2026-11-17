<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Models\AiConversation;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Task;
use App\Support\SettingsService;
use Throwable;

/**
 * AI-панель (док справа): структурированные ответы для POST /ai/message.
 *
 * Наследует grounded-логику AiAssistantService (факты scope, LLM, локальный аналитик)
 * и добавляет намерения (intents): отчёты с диаграммами, таблицы, поиск, навигация.
 * Ответ — массив {ok, reply, blocks} (JSON-контракт панели).
 *
 * Решение по безопасности: панель НЕ пишет в БД от имени ИИ (create/edit только
 * как deep-link навигация) — пользователь подтверждает создание в существующем UI.
 */
class AiPanelService extends AiAssistantService
{
    /** Статусы задач → человекочитаемые подписи для диаграмм/таблиц. */
    private const TASK_LABELS = [
        'new' => 'Новые',
        'in_progress' => 'В работе',
        'review' => 'На проверке',
        'overdue' => 'Просрочено',
        'completed' => 'Выполнено',
        'cancelled' => 'Отменено',
    ];

    /* -------------------- публичный API -------------------- */

    /**
     * Ответ панели: намерения → LLM (если настроен) → сохранение в ai_conversations.
     *
     * @return array{ok: bool, reply: string, blocks: array<int, array<string, mixed>>, facts: array<string, mixed>, grounded: bool}
     */
    public function respond(Employee $asker, string $question): array
    {
        try {
            $payload = $this->build($asker, $question);
        } catch (Throwable) {
            $payload = [
                'ok' => false,
                'reply' => 'Не удалось обработать вопрос. Попробуйте переформулировать или повторить позже.',
                'blocks' => [],
                'facts' => [],
                'grounded' => false,
            ];
        }

        $this->persist($asker, $question, $payload);

        return $payload;
    }

    /* -------------------- сборка ответа -------------------- */

    /** @return array{ok: bool, reply: string, blocks: array<int, array<string, mixed>>, facts: array<string, mixed>, grounded: bool} */
    protected function build(Employee $asker, string $question): array
    {
        $facts = $this->collectFacts($asker);
        $local = $this->localResponse($asker, $question, $facts);

        $reply = $local['reply'] ?? null;
        $blocks = $local['blocks'] ?? [];
        $grounded = false;

        if ($this->llmEnabled()) {
            $llmText = null;
            $llmBlocks = null;
            // память диалога: предыдущие реплики уходят в контекст LLM (S10)
            $history = $this->historyMessages($asker);
            // D4 (RAG): релевантные фрагменты документов компании
            $rag = $this->ragContext($asker, $question);
            if ($this->llmAnswerPanel($facts, $question, $history, $llmText, $llmBlocks, false, $rag, $asker)) {
                $reply = $llmText;
                $grounded = true;
                // grounded-приоритет: блоки из локальных намерений (на фактах) важнее
                // блоков, придуманных LLM; LLM-блоки — только когда намерение не сработало.
                if ($blocks === [] && is_array($llmBlocks)) {
                    $blocks = $llmBlocks;
                }
            }
        }

        if ($reply === null) {
            // неизвестный вопрос: детерминированный разбор фактов + подсказки-ссылки
            $reply = $this->localAnalyst($facts, $question);
        }

        if ($blocks === []) {
            $blocks = $this->hintActions($asker);
        }

        return ['ok' => true, 'reply' => $reply, 'blocks' => $blocks, 'facts' => $facts, 'grounded' => $grounded];
    }

    /** История + аудит — как в AiAssistantService::ask(), но с блоками в query_used. */
    protected function persist(Employee $asker, string $question, array $payload): void
    {
        try {
            $conversation = AiConversation::create([
                'employee_id' => $asker->id,
                'question' => $question,
                'answer' => $payload['reply'],
                'query_used' => ['facts' => $payload['facts'], 'blocks' => $payload['blocks']],
                // честный флаг: true — ответил LLM, false — локальный фолбэк/сбой
                'grounded' => (bool) ($payload['grounded'] ?? false),
            ]);

            if ($asker->user !== null) {
                $this->audit->log($asker->user, 'ai.asked', 'ai_conversation', $conversation->id, null, [
                    'question' => $question,
                    'llm' => (bool) ($payload['grounded'] ?? false),
                    'channel' => 'panel',
                ]);
            }

            // D3: LLM настроен, но синхронно не ответил — фоновый повтор в очереди
            if (! (bool) ($payload['grounded'] ?? false)) {
                $this->queueRetry($conversation, panel: true);
            }
        } catch (Throwable) {
            // история не должна ломать ответ пользователю
        }
    }

    /* -------------------- намерения (intents) -------------------- */

    /** @param array<string, mixed> $facts */
    protected function localResponse(Employee $asker, string $question, array $facts): ?array
    {
        $q = mb_strtolower($question);

        foreach ([
            fn (): ?array => $this->intentSearch($asker, $question, $q),
            fn (): ?array => $this->intentCreateEdit($asker, $q),
            fn (): ?array => $this->intentAttendance($asker, $q, $facts),
            fn (): ?array => $this->intentTasks($asker, $q, $facts),
            fn (): ?array => $this->intentHeadcount($asker, $q, $facts),
            fn (): ?array => $this->intentLeaves($asker, $q, $facts),
            fn (): ?array => $this->intentNavigate($asker, $q),
        ] as $intent) {
            $result = $intent();
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /** «найди X» / «найди: X» / «поиск X» — поиск по сотрудникам, задачам, документам. */
    protected function intentSearch(Employee $asker, string $question, string $q): ?array
    {
        if (! preg_match('/(?:^|\s)(?:найди|найти|поиск|find|search)\s*:?\s*(.{2,})$/ui', $q, $m)) {
            return null;
        }

        $needle = trim($m[1], " \t.,!?:;\"'«»");
        $display = trim($question, " \t.,!?:;\"'«»");
        if (mb_strlen($needle) < 2 || $asker->user === null) {
            return null;
        }

        $found = [];
        $counts = ['Сотрудники' => 0, 'Задачи' => 0, 'Документы' => 0];

        if ($this->permissions->check($asker->user, 'employees.view')) {
            $access = $this->analytics->accessibleEmployees($asker->user, 'employees.view');
            $employees = $access['employees']
                ->filter(static fn (Employee $e) => str_contains(
                    mb_strtolower(trim($e->last_name.' '.$e->first_name.' '.(string) $e->middle_name.' '.$e->employee_no)),
                    $needle,
                ))
                ->take(5);
            $counts['Сотрудники'] = $employees->count();
            foreach ($employees as $e) {
                $found[] = $this->action(
                    'Сотрудник: '.trim($e->last_name.' '.$e->first_name),
                    route('employees.show', $e),
                    'ti-user',
                );
            }
        }

        if ($this->permissions->check($asker->user, 'tasks.view')) {
            $access = $this->analytics->accessibleEmployees($asker->user, 'tasks.view');
            $ids = $access['employees']->pluck('id')->all();
            $tasks = Task::query()
                // company-scope = «вся моя компания» (межтенантная изоляция поиска)
                ->where('company_id', $asker->company_id)
                ->where(fn ($w) => $w->where('title', 'like', "%{$needle}%")->orWhere('number', 'like', "%{$needle}%"))
                ->when($access['scope_value'] !== 'company', fn ($w) => $w
                    ->whereHas('assignments', fn ($a) => $a->whereIn('employee_id', $ids)->limit(1)))
                ->orderByDesc('id')
                ->limit(5)
                ->get();
            $counts['Задачи'] = $tasks->count();
            foreach ($tasks as $t) {
                $found[] = $this->action('Задача: '.$t->title, route('tasks.show', $t), 'ti-checkbox');
            }
        }

        if ($this->permissions->check($asker->user, 'documents.view')) {
            $access = $this->analytics->accessibleEmployees($asker->user, 'documents.view');
            $ids = $access['employees']->pluck('id')->all();
            $documents = Document::query()
                ->where('company_id', $asker->company_id)
                ->where(fn ($w) => $w->where('title', 'like', "%{$needle}%")
                    ->orWhere('document_number', 'like', "%{$needle}%")
                    ->orWhere('reg_number', 'like', "%{$needle}%"))
                ->when($access['scope_value'] !== 'company', fn ($w) => $w
                    ->where(fn ($s) => $s->whereIn('author_id', $ids)
                        ->orWhereHas('signatureRequests', fn ($r) => $r->whereIn('signer_id', $ids))))
                ->orderByDesc('id')
                ->limit(5)
                ->get();
            $counts['Документы'] = $documents->count();
            foreach ($documents as $d) {
                $found[] = $this->action('Документ: '.$d->title, route('documents.show', $d), 'ti-file');
            }
        }

        if ($found === []) {
            return [
                'reply' => 'По запросу «'.$needle.'» ничего не нашёл в данных, доступных вам.',
                'blocks' => [],
            ];
        }

        $parts = [];
        foreach ($counts as $label => $n) {
            if ($n > 0) {
                $parts[] = mb_strtolower($label).': '.$n;
            }
        }

        return [
            'reply' => sprintf('Нашёл результаты по запросу «%s» — %s. Открывайте ссылками ниже.', $needle, implode(', ', $parts)),
            'blocks' => [['type' => 'actions', 'title' => 'Найдено ('.$display.')', 'items' => $found]],
        ];
    }

    /** «создай задачу» / «измени задачу» — только навигация, БД не трогаем. */
    protected function intentCreateEdit(Employee $asker, string $q): ?array
    {
        if (! $this->mentions($q, ['задач', 'таск'])) {
            return null;
        }

        if ($this->mentions($q, ['создай', 'создать', 'заведи', 'добавь', 'новую', 'новая'])) {
            if ($asker->user === null || ! $this->permissions->check($asker->user, 'tasks.view')) {
                return ['reply' => 'Для создания задач нужен доступ к задачам.', 'blocks' => []];
            }

            // modal #newTask на /tasks/mine — JS панели откроет его по хешу #new-task
            return [
                'reply' => 'Открою форму создания задачи на странице «Мои задачи». Подтвердите данные и нажмите «Создать задачу» — ИИ не пишет в систему без вашего подтверждения.',
                'blocks' => [['type' => 'actions', 'items' => [
                    $this->action('Перейти к созданию задачи', route('tasks.mine').'#new-task', 'ti-plus'),
                ]]],
            ];
        }

        if ($this->mentions($q, ['измени', 'изменить', 'редакт', 'обнови', 'поправь'])) {
            if ($asker->user === null || ! $this->permissions->check($asker->user, 'tasks.view')) {
                return ['reply' => 'Для изменения задач нужен доступ к задачам.', 'blocks' => []];
            }

            return [
                'reply' => 'Откройте нужную задачу — и я подсказываю поля в чате; изменения вы подтверждаете в самой задаче.',
                'blocks' => [['type' => 'actions', 'items' => [
                    $this->action('Открыть список задач', route('tasks.index'), 'ti-list'),
                ]]],
            ];
        }

        return null;
    }

    /** Посещаемость: диаграмма + таблица + ссылки. @param array<string, mixed> $facts */
    protected function intentAttendance(Employee $asker, string $q, array $facts): ?array
    {
        if (! $this->mentions($q, ['посещаем', 'опоздан', 'на месте', 'присутст', 'приход', 'отметк', 'табел', 'прогул', 'удалённ', 'удаленн'])) {
            return null;
        }

        $blocks = [];
        $parts = [];

        if (is_array($a = $facts['attendance_today'] ?? null)) {
            $parts[] = sprintf(
                'Сегодня на месте %d из %d: опоздали %d, удалённо %d, без отметок %d.',
                $a['present'], $a['total'], $a['late'], $a['remote'], $a['absent_from_schedule'],
            );
            $blocks[] = [
                'type' => 'chart',
                'title' => 'Посещаемость сегодня',
                'data' => [
                    ['label' => 'На месте', 'value' => (int) $a['present']],
                    ['label' => 'Опоздали', 'value' => (int) $a['late']],
                    ['label' => 'Удалённо', 'value' => (int) $a['remote']],
                    ['label' => 'Без отметок', 'value' => (int) $a['absent_from_schedule']],
                ],
            ];
            $blocks[] = [
                'type' => 'table',
                'title' => 'Посещаемость сегодня',
                'rows' => [
                    ['На месте', (string) $a['present']],
                    ['Опоздали', (string) $a['late']],
                    ['Удалённо', (string) $a['remote']],
                    ['Без отметок', (string) $a['absent_from_schedule']],
                    ['Всего в области доступа', (string) $a['total']],
                ],
            ];
        } elseif (is_array($my = $facts['my_attendance_today'] ?? null)) {
            $parts[] = sprintf('Ваша отметка сегодня: %s (приход %s, опоздание %d мин).', $my['status'], $my['clock_in_at'] ?? '—', $my['late_minutes']);
            $blocks[] = [
                'type' => 'table',
                'title' => 'Ваша посещаемость сегодня',
                'rows' => [
                    ['Статус', (string) $my['status']],
                    ['Приход', (string) ($my['clock_in_at'] ?? '—')],
                    ['Опоздание, мин', (string) $my['late_minutes']],
                ],
            ];
        } elseif (($facts['scope'] ?? null) !== 'own') {
            $parts[] = 'Сегодня отметок в вашей области доступа нет.';
        } else {
            $parts[] = 'Сегодня у вас нет отметок.';
        }

        $actions = [];
        if ($asker->user !== null) {
            if ($this->permissions->check($asker->user, 'attendance.view')) {
                $actions[] = $this->action('Открыть посещаемость', route('attendance.index'), 'ti-calendar');
            }
            if ($this->permissions->check($asker->user, 'attendance.live.view')) {
                $actions[] = $this->action('Живая посещаемость', route('attendance.live'), 'ti-broadcast');
            }
            if ($this->mentions($q, ['отчёт', 'отчет', 'построй']) && $this->permissions->check($asker->user, 'reports.view')) {
                $actions[] = $this->action('Отчёт по посещаемости', route('reports.attendance'), 'ti-report');
            }
        }
        if ($actions !== []) {
            $blocks[] = ['type' => 'actions', 'items' => $actions];
        }

        return ['reply' => implode(' ', $parts), 'blocks' => $blocks];
    }

    /** Задачи: диаграмма по статусам + сводная таблица + ссылки. @param array<string, mixed> $facts */
    protected function intentTasks(Employee $asker, string $q, array $facts): ?array
    {
        if (! $this->mentions($q, ['задач', 'таск', 'поручен', 'просроч', 'дедлайн'])) {
            return null;
        }

        $stats = $facts['tasks'] ?? $facts['my_tasks'] ?? null;
        if (! is_array($stats)) {
            return ['reply' => 'Задачи в вашем scope недоступны.', 'blocks' => []];
        }

        $normalized = [];
        foreach ($stats as $status => $cnt) {
            $normalized[(string) $status] = (int) $cnt;
        }

        $total = array_sum($normalized);
        $completed = $normalized['completed'] ?? 0;
        $overdue = $normalized['overdue'] ?? 0;

        if ($total === 0) {
            return [
                'reply' => 'Открытых задач в вашем scope нет.',
                'blocks' => [['type' => 'table', 'title' => 'Задачи', 'rows' => [['Всего', '0']]]],
            ];
        }

        $chart = [];
        foreach (self::TASK_LABELS as $status => $label) {
            $chart[] = ['label' => $label, 'value' => $normalized[$status] ?? 0];
        }
        foreach ($normalized as $status => $cnt) {
            if (! array_key_exists($status, self::TASK_LABELS)) {
                $chart[] = ['label' => ucfirst((string) $status), 'value' => $cnt];
            }
        }

        $blocks = [
            ['type' => 'chart', 'title' => 'Задачи по статусам', 'data' => $chart],
            ['type' => 'table', 'title' => 'Сводка по задачам', 'rows' => [
                ['Всего', (string) $total],
                ['В работе', (string) ($normalized['in_progress'] ?? 0)],
                ['На проверке', (string) ($normalized['review'] ?? 0)],
                ['Просрочено', (string) $overdue],
                ['Выполнено', (string) $completed],
            ]],
        ];

        $actions = [];
        if ($asker->user !== null) {
            if ($this->permissions->check($asker->user, 'tasks.view')) {
                $actions[] = $this->action('Открыть задачи', route('tasks.index'), 'ti-checkbox');
                if ($overdue > 0) {
                    $actions[] = $this->action('Только просроченные', route('tasks.overdue'), 'ti-alarm');
                }
            }
            if ($this->mentions($q, ['отчёт', 'отчет', 'построй']) && $this->permissions->check($asker->user, 'reports.view')) {
                $actions[] = $this->action('Отчёт по задачам', route('reports.tasks'), 'ti-report');
            }
        }
        if ($actions !== []) {
            $blocks[] = ['type' => 'actions', 'items' => $actions];
        }

        $reply = sprintf('Задач в области доступа: %d — выполнено %d, просрочено %d.', $total, $completed, $overdue);
        if ($this->mentions($q, ['отчёт', 'отчет', 'построй'])) {
            $reply .= ' Диаграмма и сводка — ниже, полный отчёт доступен по ссылке.';
        }

        return ['reply' => $reply, 'blocks' => $blocks];
    }

    /** Численность: общий итог + диаграмма по отделам. @param array<string, mixed> $facts */
    protected function intentHeadcount(Employee $asker, string $q, array $facts): ?array
    {
        if (! $this->mentions($q, ['числен', 'сотрудник', 'штат', 'людей', 'человек', 'работников', 'персонал', 'кадр'])) {
            return null;
        }

        $headcount = $facts['headcount'] ?? null;
        if (! is_array($headcount)) {
            return [
                'reply' => 'Общая численность недоступна в вашем scope — доступны только личные данные.',
                'blocks' => [],
            ];
        }

        $blocks = [];
        $reply = sprintf('Численность: активных %d из %d в области доступа.', $headcount['active'], $headcount['total']);

        if ($asker->user !== null && $this->permissions->check($asker->user, 'analytics.view')) {
            $access = $this->analytics->accessibleEmployees($asker->user, 'analytics.view');
            $rows = $this->analytics->hrByDepartment($access['employees']);

            if ($rows !== []) {
                $chart = [];
                $table = [];
                foreach (array_slice($rows, 0, 12) as $row) {
                    $chart[] = ['label' => (string) $row['name'], 'value' => (int) $row['headcount']];
                    $table[] = [(string) $row['name'], (string) $row['headcount']];
                }
                $table[] = ['Итого', (string) $headcount['total']];
                $blocks[] = ['type' => 'chart', 'title' => 'Численность по отделам', 'data' => $chart];
                $blocks[] = ['type' => 'table', 'title' => 'Кадровый срез', 'rows' => $table];
                $reply .= ' Разбивка по подразделениям — ниже.';
            }
        }

        $actions = [];
        if ($asker->user !== null) {
            if ($this->permissions->check($asker->user, 'employees.view')) {
                $actions[] = $this->action('Открыть сотрудников', route('employees.index'), 'ti-users');
            }
            if ($this->permissions->check($asker->user, 'analytics.view')) {
                $actions[] = $this->action('Аналитика', route('analytics.index'), 'ti-chart-dots');
            }
        }
        if ($actions !== []) {
            $blocks[] = ['type' => 'actions', 'items' => $actions];
        }

        return ['reply' => $reply, 'blocks' => $blocks];
    }

    /** Заявки на отпуск: таблица статусов + ссылка. @param array<string, mixed> $facts */
    protected function intentLeaves(Employee $asker, string $q, array $facts): ?array
    {
        if (! $this->mentions($q, ['отпуск', 'отпускн', 'vacation'])) {
            return null;
        }

        $leaves = $facts['leaves'] ?? [];

        $rows = [
            ['Ожидает решения', (string) ($leaves['pending'] ?? 0)],
            ['Согласовано', (string) ($leaves['approved'] ?? 0)],
            ['Отклонено', (string) ($leaves['rejected'] ?? 0)],
        ];
        $total = (int) ($leaves['pending'] ?? 0) + (int) ($leaves['approved'] ?? 0) + (int) ($leaves['rejected'] ?? 0);

        $blocks = [['type' => 'table', 'title' => 'Заявки на отпуск', 'rows' => $rows]];

        $actions = [];
        if ($asker->user !== null && $this->permissions->check($asker->user, 'leaves.view')) {
            $actions[] = $this->action('Открыть отпуска', route('schedules.leaves'), 'ti-plane');
        }
        if ($actions !== []) {
            $blocks[] = ['type' => 'actions', 'items' => $actions];
        }

        $reply = $total === 0
            ? 'Заявок на отпуск в вашем scope нет.'
            : sprintf('Заявки на отпуск: ожидает %d, согласовано %d, отклонено %d.', $leaves['pending'] ?? 0, $leaves['approved'] ?? 0, $leaves['rejected'] ?? 0);

        return ['reply' => $reply, 'blocks' => $blocks];
    }

    /** «открой/перейди к X» — чистая навигация по словарю. */
    protected function intentNavigate(Employee $asker, string $q): ?array
    {
        if ($asker->user === null) {
            return null;
        }

        if (! $this->mentions($q, ['открой', 'открыть', 'перейди', 'перейти', 'покажи', 'зайди', 'переходи'])) {
            return null;
        }

        $map = [
            ['kw' => ['посещаем', 'табел'], 'perm' => 'attendance.view', 'route' => 'attendance.index', 'label' => 'Посещаемость', 'icon' => 'ti-calendar'],
            ['kw' => ['мои задачи'], 'perm' => 'tasks.view', 'route' => 'tasks.mine', 'label' => 'Мои задачи', 'icon' => 'ti-list-check'],
            ['kw' => ['задач', 'таск'], 'perm' => 'tasks.view', 'route' => 'tasks.index', 'label' => 'Задачи', 'icon' => 'ti-checkbox'],
            ['kw' => ['отпуск'], 'perm' => 'leaves.view', 'route' => 'schedules.leaves', 'label' => 'Отпуска', 'icon' => 'ti-plane'],
            ['kw' => ['сотрудник', 'штат', 'кадр'], 'perm' => 'employees.view', 'route' => 'employees.index', 'label' => 'Сотрудники', 'icon' => 'ti-users'],
            ['kw' => ['документ'], 'perm' => 'documents.view', 'route' => 'documents.index', 'label' => 'Документы', 'icon' => 'ti-file'],
            ['kw' => ['аналит'], 'perm' => 'analytics.view', 'route' => 'analytics.index', 'label' => 'Аналитика', 'icon' => 'ti-chart-dots'],
            ['kw' => ['отчёт', 'отчет'], 'perm' => 'reports.view', 'route' => 'reports.index', 'label' => 'Отчёты', 'icon' => 'ti-report'],
            ['kw' => ['график', 'расписан'], 'perm' => 'schedules.view', 'route' => 'schedules.index', 'label' => 'Графики работы', 'icon' => 'ti-calendar-event'],
            ['kw' => ['дашборд', 'главн', 'домой'], 'perm' => null, 'route' => 'dashboard', 'label' => 'Главная', 'icon' => 'ti-home'],
        ];

        foreach ($map as $entry) {
            if (! $this->mentions($q, $entry['kw'])) {
                continue;
            }
            if ($entry['perm'] !== null && ! $this->permissions->check($asker->user, $entry['perm'])) {
                continue;
            }

            return [
                'reply' => 'Перехожу в раздел «'.$entry['label'].'».',
                'blocks' => [['type' => 'actions', 'items' => [
                    $this->action('Открыть: '.$entry['label'], route($entry['route']), $entry['icon']),
                ]]],
            ];
        }

        return null;
    }

    /* -------------------- подсказки -------------------- */

    /** Чипы-подсказки для неизвестного вопроса (только разделы, куда есть доступ). */
    protected function hintActions(Employee $asker): array
    {
        if ($asker->user === null) {
            return [];
        }

        $items = [];
        if ($this->permissions->check($asker->user, 'attendance.view')) {
            $items[] = $this->action('Посещаемость', route('attendance.index'), 'ti-calendar');
        }
        if ($this->permissions->check($asker->user, 'tasks.view')) {
            $items[] = $this->action('Задачи', route('tasks.index'), 'ti-checkbox');
        }
        if ($this->permissions->check($asker->user, 'analytics.view')) {
            $items[] = $this->action('Аналитика', route('analytics.index'), 'ti-chart-dots');
        }
        if ($this->permissions->check($asker->user, 'reports.view')) {
            $items[] = $this->action('Отчёты', route('reports.index'), 'ti-report');
        }

        if ($items === []) {
            return [];
        }

        return [['type' => 'actions', 'title' => 'Попробуйте', 'items' => $items]];
    }

    /* -------------------- LLM с блоками -------------------- */

    /**
     * Ответ LLM + опциональные блоки в fenced-json после текста.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @param  array<int, array{document_id: int, title: string, number: string, score: float, content: string}>  $rag
     *
     * @param-out string|null $answerText
     * @param-out array<int, array<string, mixed>>|null $blocks
     */
    protected function llmAnswerPanel(array $facts, string $question, array $history = [], ?string &$answerText = null, ?array &$blocks = null, bool $background = false, array $rag = [], ?Employee $asker = null): bool
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
            .'Учитывай предыдущие реплики диалога: уточнения и местоимения относи к уже заданным вопросам. '
            .'Отвечай на русском, коротко. В конце ответа МОЖНО добавить ровно один fenced-блок ```json '
            .'с объектом {"blocks":[{"type":"chart","title":"...","data":[{"label":"...","value":0}]},'
            .'{"type":"table","title":"...","rows":[["...","..."]]},'
            .'{"type":"actions","items":[{"label":"...","url":"/path","icon":"ti-..."}]}]} — '
            .'только если данные блоков полностью берутся из фактов. URL — относительные (начинаются с /). '
            .'Больше ничего после json-блока не пиши.'
            .$this->ragSystemMessage($rag);

        $message = $this->postWithTools([
            ['role' => 'system', 'content' => $system],
            ['role' => 'system', 'content' => 'Факты: '.json_encode($facts, JSON_UNESCAPED_UNICODE)],
            ...array_slice($history, -12),
            ['role' => 'user', 'content' => $question],
        ], $baseUrl, $apiKey, $model, $timeout, $asker);

        $content = $message['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            return false;
        }

        $blocks = null;
        if (preg_match('/```json\s*(.+?)\s*```/su', $content, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded) && is_array($decoded['blocks'] ?? null)) {
                $blocks = $this->sanitizeBlocks($decoded['blocks']);
            }
            $content = trim(preg_replace('/```json\s*.+?\s*```/su', '', $content) ?? $content);
        }

        if (trim($content) === '' && $blocks === null) {
            return false;
        }

        $answerText = trim($content) !== '' ? trim($content) : 'Готово — данные ниже.';

        return true;
    }

    /**
     * D3: фоновый повтор панели — LLM-ответ с разбором fenced-json блоки.
     * Локальные блоки приоритетнее (как в build()), поэтому в query_used
     * добавляются только когда сохранённых блоков нет.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<string, mixed>|null
     */
    protected function retryLlmUpdate(AiConversation $conversation, array $facts, array $history): ?array
    {
        $answer = null;
        $blocks = null;
        $employee = Employee::find($conversation->employee_id);
        $rag = $employee !== null
            ? $this->ragContext($employee, (string) $conversation->question)
            : [];

        if (! $this->llmAnswerPanel($facts, (string) $conversation->question, $history, $answer, $blocks, true, $rag, $employee)) {
            return null;
        }

        $update = ['answer' => $answer, 'grounded' => true];

        $stored = $conversation->query_used;
        if ($blocks !== null && is_array($stored) && is_array($stored['facts'] ?? null) && ($stored['blocks'] ?? []) === []) {
            $stored['blocks'] = $blocks;
            $update['query_used'] = $stored;
        }

        return $update;
    }

    /** Строгая санитизация блоков от LLM (типы, размеры, длины, URL). */
    protected function sanitizeBlocks(array $raw): array
    {
        $out = [];

        foreach ($raw as $block) {
            if (count($out) >= 6) {
                break;
            }
            if (! is_array($block) || ! isset($block['type']) || ! is_string($block['type'])) {
                continue;
            }

            $title = isset($block['title']) && is_string($block['title'])
                ? mb_substr($block['title'], 0, 80)
                : null;

            if ($block['type'] === 'chart') {
                $data = [];
                if (is_array($block['data'] ?? null)) {
                    foreach (array_slice($block['data'], 0, 20) as $row) {
                        if (is_array($row) && isset($row['label'], $row['value']) && is_scalar($row['value'])) {
                            $value = (float) $row['value'];
                            if ($value >= 0 && is_finite($value)) {
                                $data[] = [
                                    'label' => mb_substr((string) $row['label'], 0, 60),
                                    'value' => $value,
                                ];
                            }
                        }
                    }
                }
                if ($data !== []) {
                    $chart = ['type' => 'chart', 'data' => $data];
                    if ($title !== null) {
                        $chart['title'] = $title;
                    }
                    $out[] = $chart;
                }
            } elseif ($block['type'] === 'table') {
                $rows = [];
                if (is_array($block['rows'] ?? null)) {
                    foreach (array_slice($block['rows'], 0, 20) as $row) {
                        if (is_array($row)) {
                            $cells = [];
                            foreach (array_slice($row, 0, 3) as $cell) {
                                $cells[] = mb_substr(is_scalar($cell) ? (string) $cell : '', 0, 80);
                            }
                            if ($cells !== []) {
                                $rows[] = $cells;
                            }
                        }
                    }
                }
                if ($rows !== []) {
                    $out[] = ['type' => 'table', 'title' => $title, 'rows' => $rows];
                }
            } elseif ($block['type'] === 'actions') {
                $items = [];
                if (is_array($block['items'] ?? null)) {
                    foreach (array_slice($block['items'], 0, 8) as $item) {
                        if (! is_array($item) || ! isset($item['label'], $item['url']) || ! is_string($item['url'])) {
                            continue;
                        }
                        $url = $item['url'];
                        if (! str_starts_with($url, '/') || str_starts_with($url, '//') || preg_match('/[\s<>"\']/', $url)) {
                            continue;
                        }
                        $icon = isset($item['icon']) && is_string($item['icon']) && preg_match('/^[a-z0-9-]+$/', $item['icon'])
                            ? $item['icon']
                            : 'ti-arrow-right';
                        $items[] = [
                            'label' => mb_substr((string) $item['label'], 0, 60),
                            'url' => mb_substr($url, 0, 200),
                            'icon' => $icon,
                        ];
                    }
                }
                if ($items !== []) {
                    $out[] = ['type' => 'actions', 'title' => $title, 'items' => $items];
                }
            }
        }

        return $out;
    }

    /* -------------------- помощники -------------------- */

    /** @return array{label: string, url: string, icon: string} */
    protected function action(string $label, string $url, string $icon = 'ti-arrow-right'): array
    {
        return ['label' => $label, 'url' => $url, 'icon' => $icon];
    }
}
