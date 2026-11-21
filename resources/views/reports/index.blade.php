@extends('layouts.tabler')
@section('workspace_layout', 'overview')

@section('title', __('Отчёты'))

@php
    $from = request('from') ?: \Carbon\Carbon::today()->firstOfMonth()->toDateString();
    $to = request('to') ?: \Carbon\Carbon::today()->toDateString();
    $period = http_build_query(['from' => $from, 'to' => $to]);
    $scopeName = ['company' => __('по компании'), 'department' => __('по вашему подразделению'), 'own' => __('по вашим данным')][$scope] ?? __('по доступным данным');

    /* --- сигналы: что реально требует внимания (ссылка = готовое действие) --- */
    $lateToday = (int) $attendanceToday['late'] + (int) $attendanceToday['absent_from_schedule'];
    $alerts = [];
    if ((int) $taskDeadlines['overdue'] > 0) {
        $alerts[] = [
            'tone' => 'red', 'icon' => 'alarm',
            'title' => (int) $taskDeadlines['overdue'] . ' ' . \Illuminate\Support\Str::plural(__('задача'), (int) $taskDeadlines['overdue']) . ' просрочено',
            'text' => __('Откройте отчёт по задачам и перенесите сроки или закройте их.'),
            'url' => route('reports.tasks') . '?' . $period,
            'action' => __('Открыть задачи'),
        ];
    }
    if ((int) $taskDeadlines['week'] > 0) {
        $alerts[] = [
            'tone' => 'orange', 'icon' => 'calendar-event',
            'title' => (int) $taskDeadlines['week'] . ' дедлайн(ов) в ближайшие 7 дней',
            'text' => __('Посмотрите, кто загружен, чтобы успеть в срок.'),
            'url' => route('reports.tasks') . '?' . $period,
            'action' => __('Посмотреть'),
        ];
    }
    if ($lateToday > 0) {
        $alerts[] = [
            'tone' => 'orange', 'icon' => 'clock-exclamation',
            'title' => $lateToday . ' опозданий или отметок нет сегодня',
            'text' => __('Проверьте табель и напомните команде.'),
            'url' => route('reports.attendance') . '?' . $period,
            'action' => __('Открыть табель'),
        ];
    }
    if ($alerts === []) {
        $alerts[] = [
            'tone' => 'green', 'icon' => 'circle-check',
            'title' => __('Всё под контролем'),
            'text' => __('Просроченных задач и проблем посещаемости сегодня нет.'),
            'url' => route('reports.tasks') . '?' . $period,
            'action' => __('К отчётам'),
        ];
    }

    /* --- визуал: полоса посещаемости за сегодня --- */
    $attTotal = max(1, (int) $attendanceToday['total']);
    $attParts = [
        ['label' => __('На месте'), 'cnt' => (int) $attendanceToday['present'], 'color' => 'var(--crm-success)'],
        ['label' => __('Опоздали'), 'cnt' => (int) $attendanceToday['late'], 'color' => 'var(--crm-warning)'],
        ['label' => __('Удалённо'), 'cnt' => (int) $attendanceToday['remote'], 'color' => 'var(--crm-info)'],
        ['label' => __('Нет отметки'), 'cnt' => (int) $attendanceToday['absent_from_schedule'], 'color' => 'var(--crm-danger)'],
    ];

    /* --- визуал: кольцо выполнения задач --- */
    $taskPct = max(0, min(100, (int) $taskCompletion['pct']));
    $activePct = $headcount['total'] > 0 ? (int) round($headcount['active'] / $headcount['total'] * 100) : 0;
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Аналитика') }} {{ $scopeName }}</div>
            <h1 class="page-title mt-1">{{ __('Отчёты') }}</h1>
            <div class="text-secondary mt-1">{{ __('Сверху — что требует внимания, ниже — три отчёта с готовыми действиями') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <form method="get" class="d-flex align-items-end gap-2 flex-wrap m-0">
                <div><label class="form-label mb-1" for="rep-from">{{ __('С') }}</label><input type="date" id="rep-from" name="from" value="{{ $from }}" class="form-control"></div>
                <div><label class="form-label mb-1" for="rep-to">{{ __('По') }}</label><input type="date" id="rep-to" name="to" value="{{ $to }}" class="form-control"></div>
                <button class="btn btn-outline-primary"><i class="ti ti-refresh me-1"></i>{{ __('Показать') }}</button>
            </form>
        </div>
    </div>
</div>

{{-- ===== 1. Сигналы: что сделать прямо сейчас ===== --}}
<div class="crm-alerts mb-3">
    @foreach ($alerts as $alert)
        <a class="crm-alert crm-alert--{{ $alert['tone'] }}" href="{{ $alert['url'] }}">
            <span class="crm-alert__icon"><i class="ti ti-{{ $alert['icon'] }}"></i></span>
            <span class="crm-alert__body">
                <strong class="crm-alert__title">{{ $alert['title'] }}</strong>
                <span class="crm-alert__text">{{ $alert['text'] }}</span>
            </span>
            <span class="crm-alert__action">{{ $alert['action'] }} <i class="ti ti-arrow-right"></i></span>
        </a>
    @endforeach
</div>

{{-- ===== 2. Три отчёта: цифра + картинка, а не абзац текста ===== --}}
<div class="row g-3 mb-3">
    {{-- Команда --}}
    <div class="col-lg-4">
        <div class="card h-100"><div class="card-body d-flex flex-column">
            <div class="d-flex align-items-start justify-content-between gap-2">
                <div class="text-secondary small">{{ __('Состав команды') }}</div>
                <span class="avatar avatar-sm bg-blue-lt"><i class="ti ti-users"></i></span>
            </div>
            <div class="d-flex align-items-center gap-3 mt-2">
                <div class="crm-ring" style="--p: {{ $activePct }}; --c: var(--crm-accent);" role="img" aria-label="{{ __('Активных') }} {{ $activePct }}%">
                    <div class="crm-ring__hole">
                        <strong class="crm-ring__value">{{ $activePct }}%</strong>
                        <span class="crm-ring__label">{{ __('активных') }}</span>
                    </div>
                </div>
                <div>
                    <div class="h2 mb-0">{{ $headcount['active'] }}<small class="text-secondary"> / {{ $headcount['total'] }}</small></div>
                    <div class="text-secondary small">{{ __('активных из всех в scope') }}</div>
                </div>
            </div>
            <div class="d-flex gap-2 mt-auto pt-3">
                <a class="btn btn-primary flex-fill justify-content-center" href="{{ route('reports.hr') . '?' . $period }}">{{ __('Открыть отчёт') }}</a>
                <a class="btn btn-ghost-secondary" href="{{ route('reports.export', 'hr') . '?' . $period }}" title="{{ __("Скачать CSV") }}"><i class="ti ti-download"></i></a>
            </div>
        </div></div>
    </div>

    {{-- Посещаемость --}}
    <div class="col-lg-4">
        <div class="card h-100"><div class="card-body d-flex flex-column">
            <div class="d-flex align-items-start justify-content-between gap-2">
                <div class="text-secondary small">{{ __('Посещаемость сегодня') }}</div>
                <span class="avatar avatar-sm {{ $lateToday > 0 ? 'bg-orange-lt text-orange' : 'bg-green-lt text-green' }}"><i class="ti ti-clock-exclamation"></i></span>
            </div>
            <div class="h2 mb-2 mt-2">{{ $lateToday }}<small class="text-secondary"> {{ __('проблем') }}</small></div>

            <div class="crm-stack" role="img" aria-label="{{ __("Распределение посещаемости за сегодня") }}">
                @foreach ($attParts as $part)
                    @if ($part['cnt'] > 0)
                        <i style="--w: {{ max(2, (int) round($part['cnt'] / $attTotal * 100)) }}%; --c: {{ $part['color'] }};" title="{{ $part['label'] }}: {{ $part['cnt'] }}"></i>
                    @endif
                @endforeach
            </div>
            <div class="crm-stack__legend">
                @foreach ($attParts as $part)
                    <span><i style="--c: {{ $part['color'] }}"></i>{{ $part['label'] }} <strong class="crm-table-num">{{ $part['cnt'] }}</strong></span>
                @endforeach
            </div>

            <div class="d-flex gap-2 mt-auto pt-3">
                <a class="btn btn-primary flex-fill justify-content-center" href="{{ route('reports.attendance') . '?' . $period }}">{{ __('Открыть табель') }}</a>
                <a class="btn btn-ghost-secondary" href="{{ route('reports.export', 'attendance') . '?' . $period }}" title="{{ __("Скачать CSV") }}"><i class="ti ti-download"></i></a>
            </div>
        </div></div>
    </div>

    {{-- Задачи --}}
    <div class="col-lg-4">
        <div class="card h-100"><div class="card-body d-flex flex-column">
            <div class="d-flex align-items-start justify-content-between gap-2">
                <div class="text-secondary small">{{ __('Выполнение задач') }}</div>
                <span class="avatar avatar-sm {{ (int) $taskDeadlines['overdue'] > 0 ? 'bg-red-lt text-red' : 'bg-green-lt text-green' }}"><i class="ti ti-list-check"></i></span>
            </div>
            <div class="d-flex align-items-center gap-3 mt-2">
                <div class="crm-ring" style="--p: {{ $taskPct }}; --c: var(--crm-success);" role="img" aria-label="{{ __('Выполнено') }} {{ $taskPct }}%">
                    <div class="crm-ring__hole">
                        <strong class="crm-ring__value">{{ $taskPct }}%</strong>
                        <span class="crm-ring__label">{{ __('выполнено') }}</span>
                    </div>
                </div>
                <div class="d-flex flex-column gap-1">
                    <span class="crm-chip {{ (int) $taskDeadlines['overdue'] > 0 ? 'crm-chip--red' : 'crm-chip--green' }}">
                        <i class="ti ti-alarm me-1"></i>{{ $taskDeadlines['overdue'] }} {{ __('просрочено') }}
                    </span>
                    <span class="crm-chip crm-chip--cyan"><i class="ti ti-calendar me-1"></i>{{ $taskDeadlines['week'] }} {{ __('на неделе') }}</span>
                    <span class="crm-chip crm-chip--grey"><i class="ti ti-folder me-1"></i>{{ $taskCompletion['active'] }} {{ __('открыто') }}</span>
                </div>
            </div>
            <div class="d-flex gap-2 mt-auto pt-3">
                <a class="btn btn-primary flex-fill justify-content-center" href="{{ route('reports.tasks') . '?' . $period }}">{{ __('Открыть отчёт') }}</a>
                <a class="btn btn-ghost-secondary" href="{{ route('reports.export', 'tasks') . '?' . $period }}" title="{{ __("Скачать CSV") }}"><i class="ti ti-download"></i></a>
            </div>
        </div></div>
    </div>
</div>

{{-- ===== 3. Срезы: те же отчёты списком с описанием ===== --}}
<div class="card">
    <div class="card-header">
        <div><strong>{{ __('Все отчёты') }}</strong><div class="text-secondary small">{{ __('Один и тот же период') }} {{ \Carbon\Carbon::parse($from)->format('d.m.Y') }} — {{ \Carbon\Carbon::parse($to)->format('d.m.Y') }}</div></div>
    </div>
    <div class="list-group list-group-flush">
        <a class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3" href="{{ route('reports.hr') . '?' . $period }}">
            <span class="avatar bg-blue-lt"><i class="ti ti-users"></i></span>
            <span class="flex-grow-1">
                <span class="fw-semibold d-block">{{ __('Кадровый отчёт') }}</span>
                <span class="text-secondary small">{{ __('Численность по отделам, руководители, доля от компании') }}</span>
            </span>
            <i class="ti ti-chevron-right text-secondary"></i>
        </a>
        <a class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3" href="{{ route('reports.attendance') . '?' . $period }}">
            <span class="avatar bg-azure-lt"><i class="ti ti-calendar-time"></i></span>
            <span class="flex-grow-1">
                <span class="fw-semibold d-block">{{ __('Рабочее время') }}</span>
                <span class="text-secondary small">{{ __('Часы, опоздания и незакрытые смены за период') }}</span>
            </span>
            <i class="ti ti-chevron-right text-secondary"></i>
        </a>
        <a class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3" href="{{ route('reports.tasks') . '?' . $period }}">
            <span class="avatar bg-green-lt"><i class="ti ti-list-check"></i></span>
            <span class="flex-grow-1">
                <span class="fw-semibold d-block">{{ __('Результат по задачам') }}</span>
                <span class="text-secondary small">{{ __('Кто сколько закрыл, где просрочки и перегрузка') }}</span>
            </span>
            <i class="ti ti-chevron-right text-secondary"></i>
        </a>
    </div>
</div>
@endsection
