@extends('layouts.tabler')
@section('workspace_layout', 'overview')

@section('title', 'Задачи')

@php
    $statusCols = [
        'new' => ['label' => 'Новые', 'icon' => 'circle-plus', 'chip' => 'crm-chip--grey'],
        'planned' => ['label' => 'Запланированы', 'icon' => 'calendar-event', 'chip' => 'crm-chip--cyan'],
        'in_progress' => ['label' => 'В работе', 'icon' => 'player-play', 'chip' => 'crm-chip'],
        'review' => ['label' => 'На проверке', 'icon' => 'eye', 'chip' => 'crm-chip--orange'],
        'returned' => ['label' => 'Возвращены', 'icon' => 'rotate', 'chip' => 'crm-chip--orange'],
        'completed' => ['label' => 'Готово', 'icon' => 'circle-check', 'chip' => 'crm-chip--green'],
        'cancelled' => ['label' => 'Отменены', 'icon' => 'circle-x', 'chip' => 'crm-chip--grey'],
        'overdue' => ['label' => 'Просрочены', 'icon' => 'alarm', 'chip' => 'crm-chip--red'],
    ];
    $prioChips = ['critical' => 'crm-chip--red', 'high' => 'crm-chip--orange', 'medium' => 'crm-chip--cyan', 'low' => 'crm-chip--grey'];

    // календарь: текущий месяц, задачи с дедлайнами по дням
    $calStart = now()->startOfMonth();
    $calDaysInMonth = $calStart->daysInMonth;
    $calLead = $calStart->dayOfWeekIso - 1;
    $calByDay = [];
    $upcoming = collect();
    foreach ($board->flatten() as $bTask) {
        if ($bTask->deadline === null) { continue; }
        if ($bTask->deadline->isSameMonth($calStart)) {
            $calByDay[(int) $bTask->deadline->format('d')][] = $bTask;
        }
        if ($bTask->deadline->isToday() || ($bTask->deadline->isFuture() && ! in_array($bTask->status->value, ['completed', 'cancelled'], true))) {
            $upcoming->push($bTask);
        }
    }
    $upcoming = $upcoming->sortBy('deadline')->take(8)->values();
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Работа</div>
            <h1 class="page-title mt-1">Задачи</h1>
            <div class="text-secondary mt-1">Список · всего {{ $tasks->total() }} · показаны {{ $tasks->firstItem() ?? 0 }}–{{ $tasks->lastItem() ?? 0 }}</div>
        </div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <select name="status" class="form-select" aria-label="Статус" onchange="this.form.submit()">
        <option value="">Все статусы</option>
        @foreach (array_keys($statusCols) as $statusOption)
            <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ $crm_label('task_status', $statusOption) }}</option>
        @endforeach
    </select>
    <select name="priority" class="form-select" aria-label="Приоритет" onchange="this.form.submit()">
        <option value="">Любой приоритет</option>
        @foreach (['low', 'medium', 'high', 'critical'] as $p)
            <option value="{{ $p }}" @selected(request('priority') === $p)>{{ $crm_label('task_priority', $p) }}</option>
        @endforeach
    </select>
    <div class="crm-toolbar__spacer"></div>
    <div class="crm-switch" data-crm-switch="tasks" role="group" aria-label="Вид задач">
        <button type="button" data-view="list"><i class="ti ti-list"></i>Список</button>
        <button type="button" data-view="kanban"><i class="ti ti-layout-kanban"></i>Kanban</button>
        <button type="button" data-view="calendar"><i class="ti ti-calendar"></i>Календарь</button>
    </div>
</form>

<div data-crm-view-container="tasks" data-view-default="list">
    {{-- ===== Вид: список ===== --}}
    <div data-view-only="list">
        <div class="card">
            <div class="table-responsive">
                <table data-record-key="1" class="table table-hover table-vcenter card-table crm-thead-sticky">
                    <thead><tr><th scope="col">№</th><th scope="col">Заголовок</th><th scope="col">Автор</th><th scope="col">Отдел</th><th scope="col">Приоритет</th><th scope="col">Дедлайн</th><th scope="col">Статус</th><th scope="col"></th></tr></thead>
                    <tbody>
                        @forelse ($tasks as $task)
                            @php
                                $prioClass = $prioChips[$task->priority->value] ?? 'crm-chip--grey';
                                $st = $task->status->value;
                                $statusClass = $statusCols[$st]['chip'] ?? 'crm-chip';
                                $isOverdue = $task->deadline !== null && $task->deadline->isPast() && ! in_array($st, ['completed', 'cancelled'], true);
                            @endphp
                            <tr>
                                <td class="text-secondary crm-table-num">{{ $task->number }}</td>
                                <td><a href="{{ route('tasks.show', $task) }}" class="text-reset text-decoration-none"><span class="crm-table__main">{{ $task->title }}</span></a></td>
                                <td>{{ $task->author?->last_name ?? '—' }}</td>
                                <td class="text-secondary">{{ $task->department?->name ?? '—' }}</td>
                                <td><span class="crm-chip {{ $prioClass }}">{{ $crm_label('task_priority', $task->priority->value) }}</span></td>
                                <td class="crm-table-num {{ $isOverdue ? 'text-danger fw-bold' : '' }}">
                                    @if ($isOverdue)<i class="ti ti-alert-circle me-1"></i>@endif{{ $task->deadline?->format('d.m.Y') ?? '—' }}
                                </td>
                                <td><span class="crm-chip {{ $statusClass }}">{{ $crm_label('task_status', $st) }}</span></td>
                                <td class="text-end"><a class="btn btn-sm btn-ghost-secondary crm-row-actions" href="{{ route('tasks.show', $task) }}" title="Открыть"><i class="ti ti-chevron-right"></i></a></td>
                            </tr>
                        @empty
                            <tr><td colspan="8">@include('partials.empty-state', ['icon' => 'list-check', 'title' => 'Задач нет', 'text' => 'По выбранным фильтрам задач не найдено. Снимите фильтр или создайте задачу.'])</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($tasks->hasPages())
                <div class="card-footer d-flex align-items-center">
                    <div class="text-secondary me-auto">Страница {{ $tasks->currentPage() }} из {{ $tasks->lastPage() }}</div>
                    {{ $tasks->links('pagination::tabler') }}
                </div>
            @endif
        </div>
    </div>

    {{-- ===== Вид: kanban ===== --}}
    <div data-view-only="kanban">
        <div class="crm-kanban" data-kanban>
            @foreach ($statusCols as $stKey => $meta)
                <div class="crm-kanban__col" data-status="{{ $stKey }}">
                    <div class="crm-kanban__head">
                        <span class="crm-kanban__title"><i class="ti ti-{{ $meta['icon'] }}"></i>{{ $meta['label'] }}</span>
                        <span class="crm-kanban__count">{{ ($board->get($stKey) ?? collect())->count() }}</span>
                    </div>
                    @forelse (($board->get($stKey) ?? collect()) as $task)
                        <div class="crm-kanban__card" draggable="true" data-task-id="{{ $task->id }}">
                            {{-- C4: тач-альтернатива drag&drop — меню «переместить в колонку» --}}
                            <button type="button" class="crm-kanban__move" data-kanban-move
                                    aria-haspopup="menu" aria-expanded="false"
                                    aria-label="Переместить задачу «{{ $task->title }}» в другую колонку"
                                    title="Переместить в колонку">
                                <i class="ti ti-arrows-move"></i>
                            </button>
                            <a class="k-title text-reset text-decoration-none" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                            <div class="k-meta">
                                <span class="crm-chip {{ $prioChips[$task->priority->value] ?? 'crm-chip--grey' }}">{{ $crm_label('task_priority', $task->priority->value) }}</span>
                                <span @if($task->deadline && $task->deadline->isPast() && ! in_array($stKey, ['completed', 'cancelled'], true)) class="text-danger fw-semibold" @else class="text-secondary" @endif>
                                    <i class="ti ti-calendar-event me-1"></i>{{ $task->deadline?->format('d.m') ?? '—' }}
                                </span>
                            </div>
                            <div class="crm-kanban__menu" hidden role="menu" aria-label="Переместить в колонку">
                                @foreach ($statusCols as $colKey => $colMeta)
                                    @continue($colKey === $stKey)
                                    <button type="button" role="menuitem" data-move-to="{{ $colKey }}">
                                        <i class="ti ti-{{ $colMeta['icon'] }}"></i>{{ $colMeta['label'] }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="crm-kanban__empty">Пусто</div>
                    @endforelse
                </div>
            @endforeach
        </div>
        <div class="text-secondary small mt-2"><i class="ti ti-hand-move me-1"></i>Перетащите карточку в другую колонку или нажмите <i class="ti ti-arrows-move"></i> на карточке и выберите колонку (тач-устройства).</div>
    </div>

    {{-- ===== Вид: календарь ===== --}}
    <div data-view-only="calendar">
        <div class="row g-3">
            <div class="col-xl-8" data-crm-sec="cal" data-crm-sec-label="Календарь">
                <div class="crm-cal">
                    <div class="crm-cal__head">
                        @foreach (['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'] as $dow)
                            <div>{{ $dow }}</div>
                        @endforeach
                    </div>
                    <div class="crm-cal__grid">
                        @for ($i = 0; $i < $calLead; $i++)
                            <div class="is-out"></div>
                        @endfor
                        @for ($day = 1; $day <= $calDaysInMonth; $day++)
                            @php
                                $dow = \Carbon\Carbon::create($calStart->year, $calStart->month, $day)->dayOfWeekIso;
                                $isToday = $day === (int) now()->format('d');
                            @endphp
                            <div class="{{ $dow >= 6 ? 'is-weekend' : '' }}{{ $isToday ? ' is-today' : '' }}">
                                <span class="crm-cal__day">{{ $day }}</span>
                                @foreach (($calByDay[$day] ?? []) as $task)
                                    <a class="crm-event" href="{{ route('tasks.show', $task) }}" title="{{ $task->title }}">{{ $task->title }}</a>
                                @endforeach
                            </div>
                        @endfor
                    </div>
                </div>
            </div>
            <div class="col-xl-4" data-crm-sec="upcoming" data-crm-sec-label="Ближайшие сроки">
                <div class="crm-form__section">
                    <h3>Ближайшие сроки</h3>
                    <div class="crm-form__desc">Незакрытые задачи с дедлайном впереди.</div>
                    <div class="crm-queue">
                        @forelse ($upcoming as $task)
                            <a class="crm-queue__item text-reset text-decoration-none" style="--c: {{ $task->deadline->isPast() ? 'var(--crm-danger)' : 'var(--crm-success)' }};" href="{{ route('tasks.show', $task) }}">
                                <div class="crm-queue__body">
                                    <div class="crm-queue__title">{{ $task->title }}</div>
                                    <div class="crm-queue__sub">
                                        {{ $task->deadline->format('d.m.Y') }}
                                        @if ($task->deadline->isPast())· <span class="text-danger fw-semibold">просрочено</span>@endif
                                    </div>
                                </div>
                                <span class="crm-chip {{ $prioChips[$task->priority->value] ?? 'crm-chip--grey' }}">{{ $crm_label('task_priority', $task->priority->value) }}</span>
                            </a>
                        @empty
                            <div class="crm-queue__sub">Сроков в этом месяце нет.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
