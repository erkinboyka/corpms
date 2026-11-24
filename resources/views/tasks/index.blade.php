@extends('layouts.tabler')
@section('workspace_layout', 'overview')

@section('title', __('Задачи'))

@php
    $statusCols = [
        'new' => ['label' => __('Новые'), 'icon' => 'circle-plus', 'chip' => 'crm-chip--grey'],
        'planned' => ['label' => __('Запланированы'), 'icon' => 'calendar-event', 'chip' => 'crm-chip--cyan'],
        'in_progress' => ['label' => __('В работе'), 'icon' => 'player-play', 'chip' => 'crm-chip'],
        'review' => ['label' => __('На проверке'), 'icon' => 'eye', 'chip' => 'crm-chip--orange'],
        'returned' => ['label' => __('Возвращены'), 'icon' => 'rotate', 'chip' => 'crm-chip--orange'],
        'completed' => ['label' => __('Готово'), 'icon' => 'circle-check', 'chip' => 'crm-chip--green'],
        'cancelled' => ['label' => __('Отменены'), 'icon' => 'circle-x', 'chip' => 'crm-chip--grey'],
        'overdue' => ['label' => __('Просрочены'), 'icon' => 'alarm', 'chip' => 'crm-chip--red'],
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
            <div class="crm-eyebrow">{{ __('Работа') }}</div>
            <h1 class="page-title mt-1">{{ __('Задачи') }}</h1>
            <div class="text-secondary mt-1">{{ __('Список · всего') }} {{ $tasks->total() }} {{ __('показаны') }} {{ $tasks->firstItem() ?? 0 }}–{{ $tasks->lastItem() ?? 0 }}</div>
        </div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <select name="status" class="form-select" aria-label="{{ __("Статус") }}" onchange="this.form.submit()">
        <option value="">{{ __('Все статусы') }}</option>
        @foreach (array_keys($statusCols) as $statusOption)
            <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ $crm_label('task_status', $statusOption) }}</option>
        @endforeach
    </select>
    <select name="priority" class="form-select" aria-label="{{ __("Приоритет") }}" onchange="this.form.submit()">
        <option value="">{{ __('Любой приоритет') }}</option>
        @foreach (['low', 'medium', 'high', 'critical'] as $p)
            <option value="{{ $p }}" @selected(request('priority') === $p)>{{ $crm_label('task_priority', $p) }}</option>
        @endforeach
    </select>
    <div class="crm-toolbar__spacer"></div>
    <div class="crm-switch" data-crm-switch="tasks" role="group" aria-label="{{ __("Вид задач") }}">
        <button type="button" data-view="list"><i class="ti ti-list"></i>{{ __('Список') }}</button>
        <button type="button" data-view="kanban"><i class="ti ti-layout-kanban"></i>Kanban</button>
        <button type="button" data-view="calendar"><i class="ti ti-calendar"></i>{{ __('Календарь') }}</button>
    </div>
</form>

<div data-crm-view-container="tasks" data-view-default="list">
    {{-- ===== Вид: список ===== --}}
    <div data-view-only="list">
        <div class="card">
            <div class="table-responsive">
                <table data-record-key="1" class="table table-hover table-vcenter card-table crm-thead-sticky">
                    <thead><tr><th scope="col">№</th><th scope="col">{{ __('Заголовок') }}</th><th scope="col">{{ __('Автор') }}</th><th scope="col">{{ __('Отдел') }}</th><th scope="col">{{ __('Приоритет') }}</th><th scope="col">{{ __('Дедлайн') }}</th><th scope="col">{{ __('Статус') }}</th><th scope="col"></th></tr></thead>
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
                                <td class="text-end"><a class="btn btn-sm btn-ghost-secondary crm-row-actions" href="{{ route('tasks.show', $task) }}" title="{{ __("Открыть") }}"><i class="ti ti-chevron-right"></i></a></td>
                            </tr>
                        @empty
                            <tr><td colspan="8">@include('partials.empty-state', ['icon' => 'list-check', 'title' => __('Задач нет'), 'text' => __('По выбранным фильтрам задач не найдено. Снимите фильтр или создайте задачу.')])</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($tasks->hasPages())
                <div class="card-footer d-flex align-items-center">
                    <div class="text-secondary me-auto">{{ __('Страница') }} {{ $tasks->currentPage() }} {{ __('из') }} {{ $tasks->lastPage() }}</div>
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
                                    aria-label="{{ __('Переместить задачу') }} «{{ $task->title }}» {{ __('в другую колонку') }}"
                                    title="{{ __("Переместить в колонку") }}">
                                <i class="ti ti-arrows-move"></i>
                            </button>
                            <a class="k-title text-reset text-decoration-none" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                            <div class="k-meta">
                                <span class="crm-chip {{ $prioChips[$task->priority->value] ?? 'crm-chip--grey' }}">{{ $crm_label('task_priority', $task->priority->value) }}</span>
                                <span @if($task->deadline && $task->deadline->isPast() && ! in_array($stKey, ['completed', 'cancelled'], true)) class="text-danger fw-semibold" @else class="text-secondary" @endif>
                                    <i class="ti ti-calendar-event me-1"></i>{{ $task->deadline?->format('d.m') ?? '—' }}
                                </span>
                            </div>
                            <div class="crm-kanban__menu" hidden role="menu" aria-label="{{ __("Переместить в колонку") }}">
                                @foreach ($statusCols as $colKey => $colMeta)
                                    @continue($colKey === $stKey)
                                    <button type="button" role="menuitem" data-move-to="{{ $colKey }}">
                                        <i class="ti ti-{{ $colMeta['icon'] }}"></i>{{ $colMeta['label'] }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="crm-kanban__empty">{{ __('Пусто') }}</div>
                    @endforelse
                </div>
            @endforeach
        </div>
        <div class="text-secondary small mt-2"><i class="ti ti-hand-move me-1"></i>{{ __('Перетащите карточку в другую колонку или нажмите') }} <i class="ti ti-arrows-move"></i> {{ __('на карточке и выберите колонку (тач-устройства)') }}</div>
    </div>

    {{-- ===== Вид: календарь ===== --}}
        <div data-view-only="calendar">
        <div class="row g-3">
            <div class="col-xl-8" data-crm-sec="cal" data-crm-sec-label="{{ __('Календарь') }}">
                <div class="crm-cal">
                    <div class="crm-cal__grid">
                        @foreach ([__('Пн'), __('Вт'), __('Ср'), __('Чт'), __('Пт'), __('Сб'), __('Вс')] as $dow)
                            <div class="crm-cal__dow">{{ $dow }}</div>
                        @endforeach
                        @for ($i = 0; $i < $calLead; $i++)
                            <div class="is-out"></div>
                        @endfor
                        @for ($day = 1; $day <= $calDaysInMonth; $day++)
                            @php
                                $date = \Carbon\Carbon::create($calStart->year, $calStart->month, $day);
                                $dow = $date->dayOfWeekIso;
                                $isToday = $date->isToday();
                                $dayTasks = collect($calByDay[$day] ?? []);
                                $payload = [
                                    'title' => $date->locale(app()->getLocale())->isoFormat('D MMMM YYYY, dddd'),
                                    'items' => $dayTasks->map(fn($task) => [
                                        'title' => $task->title,
                                        'number' => $task->number,
                                        'status' => $crm_label('task_status', $task->status->value),
                                        'priority' => $crm_label('task_priority', $task->priority->value),
                                        'deadline' => $task->deadline?->format('d.m.Y H:i'),
                                        'url' => route('tasks.show', $task),
                                        'overdue' => $task->deadline !== null && $task->deadline->isPast() && ! in_array($task->status->value, ['completed','cancelled'], true),
                                    ])->values()->all(),
                                ];
                                $classes = ['crm-cal__day-btn'];
                                if ($dow >= 6) $classes[] = 'is-weekend';
                                if ($isToday) $classes[] = 'is-today';
                            @endphp
                            <div class="{{ implode(' ', $classes) }}" role="button" tabindex="0"
                                 data-task-day='@json($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG)'>
                                <span class="crm-cal__num">{{ $day }}</span>
                                @foreach ($dayTasks->take(3) as $task)
                                    <span class="crm-cal__event {{ in_array($task->status->value, ['completed','cancelled'], true) ? 'crm-cal__event--off' : ($task->deadline && $task->deadline->isPast() ? 'crm-cal__event--holiday' : 'crm-cal__event--work') }}" title="{{ $task->title }}">{{ \Illuminate\Support\Str::limit($task->title, 22) }}</span>
                                @endforeach
                                @if($dayTasks->count() > 3)
                                    <span class="crm-cal__event crm-cal__event--off">+{{ $dayTasks->count() - 3 }}</span>
                                @endif
                            </div>
                        @endfor
                    </div>
                </div>
            </div>
            <div class="col-xl-4" data-crm-sec="upcoming" data-crm-sec-label="{{ __('Ближайшие сроки') }}">
                <div class="crm-form__section">
                    <h3>{{ __('Ближайшие сроки') }}</h3>
                    <div class="crm-form__desc">{{ __('Открытые задачи с ближайшим сроком') }}</div>
                    <div class="crm-queue">
                        @forelse ($upcoming as $task)
                            <a class="crm-queue__item text-reset text-decoration-none" style="--c: {{ $task->deadline->isPast() ? 'var(--crm-danger)' : 'var(--crm-success)' }};" href="{{ route('tasks.show', $task) }}">
                                <div class="crm-queue__body">
                                    <div class="crm-queue__title">{{ $task->title }}</div>
                                    <div class="crm-queue__sub">
                                        {{ $task->deadline->format('d.m.Y') }}
                                        @if ($task->deadline->isPast()) <span class="text-danger fw-semibold">{{ __('просрочено') }}</span>@endif
                                    </div>
                                </div>
                                <span class="crm-chip {{ $prioChips[$task->priority->value] ?? 'crm-chip--grey' }}">{{ $crm_label('task_priority', $task->priority->value) }}</span>
                            </a>
                        @empty
                            <div class="crm-queue__sub">{{ __('Нет задач с ближайшим сроком') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal modal-blur fade" id="taskDayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="taskDayTitle">{{ __('День') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Закрыть') }}"></button>
            </div>
            <div class="modal-body" id="taskDayBody"></div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('taskDayModal');
    var title = document.getElementById('taskDayTitle');
    var body = document.getElementById('taskDayBody');
    function openDay(el) {
        if (!modalEl) return;
        var raw = el.getAttribute('data-task-day') || '{}';
        var d = {};
        try { d = JSON.parse(raw); } catch (e) { d = {}; }
        title.textContent = d.title || '{{ __('День') }}';
        body.innerHTML = '';
        var items = d.items || [];
        if (!items.length) {
            var empty = document.createElement('div');
            empty.className = 'text-secondary';
            empty.textContent = '{{ __('На этот день задач нет.') }}';
            body.appendChild(empty);
        } else {
            items.forEach(function (it) {
                var a = document.createElement('a');
                a.className = 'd-flex align-items-start gap-3 p-3 rounded-3 border mb-2 text-reset text-decoration-none';
                a.href = it.url || '#';
                var dot = document.createElement('span');
                var tone = it.overdue ? 'var(--crm-danger)' : 'rgba(34,197,94,.9)';
                dot.style.cssText = 'width:10px;height:10px;border-radius:50%;margin-top:6px;flex:0 0 10px;background:' + tone;
                var c = document.createElement('div');
                c.style.minWidth = '0';
                var n = document.createElement('div'); n.className = 'fw-semibold'; n.textContent = (it.number ? '#' + it.number + ' ' : '') + (it.title || '');
                var m = document.createElement('div'); m.className = 'text-secondary small mt-1';
                m.textContent = [it.status, it.priority, it.deadline].filter(Boolean).join(' · ');
                c.appendChild(n); c.appendChild(m);
                a.appendChild(dot); a.appendChild(c);
                body.appendChild(a);
            });
        }
        if (window.bootstrap && bootstrap.Modal) bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
    document.addEventListener('click', function (e) { var el = e.target.closest('[data-task-day]'); if (el) openDay(el); });
    document.addEventListener('keydown', function (e) { if (e.key !== 'Enter' && e.key !== ' ') return; var el = e.target.closest('[data-task-day]'); if (!el) return; e.preventDefault(); openDay(el); });
});
</script>
@endpush
@endsection
