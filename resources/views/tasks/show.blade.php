@extends('layouts.tabler')

@section('title', 'Задача ' . $task->number)

@php
    $prioChip = ['critical' => 'crm-chip--red', 'high' => 'crm-chip--orange', 'medium' => 'crm-chip--cyan', 'low' => 'crm-chip--grey'][$task->priority->value] ?? 'crm-chip--grey';
    $statusChip = [
        'completed' => 'crm-chip--green', 'overdue' => 'crm-chip--red', 'cancelled' => 'crm-chip--grey',
        'returned' => 'crm-chip--orange', 'review' => 'crm-chip--orange', 'planned' => 'crm-chip--cyan',
        'new' => 'crm-chip--grey', 'in_progress' => 'crm-chip',
    ][$task->status->value] ?? 'crm-chip';
    $isLate = $task->deadline !== null && $task->deadline->isPast() && ! in_array($task->status->value, ['completed', 'cancelled'], true);

    /* обратный отсчёт дедлайна */
    $deadlineLeft = null;
    if ($task->deadline !== null && ! in_array($task->status->value, ['completed', 'cancelled'], true)) {
        $hours = (int) now()->diffInHours($task->deadline, false);
        if ($hours >= 0) {
            $deadlineLeft = $hours < 24
                ? 'осталось ' . $hours . ' ч'
                : 'осталось ' . (int) floor($hours / 24) . ' дн.';
        } else {
            $abs = abs($hours);
            $deadlineLeft = $abs < 24
                ? 'просрочено на ' . $abs . ' ч'
                : 'просрочено на ' . (int) floor($abs / 24) . ' дн.';
        }
    }

    /* лента жизненного цикла: где задача сейчас */
    $flow = [
        ['key' => 'new', 'label' => 'Новая', 'icon' => 'clipboard'],
        ['key' => 'in_progress', 'label' => 'В работе', 'icon' => 'player-play'],
        ['key' => 'review', 'label' => 'Проверка', 'icon' => 'eye'],
        ['key' => 'completed', 'label' => 'Готово', 'icon' => 'circle-check'],
    ];
    $flowIndex = match ($task->status->value) {
        'new', 'planned' => 0,
        'in_progress', 'overdue', 'returned' => 1,
        'review' => 2,
        'completed' => 3,
        default => -1, /* cancelled */
    };
    $canComplete = (int) ($task->author_id ?? 0) === (int) (auth()->user()?->employee?->id ?? 0)
        || app(\App\Support\PermissionService::class)->check(auth()->user(), 'tasks.manage')
        || app(\App\Support\PermissionService::class)->check(auth()->user(), 'tasks.complete');
    $nextAction = match ($task->status->value) {
        'new', 'planned' => ['type' => 'status', 'status' => 'in_progress', 'label' => 'Взять в работу', 'icon' => 'player-play', 'hint' => 'Задача перейдёт в статус «В работе».'],
        'in_progress', 'overdue', 'returned' => ['type' => 'result', 'label' => 'Отправить результат', 'icon' => 'send', 'hint' => 'Когда работа готова, коротко опишите результат для проверки.'],
        'review' => $canComplete
            ? ['type' => 'status', 'status' => 'completed', 'label' => 'Подтвердить выполнение', 'icon' => 'circle-check', 'hint' => 'Результат проверен — задача будет завершена.']
            : ['type' => 'none', 'label' => 'Ожидает решения автора', 'icon' => 'hourglass', 'hint' => 'Результат отправлен на проверку.'],
        default => ['type' => 'none', 'label' => 'Задача завершена', 'icon' => 'circle-check', 'hint' => 'История и материалы остаются доступны ниже.'],
    };
@endphp

@section('content')
{{-- ===== Шапка задачи ===== --}}
<div class="crm-profile-hero card mb-3">
    <div class="card-body">
        <div class="d-flex flex-column flex-md-row align-items-md-start gap-3">
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="crm-chip crm-chip--grey">{{ $task->number }}</span>
                    <span class="crm-chip {{ $statusChip }}">{{ $crm_label('task_status', $task->status->value) }}</span>
                    <span class="crm-chip {{ $prioChip }}">{{ $crm_label('task_priority', $task->priority->value) }}</span>
                    @if ($isLate)
                        <span class="crm-chip crm-chip--red"><i class="ti ti-alarm me-1"></i>просрочено</span>
                    @elseif ($deadlineLeft !== null)
                        <span class="crm-chip {{ $deadlineLeft !== null && str_starts_with($deadlineLeft, 'просрочено') ? 'crm-chip--orange' : 'crm-chip--cyan' }}">
                            <i class="ti ti-hourglass me-1"></i>{{ $deadlineLeft }}
                        </span>
                    @endif
                </div>
                <h2 class="page-title mt-2 mb-1">{{ $task->title }}</h2>
                <div class="text-secondary">
                    <i class="ti ti-user me-1"></i>автор: {{ $task->author?->last_name . ' ' . $task->author?->first_name ?? '—' }}
                    <span class="mx-1">·</span>
                    <i class="ti ti-sitemap me-1"></i>{{ $task->department?->name ?? 'без отдела' }}
                    <span class="mx-1">·</span>
                    <i class="ti ti-calendar-event me-1"></i><span class="{{ $isLate ? 'text-danger fw-semibold' : '' }}">{{ $task->deadline?->format('d.m.Y H:i') ?? 'дедлайн не задан' }}</span>
                </div>            </div>
            <div class="d-flex gap-2 d-print-none">
                <a class="btn btn-ghost-secondary" href="{{ route('tasks.index') }}"><i class="ti ti-arrow-left me-1"></i>К задачам</a>
                @if ($canEditTask)
                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editTask"><i class="ti ti-pencil me-1"></i>Изменить</button>
                    <form method="post" action="{{ route('tasks.destroy', $task) }}" onsubmit="return confirm('Удалить задачу {{ $task->number }}? Открытые подзадачи запрещают удаление.')" title="Удалить">
                        @csrf @method('DELETE')
                        <button class="btn btn-ghost-danger"><i class="ti ti-trash"></i></button>
                    </form>
                @endif
            </div>
        </div>
        @if ($task->description)
            <div class="mt-3 pt-3 border-top text-secondary" style="white-space: pre-line;">{{ $task->description }}</div>
        @endif
    </div>
</div>

{{-- ===== Лента жизненного цикла: где задача сейчас ===== --}}
<div class="crm-stepper mb-3" role="list" aria-label="Этапы задачи">
    @foreach ($flow as $i => $step)
        @php
            $state = $flowIndex < 0 ? 'off' : ($i < $flowIndex ? 'done' : ($i === $flowIndex ? 'current' : 'todo'));
        @endphp
        <div class="crm-stepper__item is-{{ $state }}" role="listitem">
            <span class="crm-stepper__dot"><i class="ti ti-{{ $state === 'done' ? 'check' : $step['icon'] }}"></i></span>
            <span class="crm-stepper__label">{{ $step['label'] }}</span>
        </div>
        @if (! $loop->last)<span class="crm-stepper__line is-{{ $state === 'done' ? 'done' : 'todo' }}"></span>@endif
    @endforeach
    @if ($flowIndex < 0)
        <span class="crm-chip crm-chip--grey ms-2">Отменена</span>
    @endif
</div>

<div class="card mb-3 border-primary-subtle">
    <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <span class="avatar bg-primary-lt"><i class="ti ti-{{ $nextAction['icon'] }}"></i></span>
            <div><div class="fw-semibold">Следующий шаг: {{ $nextAction['label'] }}</div><div class="text-secondary small">{{ $nextAction['hint'] }}</div></div>
        </div>
        @if ($nextAction['type'] === 'status')
            <form method="post" action="{{ route('tasks.status', $task) }}" data-submit-guard>
                @csrf @method('PUT')
                <input type="hidden" name="status" value="{{ $nextAction['status'] }}">
                <button class="btn btn-primary"><i class="ti ti-{{ $nextAction['icon'] }} me-1"></i>{{ $nextAction['label'] }}</button>
            </form>
        @elseif ($nextAction['type'] === 'result')
            <a class="btn btn-primary" href="#task-result"><i class="ti ti-send me-1"></i>{{ $nextAction['label'] }}</a>
        @endif
    </div>
</div>

{{-- ===== Учёт времени ===== --}}
<div class="card mb-3 task-timer d-print-none">
    <div class="card-body py-3">
        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
            <strong class="d-flex align-items-center"><i class="ti ti-clock-hour-4 me-1"></i>Учёт времени</strong>
            <span class="crm-chip crm-chip--cyan ms-auto">Итого затрачено: {{ intdiv((int) $totalMinutes, 60) }} ч {{ $totalMinutes % 60 }} мин</span>
        </div>

        @if ($openLog !== null)
            <div class="task-timer__state task-timer__state--live">
                <span class="task-timer__dot" aria-hidden="true"></span>
                <span>Идёт учёт времени — начат в <strong>{{ $openLog->started_at->format('H:i') }}</strong></span>
                <span class="task-timer__counter" data-started="{{ $openLog->started_at->toIso8601String() }}">0:00:00</span>
            </div>
        @elseif ($otherOpenLog !== null)
            <div class="task-timer__state text-secondary">
                <i class="ti ti-info-circle me-1"></i>
                <span>Сейчас идёт учёт по другой задаче<span class="d-none d-sm-inline"> — «{{ $otherOpenLog->task?->title }}»</span>.</span>
            </div>
        @else
            <div class="task-timer__state text-secondary">
                <i class="ti ti-player-play me-1"></i>
                <span>Учёт не запущен — нажмите «Начать учёт времени», чтобы отработать сессию.</span>
            </div>
        @endif

        <div class="table-responsive task-timer__history mt-3">
            <table class="table table-sm table-vcenter mb-0">
                <thead><tr><th>Начало</th><th>Окончание</th><th>Длительность</th><th>Исполнитель</th></tr></thead>
                <tbody>
                    @forelse ($historyLogs as $log)
                        <tr>
                            <td class="text-nowrap">{{ $log->started_at->format('d.m.Y H:i') }}</td>
                            <td class="text-nowrap">{{ $log->stopped_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            <td class="fw-semibold text-nowrap">{{ intdiv((int) $log->minutes, 60) }} ч {{ $log->minutes % 60 }} мин</td>
                            <td class="text-secondary text-nowrap">{{ $log->employee?->last_name }} {{ $log->employee?->first_name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-secondary">Записей пока нет</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <form method="post" action="{{ route('tasks.time', $task) }}" class="mt-3" data-submit-guard>
            @csrf
            @if ($openLog !== null)
                <button class="btn btn-ghost-danger"><i class="ti ti-player-stop me-1"></i>Остановить учёт</button>
            @else
                <button class="btn btn-outline-success"><i class="ti ti-player-play me-1"></i>Начать учёт времени</button>
            @endif
        </form>
    </div>
</div>

<div class="row g-3">
    {{-- ===== Рабочая колонка ===== --}}
    <div class="col-lg-7">
        <div class="crm-form__section" id="task-result">
            <h3>Результат работы</h3>
            <div class="crm-form__desc">Заполняйте только когда задача готова к проверке.</div>
            <form method="post" action="{{ route('tasks.result', $task) }}" data-submit-guard>
                @csrf
                <div class="crm-form__field">
                    <label class="form-label" for="ts-result">Что сделано</label>
                    <textarea id="ts-result" name="summary" class="form-control" rows="3" placeholder="Например: подготовил договор, ссылка во вложении; прошу проверить пункт 4."></textarea>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-success"><i class="ti ti-send me-1"></i>Отправить на проверку</button>
                </div>
            </form>
        </div>

        <details class="crm-form__section">
            <summary class="fw-semibold" style="cursor: pointer;">Дополнительные действия</summary>
            <div class="text-secondary small mt-2 mb-3">Измените статус вручную, если автоперехода недостаточно.</div>
            <form method="post" action="{{ route('tasks.status', $task) }}" class="crm-form__row mb-2" data-submit-guard>
                @csrf @method('PUT')
                <div class="crm-form__field">
                    <label class="form-label" for="ts-status">Статус</label>
                    <select id="ts-status" name="status" class="form-select">
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected($task->status->value === $status->value)>{{ $crm_label('task_status', $status->value) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="ts-comment">Комментарий к изменению</label>
                    <input type="text" id="ts-comment" name="comment" class="form-control" placeholder="Необязательно">
                </div>
                <div class="crm-form__field d-flex align-items-end"><button class="btn btn-outline-primary w-100 justify-content-center">Сохранить</button></div>
            </form>
        </details>

        <div class="crm-form__section">
            <h3>Подзадачи <span class="text-secondary fw-normal">({{ $task->children->count() }})</span></h3>
            <div class="crm-form__desc">Дробление крупной задачи на шаги.</div>
            <div class="crm-queue mb-3">
                @forelse ($task->children as $child)
                    <a class="crm-queue__item text-reset text-decoration-none" style="--c: #0b9b72;" href="{{ route('tasks.show', $child) }}">
                        <div class="crm-queue__body">
                            <div class="crm-queue__title">{{ $child->title }}</div>
                        </div>
                        <span class="crm-chip {{ [
                            'completed' => 'crm-chip--green', 'overdue' => 'crm-chip--red', 'cancelled' => 'crm-chip--grey',
                            'returned' => 'crm-chip--orange', 'review' => 'crm-chip--orange', 'planned' => 'crm-chip--cyan',
                            'new' => 'crm-chip--grey',
                        ][$child->status->value] ?? 'crm-chip' }}">{{ $crm_label('task_status', $child->status->value) }}</span>
                    </a>
                @empty
                    <div class="crm-queue__sub">Подзадач нет.</div>
                @endforelse
            </div>
            @if ($canCreateSubtask)
                <form method="post" action="{{ route('tasks.subtasks', $task) }}" class="crm-form__row" data-submit-guard>
                    @csrf
                    <div class="crm-form__field">
                        <label class="form-label" for="st-title">Новая подзадача<span class="req">*</span></label>
                        <input type="text" id="st-title" name="title" class="form-control" placeholder="Заголовок" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="st-priority">Приоритет<span class="req">*</span></label>
                        <select id="st-priority" name="priority" class="form-select" required>
                            @foreach (['low', 'medium', 'high', 'critical'] as $p)
                                <option value="{{ $p }}" @selected($task->priority->value === $p)>{{ $crm_label('task_priority', $p) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="st-emp">Кому</label>
                        <select id="st-emp" name="employee_id" class="form-select">
                            <option value="">— выбрать —</option>
                            @foreach (\App\Models\Employee::query()->where('status', 'active')->orderBy('last_name')->get() as $option)
                                <option value="{{ $option->id }}">{{ $option->last_name }} {{ $option->first_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="st-deadline">Срок</label>
                        <input type="datetime-local" id="st-deadline" name="deadline" class="form-control">
                    </div>
                    <div class="crm-form__field subtask-desc">
                        <label class="form-label" for="st-desc">Описание</label>
                        <textarea id="st-desc" name="description" class="form-control" rows="2" placeholder="Необязательно"></textarea>
                    </div>
                    <div class="crm-form__field d-flex align-items-end">
                        <button class="btn btn-primary w-100 justify-content-center"><i class="ti ti-plus me-1"></i>Добавить</button>
                    </div>
                </form>
            @endif
        </div>

        <div class="crm-form__section">
            <h3>Комментарии</h3>
            <div class="crm-form__desc">Обсуждение задачи участниками.</div>
            <div class="crm-chat mb-3">
                @forelse ($task->comments()->with('employee')->get() as $comment)
                    <div class="crm-msg">
                        <span class="crm-msg__avatar">{{ mb_strtoupper(mb_substr($comment->employee->first_name, 0, 1)) }}</span>
                        <div>
                            <div class="crm-timeline__time mb-1">
                                <strong style="color: var(--tblr-body-color);">{{ $comment->employee->last_name }} {{ $comment->employee->first_name }}</strong>
                                · {{ $comment->created_at?->format('d.m H:i') }}
                            </div>
                            <div class="crm-msg__bubble">{{ $comment->body }}</div>
                        </div>
                    </div>
                @empty
                    <div class="crm-queue__sub">Комментариев нет — напишите первым.</div>
                @endforelse
            </div>
            <form method="post" action="{{ route('tasks.comment', $task) }}" class="crm-chat__dock" data-submit-guard>
                @csrf
                <input type="text" name="body" class="form-control" placeholder="Комментарий…" required aria-label="Комментарий">
                <button class="btn btn-primary"><i class="ti ti-send"></i></button>
            </form>
        </div>
    </div>

    {{-- ===== Контекстная колонка: вкладки ===== --}}
    <div class="col-lg-5">
        <ul class="nav crm-tabs mb-3 d-print-none" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tt-people-btn" data-bs-toggle="tab" data-bs-target="#tt-people" type="button" role="tab" aria-controls="tt-people" aria-selected="true">Исполнители</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tt-history-btn" data-bs-toggle="tab" data-bs-target="#tt-history" type="button" role="tab" aria-controls="tt-history" aria-selected="false">История</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tt-files-btn" data-bs-toggle="tab" data-bs-target="#tt-files" type="button" role="tab" aria-controls="tt-files" aria-selected="false">
                    Файлы <span class="badge bg-secondary-lt ms-1">{{ $attachments->count() }}</span>
                </button>
            </li>
        </ul>

        <div class="tab-content">
        <div class="tab-pane fade show active" id="tt-people" role="tabpanel" aria-labelledby="tt-people-btn">
        <div class="crm-form__section">
            <h3>Исполнители</h3>
            <div class="crm-form__desc">Назначения и делегирование.</div>
            <div class="table-responsive">
                <table class="table table-hover table-vcenter card-table mb-0">
                    <thead><tr><th>Исполнитель</th><th>Тип</th><th>Статус</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($task->assignments as $assignment)
                            <tr>
                                <td>
                                    @if ($assignment->assignee_type->value === 'department')
                                        <div class="crm-table__main"><i class="ti ti-building-community me-1"></i>{{ $assignment->department?->name ?? '—' }}</div>
                                        <div class="crm-table__sub">все сотрудники отдела</div>
                                    @else
                                        <div class="crm-table__main">{{ $assignment->employee?->last_name }} {{ $assignment->employee?->first_name }}</div>
                                        <div class="crm-table__sub">{{ $assignment->employee?->employee_no ?? '' }}</div>
                                    @endif
                                </td>
                                <td><span class="crm-chip {{ $assignment->assignee_type->value === 'department' ? 'crm-chip--cyan' : 'crm-chip--grey' }}">{{ ['employee' => 'Сотрудник', 'department' => 'Отдел'][$assignment->assignee_type->value] ?? $assignment->assignee_type->value }}</span></td>
                                <td><span class="crm-chip">{{ $crm_label('task_assignment_status', $assignment->assignment_status->value) }}</span></td>
                                <td>
                                    @if (isset($assignment->employee_id) && $assignment->employee_id === auth()->user()->employee->id)
                                        <form method="post" action="{{ route('tasks.delegate', $task) }}" class="d-flex gap-1" data-submit-guard>
                                            @csrf
                                            <input type="hidden" name="assignment_id" value="{{ $assignment->id }}">
                                            <select name="to_employee_id" class="form-select form-select-sm" aria-label="Кому делегировать">
                                                @foreach (\App\Models\Employee::query()->where('status', 'active')->orderBy('last_name')->get() as $option)
                                                    @if ($option->id !== auth()->user()->employee->id)
                                                        <option value="{{ $option->id }}">{{ $option->last_name }}</option>
                                                    @endif
                                                @endforeach
                                            </select>
                                            <button class="btn btn-sm btn-ghost-secondary" title="Делегировать"><i class="ti ti-arrow-right"></i></button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-secondary">Назначений нет</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        </div><!-- /Исполнители -->

        <div class="tab-pane fade" id="tt-history" role="tabpanel" aria-labelledby="tt-history-btn">
        <div class="crm-form__section">
            <h3>История статусов</h3>
            <div class="crm-form__desc">Все переходы по задаче.</div>
            <div class="crm-timeline">
                @forelse ($task->statusHistory->reverse()->values() as $history)
                    <div class="crm-timeline__item">
                        <div class="crm-timeline__time">{{ $history->created_at?->format('d.m H:i') }}</div>
                        <div class="crm-timeline__title">
                            @if ($history->from_status !== null)
                                {{ $history->from_status }} →
                            @endif
                            <strong>{{ $history->to_status }}</strong>
                        </div>
                        @if ($history->comment !== null)
                            <div class="crm-timeline__text">{{ Str::limit($history->comment, 90) }}</div>
                        @endif
                    </div>
                @empty
                    <div class="crm-timeline__text">Переходов пока нет.</div>
                @endforelse
            </div>
        </div>
        </div><!-- /История -->

        <div class="tab-pane fade" id="tt-files" role="tabpanel" aria-labelledby="tt-files-btn">
        <div class="crm-form__section">
            <h3>Файлы <span class="text-secondary fw-normal">({{ $attachments->count() }})</span></h3>
            <div class="crm-form__desc">Вложения задачи.</div>
            <ul class="list-group list-group-flush mb-2">
                @forelse ($attachments as $attachment)
                    @php
                        $myEmployeeId = auth()->user()?->employee?->id;
                        $canDeleteFile = $myEmployeeId !== null
                            && ((int) $attachment->employee_id === (int) $myEmployeeId
                                || (int) ($task->author_id ?? 0) === (int) $myEmployeeId
                                || auth()->user()->can('checkBoxPermission', 'tasks.manage'));
                    @endphp
                    <li class="list-group-item py-2 d-flex align-items-center gap-2">
                        <a href="{{ route('tasks.attachments.download', [$task, $attachment]) }}" title="Скачать файл" class="d-flex align-items-center gap-1">
                            <i class="ti ti-paperclip"></i> {{ $attachment->original_name }}
                        </a>
                        <span class="text-secondary small">
                            {{ number_format($attachment->size / 1024, 0) }} КБ · {{ $attachment->employee?->last_name }} · {{ $attachment->created_at?->format('d.m H:i') }}
                        </span>
                        @if ($canDeleteFile)
                            <form method="post" action="{{ route('tasks.attachments.destroy', [$task, $attachment]) }}" class="ms-auto"
                                  onsubmit="return confirm('Удалить файл «{{ $attachment->original_name }}»?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-ghost-danger" title="Удалить файл"><i class="ti ti-trash"></i></button>
                            </form>
                        @endif
                    </li>
                @empty
                    <li class="list-group-item text-secondary">Файлов нет</li>
                @endforelse
            </ul>
            @if ($canAttach)
                <form method="post" action="{{ route('tasks.attachments.store', $task) }}" enctype="multipart/form-data" class="d-flex gap-2" data-submit-guard>
                    @csrf
                    <input type="file" name="file" class="form-control" required>
                    <button class="btn btn-outline-primary text-nowrap"><i class="ti ti-upload me-1"></i>Приложить</button>
                </form>
            @endif
        </div>
        </div><!-- /Файлы -->
        </div><!-- /tab-content -->
    </div>
</div>

{{-- ===== Модалка правки ===== --}}
@if ($canEditTask)
<div class="modal modal-blur fade" id="editTask" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('tasks.update', $task) }}" data-submit-guard>
            @csrf @method('PATCH')
            <div class="modal-header"><h5 class="modal-title">Правка задачи {{ $task->number }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__field">
                    <label class="form-label" for="et-title">Название<span class="req">*</span></label>
                    <input type="text" id="et-title" name="title" class="form-control" value="{{ old('title', $task->title) }}" required>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="et-desc">Описание</label>
                    <textarea id="et-desc" name="description" class="form-control" rows="4">{{ old('description', $task->description) }}</textarea>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="et-priority">Приоритет</label>
                        <select id="et-priority" name="priority" class="form-select">
                            @foreach (['low', 'medium', 'high', 'critical'] as $p)
                                <option value="{{ $p }}" @selected(old('priority', $task->priority->value) === $p)>{{ $crm_label('task_priority', $p) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="et-deadline">Дедлайн</label>
                        <input type="datetime-local" id="et-deadline" name="deadline" class="form-control"
                               value="{{ old('deadline', $task->deadline?->format('Y-m-d\TH:i')) }}">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>
@endif
@endsection

@push('styles')
<style>
    /* ===== Учёт времени (карточка на странице задачи) ===== */
    .task-timer__state {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        padding: 10px 14px;
        border: 1px solid rgba(var(--crm-accent-rgb), .16);
        border-radius: var(--crm-radius-md);
        background: rgba(var(--crm-accent-rgb), .05);
        font-size: var(--crm-text-caption);
        line-height: var(--crm-lh-caption);
    }
    .task-timer__state--live {
        border-color: rgba(var(--crm-success-rgb), .35);
        background: rgba(var(--crm-success-rgb), .07);
    }
    .task-timer__dot {
        flex: none;
        width: 9px;
        height: 9px;
        border-radius: 50%;
        background: var(--crm-success);
        box-shadow: 0 0 0 0 rgba(var(--crm-success-rgb), .55);
        animation: task-timer-pulse 1.6s ease-out infinite;
    }
    @keyframes task-timer-pulse {
        0%   { box-shadow: 0 0 0 0 rgba(var(--crm-success-rgb), .55); }
        70%  { box-shadow: 0 0 0 8px rgba(var(--crm-success-rgb), 0); }
        100% { box-shadow: 0 0 0 0 rgba(var(--crm-success-rgb), 0); }
    }
    .task-timer__counter {
        margin-left: auto;
        font-size: 1.0625rem;
        font-weight: 600;
        font-variant-numeric: tabular-nums;
        color: var(--crm-text);
    }
    .task-timer__history td { padding-top: .4rem; padding-bottom: .4rem; }
    /* подзадача: описание на всю ширину сетки crm-form__row */
    .subtask-desc { grid-column: 1 / -1; }
    @media (prefers-reduced-motion: reduce) {
        .task-timer__dot { animation: none; }
    }
</style>
@endpush

@push('scripts')
<script>
    (function () {
        /* живой счётчик идущего учёта времени */
        document.querySelectorAll('.task-timer__counter[data-started]').forEach(function (el) {
            var startedAt = new Date(el.dataset.started).getTime();
            if (isNaN(startedAt)) { return; }
            var pad = function (n) { return String(n).padStart(2, '0'); };
            var tick = function () {
                var sec = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
                el.textContent = Math.floor(sec / 3600) + ':' + pad(Math.floor((sec % 3600) / 60)) + ':' + pad(sec % 60);
            };
            tick();
            setInterval(tick, 1000);
        });
    })();
</script>
@endpush
