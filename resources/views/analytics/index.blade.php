@extends('layouts.tabler')

@section('title', 'Аналитика')

@php
    $taskMeta = [
        'new' => ['Новые', '#4299e1'],
        'planned' => ['Запланированные', '#7048e8'],
        'in_progress' => ['В работе', '#0b9b72'],
        'review' => ['На проверке', '#f76707'],
        'returned' => ['Возвращённые', '#ae3ec9'],
        'completed' => ['Завершённые', '#2fb344'],
        'cancelled' => ['Отменённые', '#6c7a91'],
        'overdue' => ['Просроченные', '#d63939'],
    ];
    $docMeta = [
        'draft' => ['Черновик', '#6c7a91'],
        'submitted' => ['На согласовании', '#4299e1'],
        'review' => ['На проверке', '#f76707'],
        'revision' => ['Доработка', '#ae3ec9'],
        'approved' => ['Согласовано', '#2fb344'],
        'signing' => ['На подписании', '#0b9b72'],
        'signed' => ['Подписано', '#0b9b72'],
        'registered' => ['Зарегистрировано', '#4299e1'],
        'archived' => ['В архиве', '#6c7a91'],
    ];

    $openTasks = ($tasks['new'] ?? 0) + ($tasks['planned'] ?? 0) + ($tasks['in_progress'] ?? 0) + ($tasks['review'] ?? 0);
    $taskTotal = array_sum($tasks);
    $docTotal = array_sum($documents);

    $trendMax = 1;
    $sumPresent = 0;
    $sumLate = 0;
    $sumRemote = 0;
    foreach ($trend as $t) {
        $dayTotal = $t['present'] + $t['late'] + $t['remote'];
        $trendMax = max($trendMax, $dayTotal);
        $sumPresent += $t['present'];
        $sumLate += $t['late'];
        $sumRemote += $t['remote'];
    }

    $maxHeadcount = 1;
    foreach ($departments as $row) {
        $maxHeadcount = max($maxHeadcount, $row['headcount']);
    }

    /* --- виджеты (п.3): меты и максимумы для столбчатых диаграмм --- */
    $prioMeta = [
        'critical' => ['Критический', '#d63939'],
        'high' => ['Высокий', '#f76707'],
        'medium' => ['Средний', '#4299e1'],
        'low' => ['Низкий', '#6c7a91'],
    ];
    $leaveMeta = [
        'pending' => ['Ожидают решения', '#f76707'],
        'approved' => ['Согласованы', '#2fb344'],
        'rejected' => ['Отклонены', '#d63939'],
        'cancelled' => ['Отменены', '#6c7a91'],
    ];
    $prioTotal = array_sum($priorities);
    $deadlineMax = max(1, max(array_values($deadlines)));
    $assigneeMax = max(1, max(array_column($assignees, 'count') ?: [1]));
    $tenureMax = max(1, max(array_values($tenure)));
    $leaveTotal = array_sum($leaves);

    $marksTotal = $sumPresent + $sumLate + $sumRemote;
    $trackedHours = round($timeTracking['minutes'] / 60, 1);

    $auditMax = 1;
    $auditActionMax = 1;
    if ($auditTrend !== null) {
        foreach ($auditTrend['days'] as $d) {
            $auditMax = max($auditMax, $d['count']);
        }
        foreach ($auditActions as $a) {
            $auditActionMax = max($auditActionMax, $a['count']);
        }
    }

    /* --- данные для графиков Chart.js (line / bar / doughnut / pie) --- */
    $chartTrendLabels = [];
    $chartPresent = [];
    $chartLate = [];
    $chartRemote = [];
    foreach ($trend as $row) {
        $chartTrendLabels[] = $row['date']->format('d.m');
        $chartPresent[] = (int) $row['present'];
        $chartLate[] = (int) $row['late'];
        $chartRemote[] = (int) $row['remote'];
    }

    $chartTasks = ['labels' => [], 'colors' => [], 'data' => []];
    foreach ($taskMeta as $key => $meta) {
        if (($tasks[$key] ?? 0) > 0) {
            $chartTasks['labels'][] = $meta[0];
            $chartTasks['colors'][] = $meta[1];
            $chartTasks['data'][] = (int) $tasks[$key];
        }
    }

    $chartDocs = ['labels' => [], 'colors' => [], 'data' => []];
    foreach ($docMeta as $key => $meta) {
        if (($documents[$key] ?? 0) > 0) {
            $chartDocs['labels'][] = $meta[0];
            $chartDocs['colors'][] = $meta[1];
            $chartDocs['data'][] = (int) $documents[$key];
        }
    }

    $chartPrio = ['labels' => [], 'colors' => [], 'data' => []];
    foreach ($prioMeta as $key => $meta) {
        if (($priorities[$key] ?? 0) > 0) {
            $chartPrio['labels'][] = $meta[0];
            $chartPrio['colors'][] = $meta[1];
            $chartPrio['data'][] = (int) $priorities[$key];
        }
    }
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Данные</div>
            <h2 class="page-title mt-1">Аналитика</h2>
            <div class="text-secondary mt-1">Сводные показатели по посещаемости, задачам, документам и кадрам</div>
        </div>
        <div class="col-auto d-print-none">
            <span class="crm-chip {{ $scope === 'company' ? 'crm-chip--green' : ($scope === 'department' ? 'crm-chip--cyan' : 'crm-chip--grey') }}">
                <i class="ti ti-scope me-1"></i>Scope: {{ $scope === 'company' ? 'компания' : ($scope === 'department' ? 'подразделение' : 'личный') }}
            </span>
        </div>
    </div>
</div>

{{-- ===== KPI-лента ===== --}}
<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #0b9b72;"><i class="ti ti-users"></i>Сотрудников</div>
        <div class="crm-metric__value">{{ $headcount['active'] }} <small>/ {{ $headcount['total'] }}</small></div>
        <div class="crm-metric__hint">активных / всего в области доступа</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #2fb344;"><i class="ti ti-door-enter"></i>На месте сегодня</div>
        <div class="crm-metric__value">{{ $attendance['present'] }}</div>
        <div class="crm-metric__hint">опоздали: {{ $attendance['late'] }} · удалённо: {{ $attendance['remote'] }} · без отметки: {{ $attendance['absent_from_schedule'] }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #4299e1;"><i class="ti ti-list-check"></i>Открытых задач</div>
        <div class="crm-metric__value">{{ $openTasks }}</div>
        <div class="crm-metric__hint">
            @if (($tasks['overdue'] ?? 0) > 0)
                <span class="text-danger">просрочено: {{ $tasks['overdue'] }}</span>
            @else
                просроченных нет
            @endif
        </div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #f76707;"><i class="ti ti-calendar-event"></i>Отпуска в ожидании</div>
        <div class="crm-metric__value">{{ $leaves['pending'] ?? 0 }}</div>
        <div class="crm-metric__hint">согласовано: {{ $leaves['approved'] ?? 0 }} · отклонено: {{ $leaves['rejected'] ?? 0 }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #2fb344;"><i class="ti ti-circle-check"></i>Выполнение задач</div>
        <div class="crm-metric__value">{{ $completion['pct'] }}<small>%</small></div>
        <div class="crm-metric__hint">завершено {{ $completion['completed'] }} · в работе {{ $completion['active'] }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #ae3ec9;"><i class="ti ti-clock-hour-4"></i>Учтено времени</div>
        <div class="crm-metric__value">{{ $trackedHours }}<small> ч</small></div>
        <div class="crm-metric__hint">записей тайм-трекинга: {{ $timeTracking['records'] }}</div>
    </div>
    @if ($canAudit && $auditTrend !== null)
        <div class="crm-metric">
            <div class="crm-metric__label" style="color: #4299e1;"><i class="ti ti-activity"></i>Событий журнала</div>
            <div class="crm-metric__value">{{ $auditTrend['total'] }}</div>
            <div class="crm-metric__hint">за последние 14 дней</div>
        </div>
    @endif
</div>

<div class="row g-3">
    {{-- ===== Тренд посещаемости ===== --}}
    <div class="col-lg-8">
        <div class="card crm-panel h-100">
            <div class="card-header d-flex align-items-start justify-content-between gap-2">
                <div>
                    <div class="card-title mb-0">Посещаемость, последние {{ count($trend) }} дней</div>
                    <div class="crm-panel__sub">столбец — отметки за день · наведите для деталей</div>
                </div>
                <span class="crm-chip crm-chip--green">пик {{ $trendMax }}</span>
            </div>
            <div class="card-body d-flex flex-column">
                <div class="crm-bars flex-grow-1" style="min-height: 168px;">
                    @foreach ($trend as $row)
                        @php
                            $dayTotal = $row['present'] + $row['late'] + $row['remote'];
                        @endphp
                        <div class="crm-bar {{ $row['date']->isWeekend() ? 'crm-bar--dim' : '' }}">
                            <div class="crm-bar__col"
                                 style="--h: {{ max(6, (int) round(100 * $dayTotal / $trendMax)) }}%"
                                 data-tip="{{ $row['date']->format('d.m') }} · на месте {{ $row['present'] }} · опоздали {{ $row['late'] }} · удалённо {{ $row['remote'] }}"></div>
                            <div class="crm-bar__label">{{ $row['date']->format('d.m') }}</div>
                        </div>
                    @endforeach
                </div>
                <div class="crm-legend mt-3">
                    <span><i style="--c: #2fb344"></i>на месте {{ $sumPresent }}</span>
                    <span><i style="--c: #f76707"></i>опоздали {{ $sumLate }}</span>
                    <span><i style="--c: #4299e1"></i>удалённо {{ $sumRemote }}</span>
                    <span><i style="--c: rgba(var(--crm-accent-rgb), .3)"></i>выходные</span>
                </div>
            </div>
        </div>
    </div>

    {{-- ===== Задачи и документы ===== --}}
    <div class="col-lg-4">
        <div class="card crm-panel mb-3">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">Задачи по статусам</div>
                    <div class="crm-panel__sub">всего {{ $taskTotal }} в области доступа</div>
                </div>
            </div>
            <div class="card-body">
                @if ($taskTotal > 0)
                    <div class="crm-stack" role="img" aria-label="Распределение задач по статусам">
                        @foreach ($taskMeta as $key => $meta)
                            @if (($tasks[$key] ?? 0) > 0)
                                <span class="crm-stack__seg" style="--w: {{ (int) round(100 * $tasks[$key] / max($taskTotal, 1)) }}%; --c: {{ $meta[1] }}" title="{{ $meta[0] }}: {{ $tasks[$key] }}"></span>
                            @endif
                        @endforeach
                    </div>
                    <div class="mt-3">
                        @foreach ($taskMeta as $key => $meta)
                            @if (($tasks[$key] ?? 0) > 0)
                                <div class="d-flex align-items-center justify-content-between py-1">
                                    <span class="d-inline-flex align-items-center gap-2 small">
                                        <i style="width: 8px; height: 8px; border-radius: 2px; background: {{ $meta[1] }}; display: inline-block;"></i>
                                        {{ $meta[0] }}
                                    </span>
                                    <strong class="crm-table-num">{{ $tasks[$key] }}</strong>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @else
                    @include('partials.empty-state', ['icon' => 'list-check', 'title' => 'Задач нет', 'text' => 'В вашей области доступа нет задач по назначенным сотрудникам.'])
                @endif
            </div>
        </div>

        <div class="card crm-panel">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">Документы по статусам</div>
                    <div class="crm-panel__sub">всего {{ $docTotal }} · автор или подписант</div>
                </div>
            </div>
            <div class="card-body">
                @if ($docTotal > 0)
                    <div class="crm-stack" role="img" aria-label="Распределение документов по статусам">
                        @foreach ($docMeta as $key => $meta)
                            @if (($documents[$key] ?? 0) > 0)
                                <span class="crm-stack__seg" style="--w: {{ (int) round(100 * $documents[$key] / max($docTotal, 1)) }}%; --c: {{ $meta[1] }}" title="{{ $meta[0] }}: {{ $documents[$key] }}"></span>
                            @endif
                        @endforeach
                    </div>
                    <div class="mt-3">
                        @foreach ($docMeta as $key => $meta)
                            @if (($documents[$key] ?? 0) > 0)
                                <div class="d-flex align-items-center justify-content-between py-1">
                                    <span class="d-inline-flex align-items-center gap-2 small">
                                        <i style="width: 8px; height: 8px; border-radius: 2px; background: {{ $meta[1] }}; display: inline-block;"></i>
                                        {{ $meta[0] }}
                                    </span>
                                    <strong class="crm-table-num">{{ $documents[$key] }}</strong>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @else
                    @include('partials.empty-state', ['icon' => 'file-text', 'title' => 'Документов нет', 'text' => 'Документы появятся здесь, когда вы станете автором или подписантам.'])
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ===== Виджеты: приоритеты, сроки, исполнители ===== --}}
<div class="row g-3 mt-3">
    {{-- Задачи по приоритетам --}}
    <div class="col-lg-4">
        <div class="card crm-panel h-100">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">Приоритеты задач</div>
                    <div class="crm-panel__sub">всего {{ $prioTotal }} в области доступа</div>
                </div>
            </div>
            <div class="card-body">
                @if ($prioTotal > 0)
                    <div class="crm-stack" role="img" aria-label="Распределение задач по приоритетам">
                        @foreach ($prioMeta as $key => $meta)
                            @if (($priorities[$key] ?? 0) > 0)
                                <span class="crm-stack__seg" style="--w: {{ (int) round(100 * $priorities[$key] / max($prioTotal, 1)) }}%; --c: {{ $meta[1] }}" title="{{ $meta[0] }}: {{ $priorities[$key] }}"></span>
                            @endif
                        @endforeach
                    </div>
                    <div class="mt-3">
                        @foreach ($prioMeta as $key => $meta)
                            <div class="d-flex align-items-center justify-content-between py-1">
                                <span class="d-inline-flex align-items-center gap-2 small">
                                    <i style="width: 8px; height: 8px; border-radius: 2px; background: {{ $meta[1] }}; display: inline-block;"></i>
                                    {{ $meta[0] }}
                                </span>
                                <strong class="crm-table-num">{{ $priorities[$key] ?? 0 }}</strong>
                            </div>
                        @endforeach
                    </div>
                @else
                    @include('partials.empty-state', ['icon' => 'flag', 'title' => 'Задач нет', 'text' => 'В вашей области доступа нет задач по назначенным сотрудникам.'])
                @endif
            </div>
        </div>
    </div>

    {{-- Сроки открытых задач --}}
    <div class="col-lg-4">
        <div class="card crm-panel h-100">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">Сроки задач</div>
                    <div class="crm-panel__sub">открытые задачи по дате дедлайна</div>
                </div>
                @if ($deadlines['overdue'] > 0)
                    <span class="crm-chip crm-chip--red">{{ $deadlines['overdue'] }} просрочено</span>
                @endif
            </div>
            <div class="card-body">
                @php
                    $deadlineRows = [
                        ['Срок прошёл', $deadlines['overdue'], '#d63939'],
                        ['Ближайшие 7 дней', $deadlines['week'], '#f76707'],
                        ['Позже', $deadlines['later'], '#2fb344'],
                        ['Без срока', $deadlines['none'], '#6c7a91'],
                    ];
                    $deadlineSum = array_sum($deadlines);
                @endphp
                @if ($deadlineSum > 0)
                    @foreach ($deadlineRows as [$label, $count, $color])
                        <div class="mb-3">
                            <div class="d-flex align-items-center justify-content-between small mb-1">
                                <span class="d-inline-flex align-items-center gap-2">
                                    <i style="width: 8px; height: 8px; border-radius: 2px; background: {{ $color }}; display: inline-block;"></i>
                                    {{ $label }}
                                </span>
                                <strong class="crm-table-num">{{ $count }}</strong>
                            </div>
                            <div class="crm-kpi__bar" style="--w: {{ (int) round(100 * $count / $deadlineMax) }}%"><i style="background: {{ $color }}"></i></div>
                        </div>
                    @endforeach
                @else
                    @include('partials.empty-state', ['icon' => 'calendar-off', 'title' => 'Открытых задач нет', 'text' => 'Просроченные задачи и горизонт дедлайнов появятся, когда задачи будут в работе.'])
                @endif
            </div>
        </div>
    </div>

    {{-- Загрузка исполнителей --}}
    <div class="col-lg-4">
        <div class="card crm-panel h-100">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">Загрузка исполнителей</div>
                    <div class="crm-panel__sub">открытых задач на сотрудника</div>
                </div>
            </div>
            <div class="card-body">
                @if (count($assignees) > 0)
                    @foreach ($assignees as $person)
                        <div class="d-flex align-items-center gap-3 py-1">
                            <div class="flex-grow-1 min-w-0">
                                <div class="small text-truncate">{{ $person['name'] }}</div>
                                <div class="crm-kpi__bar mt-1" title="{{ $person['count'] }} открытых задач">
                                    <i style="--w: {{ max(4, (int) round(100 * $person['count'] / $assigneeMax)) }}%"></i>
                                </div>
                            </div>
                            <strong class="crm-table-num">{{ $person['count'] }}</strong>
                        </div>
                    @endforeach
                @else
                    @include('partials.empty-state', ['icon' => 'users', 'title' => 'Нет открытых задач', 'text' => 'Все задачи в области доступа закрыты или ещё не назначены.'])
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ===== Журнал действий (виджеты только для владельцев audit.view) ===== --}}
@if ($canAudit && $auditTrend !== null)
    <div class="row g-3 mt-3">
        <div class="col-lg-8">
            <div class="card crm-panel h-100">
                <div class="card-header d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <div class="card-title mb-0">Активность журнала, 14 дней</div>
                        <div class="crm-panel__sub">события действий пользователей · наведите для деталей</div>
                    </div>
                    <span class="crm-chip crm-chip--cyan">{{ $auditTrend['total'] }} событий</span>
                </div>
                <div class="card-body d-flex flex-column">
                    <div class="crm-bars flex-grow-1" style="min-height: 168px;">
                        @foreach ($auditTrend['days'] as $day)
                            <div class="crm-bar {{ $day['count'] === 0 ? 'crm-bar--dim' : '' }}">
                                <div class="crm-bar__col"
                                     style="--h: {{ max(4, (int) round(100 * $day['count'] / $auditMax)) }}%"
                                     data-tip="{{ $day['date']->format('d.m') }} · {{ $day['count'] }} событий"></div>
                                <div class="crm-bar__label">{{ $day['date']->format('d.m') }}</div>
                            </div>
                        @endforeach
                    </div>
                    <div class="crm-legend mt-3">
                        <span><i style="--c: rgba(var(--crm-accent-rgb), .8)"></i>события журнала</span>
                        <span><i style="--c: rgba(var(--crm-accent-rgb), .3)"></i>дней без событий</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card crm-panel h-100">
                <div class="card-header">
                    <div>
                        <div class="card-title mb-0">Частые действия</div>
                        <div class="crm-panel__sub">топ за 14 дней</div>
                    </div>
                    <div class="ms-auto"><a class="btn btn-sm btn-ghost-primary" href="{{ route('audit.index') }}">Журнал<i class="ti ti-arrow-right ms-1"></i></a></div>
                </div>
                <div class="card-body">
                    @if (count($auditActions) > 0)
                        @foreach ($auditActions as $item)
                            <div class="d-flex align-items-center gap-3 py-1">
                                <div class="flex-grow-1 min-w-0">
                                    <div class="small text-truncate font-monospace" title="{{ $item['action'] }}">{{ $item['action'] }}</div>
                                    <div class="crm-kpi__bar mt-1">
                                        <i style="--w: {{ max(4, (int) round(100 * $item['count'] / $auditActionMax)) }}%"></i>
                                    </div>
                                </div>
                                <strong class="crm-table-num">{{ $item['count'] }}</strong>
                            </div>
                        @endforeach
                    @else
                        @include('partials.empty-state', ['icon' => 'history', 'title' => 'Событий нет', 'text' => 'За последние 14 дней журнал действий пуст.'])
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif

{{-- ===== Виджеты: пунктуальность, стаж, отпуска ===== --}}
<div class="row g-3 mt-3">
    {{-- Пунктуальность за 14 дней --}}
    <div class="col-lg-4">
        <div class="card crm-panel h-100">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">Пунктуальность, 14 дней</div>
                    <div class="crm-panel__sub">доля отметок без опоздания</div>
                </div>
            </div>
            <div class="card-body">
                @if ($marksTotal > 0)
                    @php
                        $onTimePct = (int) round(100 * ($marksTotal - $sumLate) / $marksTotal);
                    @endphp
                    <div class="crm-stat mb-3">
                        <span class="crm-stat__dot" style="--c:#2fb344"></span>
                        <div>
                            <div class="crm-stat__val">{{ $onTimePct }}%</div>
                            <div class="crm-stat__label">пришли вовремя</div>
                        </div>
                    </div>
                    <div class="crm-stack" role="img" aria-label="Структура отметок за 14 дней">
                        <span class="crm-stack__seg" style="--w: {{ (int) round(100 * $sumPresent / $marksTotal) }}%; --c: #2fb344" title="на месте: {{ $sumPresent }}"></span>
                        <span class="crm-stack__seg" style="--w: {{ (int) round(100 * $sumLate / $marksTotal) }}%; --c: #f76707" title="опоздали: {{ $sumLate }}"></span>
                        <span class="crm-stack__seg" style="--w: {{ (int) round(100 * $sumRemote / $marksTotal) }}%; --c: #4299e1" title="удалённо: {{ $sumRemote }}"></span>
                    </div>
                    <div class="crm-legend mt-3">
                        <span><i style="--c: #2fb344"></i>на месте {{ $sumPresent }}</span>
                        <span><i style="--c: #f76707"></i>опоздали {{ $sumLate }}</span>
                        <span><i style="--c: #4299e1"></i>удалённо {{ $sumRemote }}</span>
                    </div>
                @else
                    @include('partials.empty-state', ['icon' => 'clock-off', 'title' => 'Отметок нет', 'text' => 'За последние 14 дней в области доступа нет записей посещаемости.'])
                @endif
            </div>
        </div>
    </div>

    {{-- Стаж сотрудников --}}
    <div class="col-lg-4">
        <div class="card crm-panel h-100">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">Стаж сотрудников</div>
                    <div class="crm-panel__sub">по дате приёма · всего {{ $headcount['total'] }}</div>
                </div>
            </div>
            <div class="card-body">
                @foreach ($tenure as $label => $count)
                    <div class="mb-3">
                        <div class="d-flex align-items-center justify-content-between small mb-1">
                            <span>{{ $label }}</span>
                            <strong class="crm-table-num">{{ $count }}</strong>
                        </div>
                        <div class="crm-kpi__bar" style="--w: {{ $count > 0 ? max(4, (int) round(100 * $count / $tenureMax)) : 0 }}%"><i></i></div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Отпуска по статусам --}}
    <div class="col-lg-4">
        <div class="card crm-panel h-100">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">Заявки на отпуск</div>
                    <div class="crm-panel__sub">всего {{ $leaveTotal }} в области доступа</div>
                </div>
            </div>
            <div class="card-body">
                @if ($leaveTotal > 0)
                    <div class="crm-stack" role="img" aria-label="Заявки на отпуск по статусам">
                        @foreach ($leaveMeta as $key => $meta)
                            @if (($leaves[$key] ?? 0) > 0)
                                <span class="crm-stack__seg" style="--w: {{ (int) round(100 * $leaves[$key] / max($leaveTotal, 1)) }}%; --c: {{ $meta[1] }}" title="{{ $meta[0] }}: {{ $leaves[$key] }}"></span>
                            @endif
                        @endforeach
                    </div>
                    <div class="mt-3">
                        @foreach ($leaveMeta as $key => $meta)
                            <div class="d-flex align-items-center justify-content-between py-1">
                                <span class="d-inline-flex align-items-center gap-2 small">
                                    <i style="width: 8px; height: 8px; border-radius: 2px; background: {{ $meta[1] }}; display: inline-block;"></i>
                                    {{ $meta[0] }}
                                </span>
                                <strong class="crm-table-num">{{ $leaves[$key] ?? 0 }}</strong>
                            </div>
                        @endforeach
                    </div>
                @else
                    @include('partials.empty-state', ['icon' => 'plane-departure', 'title' => 'Заявок нет', 'text' => 'Заявки на отпуск появятся здесь, когда сотрудники начнут их оформлять.'])
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ===== Численность ===== --}}
<div class="card crm-panel mt-3">
    <div class="card-header d-flex align-items-start justify-content-between gap-2">
        <div>
            <div class="card-title mb-0">Численность по подразделениям</div>
            <div class="crm-panel__sub">активные сотрудники в области доступа · всего {{ $headcount['total'] }}</div>
        </div>
        <span class="crm-chip crm-chip--cyan">{{ count($departments) }} подразделений</span>
    </div>
    <div class="card-body">
        @if (count($departments) > 0)
            <div class="row g-3">
                @foreach ($departments as $row)
                    <div class="col-sm-6 col-xl-4">
                        <div class="crm-entity">
                            <div class="crm-entity__head">
                                <span class="crm-entity__logo">{{ mb_strtoupper(mb_substr($row['name'], 0, 2)) }}</span>
                                <div class="min-w-0">
                                    <div class="crm-entity__name">{{ $row['name'] }}</div>
                                    <div class="crm-entity__sub">Руководитель: {{ $row['director'] ?? '—' }}</div>
                                </div>
                            </div>
                            <div class="crm-entity__meta">
                                <span><i class="ti ti-users me-1"></i>{{ $row['headcount'] }} чел.</span>
                                <span>{{ (int) round(100 * $row['headcount'] / max($headcount['total'], 1)) }}% от scope</span>
                            </div>
                            <div class="crm-entity__footer">
                                <div class="crm-kpi__bar flex-grow-1" style="--w: {{ max(4, (int) round(100 * $row['headcount'] / $maxHeadcount)) }}%"><i></i></div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            @include('partials.empty-state', ['icon' => 'building', 'title' => 'Подразделений нет', 'text' => 'Создайте структуру компании, чтобы видеть численность по отделам.'])
        @endif
    </div>
</div>

{{-- ===== Графики Chart.js: line / bar / doughnut / pie ===== --}}
<div class="row g-3 mt-3" id="crm-js-charts">
    <div class="col-lg-6">
        <div class="card crm-panel h-100">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">Динамика посещаемости</div>
                    <div class="crm-panel__sub">линейный график · последние {{ count($trend) }} дней</div>
                </div>
                <span class="crm-chip crm-chip--green"><i class="ti ti-chart-line me-1"></i>Line</span>
            </div>
            <div class="card-body">
                @if (count($chartTrendLabels) > 0)
                    <div class="crm-chart-box"><canvas id="crmChartLine" aria-label="Линейный график посещаемости"></canvas></div>
                @else
                    @include('partials.empty-state', ['icon' => 'chart-line', 'title' => 'Нет данных', 'text' => 'За период нет отметок посещаемости.'])
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card crm-panel h-100">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">Задачи по статусам</div>
                    <div class="crm-panel__sub">столбчатая диаграмма · всего {{ $taskTotal }}</div>
                </div>
                <span class="crm-chip crm-chip--cyan"><i class="ti ti-chart-bar me-1"></i>Bar</span>
            </div>
            <div class="card-body">
                @if ($taskTotal > 0 && count($chartTasks['data']) > 0)
                    <div class="crm-chart-box"><canvas id="crmChartBar" aria-label="Столбчатая диаграмма задач по статусам"></canvas></div>
                @else
                    @include('partials.empty-state', ['icon' => 'chart-bar', 'title' => 'Задач нет', 'text' => 'В вашей области доступа нет задач по назначенным сотрудникам.'])
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card crm-panel h-100">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">Документы по статусам</div>
                    <div class="crm-panel__sub">кольцевая диаграмма · всего {{ $docTotal }}</div>
                </div>
                <span class="crm-chip crm-chip--orange"><i class="ti ti-chart-donut me-1"></i>Doughnut</span>
            </div>
            <div class="card-body">
                @if ($docTotal > 0 && count($chartDocs['data']) > 0)
                    <div class="crm-chart-box"><canvas id="crmChartDoughnut" aria-label="Кольцевая диаграмма документов по статусам"></canvas></div>
                @else
                    @include('partials.empty-state', ['icon' => 'file-text', 'title' => 'Документов нет', 'text' => 'Документы появятся здесь, когда вы станете автором или подписантам.'])
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card crm-panel h-100">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">Приоритеты задач</div>
                    <div class="crm-panel__sub">круговая диаграмма · всего {{ $prioTotal }}</div>
                </div>
                <span class="crm-chip"><i class="ti ti-chart-pie me-1"></i>Pie</span>
            </div>
            <div class="card-body">
                @if ($prioTotal > 0 && count($chartPrio['data']) > 0)
                    <div class="crm-chart-box"><canvas id="crmChartPie" aria-label="Круговая диаграмма приоритетов задач"></canvas></div>
                @else
                    @include('partials.empty-state', ['icon' => 'flag', 'title' => 'Задач нет', 'text' => 'В вашей области доступа нет задач по назначенным сотрудникам.'])
                @endif
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
    .crm-chart-box { position: relative; width: 100%; height: 280px; }
    @media (max-width: 576px) { .crm-chart-box { height: 220px; } }
</style>
@endpush

@push('scripts')
<script src="/assets/vendor/chartjs/chart.umd.js?v1"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') { return; }

    var root = document.documentElement;
    var isDark = function () { return root.getAttribute('data-bs-theme') === 'dark'; };
    var gridColor = function () { return isDark() ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.07)'; };
    var tickColor = function () { return isDark() ? 'rgba(255,255,255,.62)' : 'rgba(0,0,0,.55)'; };
    var tooltipColor = function () { return isDark() ? '#1c1f26' : '#fff'; };
    var tooltipBg = function () { return isDark() ? '#f2f2f7' : 'rgba(28,31,38,.94)'; };

    Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Inter', 'Segoe UI', system-ui, sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.animation.duration = 600;
    Chart.defaults.plugins.tooltip.backgroundColor = tooltipBg();
    Chart.defaults.plugins.tooltip.titleColor = tooltipColor();
    Chart.defaults.plugins.tooltip.bodyColor = tooltipColor();
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 8;
    Chart.defaults.plugins.tooltip.displayColors = true;
    Chart.defaults.plugins.tooltip.boxPadding = 4;

    var charts = [];

    function baseCartesian(extra) {
        var opts = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: tickColor(), usePointStyle: true, pointStyleWidth: 8, padding: 14 }
                }
            },
            scales: {
                x: { grid: { color: gridColor(), drawBorder: false }, ticks: { color: tickColor(), maxRotation: 0, autoSkipPadding: 8 } },
                y: { beginAtZero: true, grid: { color: gridColor(), drawBorder: false }, ticks: { color: tickColor(), precision: 0, padding: 6 } }
            }
        };
        if (extra) { extra(opts); }
        return opts;
    }

    /* ---- Line: динамика посещаемости ---- */
    var lineEl = document.getElementById('crmChartLine');
    if (lineEl) {
        charts.push(new Chart(lineEl, {
            type: 'line',
            data: {
                labels: @json($chartTrendLabels),
                datasets: [
                    { label: 'На месте', data: @json($chartPresent), borderColor: '#2fb344', backgroundColor: 'rgba(47,179,68,.14)', fill: true, tension: .35, borderWidth: 2, pointRadius: 3, pointHoverRadius: 5 },
                    { label: 'Опоздали', data: @json($chartLate), borderColor: '#f76707', backgroundColor: 'rgba(247,103,7,.12)', fill: true, tension: .35, borderWidth: 2, pointRadius: 3, pointHoverRadius: 5 },
                    { label: 'Удалённо', data: @json($chartRemote), borderColor: '#4299e1', backgroundColor: 'rgba(66,153,225,.12)', fill: true, tension: .35, borderWidth: 2, pointRadius: 3, pointHoverRadius: 5 }
                ]
            },
            options: baseCartesian()
        }));
    }

    /* ---- Bar: задачи по статусам ---- */
    var barEl = document.getElementById('crmChartBar');
    if (barEl) {
        charts.push(new Chart(barEl, {
            type: 'bar',
            data: {
                labels: @json($chartTasks['labels']),
                datasets: [{
                    label: 'Задач',
                    data: @json($chartTasks['data']),
                    backgroundColor: @json($chartTasks['colors']),
                    borderRadius: 6,
                    borderSkipped: false,
                    maxBarThickness: 34
                }]
            },
            options: baseCartesian(function (o) {
                o.plugins.legend.display = false;
                o.scales.x.ticks.maxRotation = 45;
                o.scales.x.ticks.minRotation = 0;
            })
        }));
    }

    /* ---- Doughnut: документы по статусам ---- */
    var doughnutEl = document.getElementById('crmChartDoughnut');
    if (doughnutEl) {
        charts.push(new Chart(doughnutEl, {
            type: 'doughnut',
            data: {
                labels: @json($chartDocs['labels']),
                datasets: [{
                    data: @json($chartDocs['data']),
                    backgroundColor: @json($chartDocs['colors']),
                    borderColor: 'transparent',
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: tickColor(), usePointStyle: true, pointStyleWidth: 8, padding: 12 }
                    }
                }
            }
        }));
    }

    /* ---- Pie: приоритеты задач ---- */
    var pieEl = document.getElementById('crmChartPie');
    if (pieEl) {
        charts.push(new Chart(pieEl, {
            type: 'pie',
            data: {
                labels: @json($chartPrio['labels']),
                datasets: [{
                    data: @json($chartPrio['data']),
                    backgroundColor: @json($chartPrio['colors']),
                    borderColor: 'transparent',
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: tickColor(), usePointStyle: true, pointStyleWidth: 8, padding: 12 }
                    }
                }
            }
        }));
    }

    /* ---- Смена темы: перекрашиваем оси и легенду ---- */
    var themeTimer = null;
    var observer = new MutationObserver(function () {
        if (themeTimer) { clearTimeout(themeTimer); }
        themeTimer = setTimeout(function () {
            charts.forEach(function (chart) {
                var opts = chart.options;
                if (opts.scales) {
                    Object.keys(opts.scales).forEach(function (key) {
                        if (opts.scales[key].grid) { opts.scales[key].grid.color = gridColor(); }
                        if (opts.scales[key].ticks) { opts.scales[key].ticks.color = tickColor(); }
                    });
                }
                if (opts.plugins && opts.plugins.legend && opts.plugins.legend.labels) {
                    opts.plugins.legend.labels.color = tickColor();
                }
                chart.update('none');
            });
        }, 60);
    });
    observer.observe(root, { attributes: true, attributeFilter: ['data-bs-theme'] });
})();
</script>
@endpush
@endsection
