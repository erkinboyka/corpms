@extends('layouts.tabler')
@section('body_class', 'bi-page')
@section('workspace_layout', 'overview')

@section('title', 'BI — '.$dashboard->name)

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('BI · Дашборд') }}</div>
            <h1 class="page-title mt-1">{{ $dashboard->name }}</h1>
            <div class="text-secondary mt-1 d-flex align-items-center gap-2 flex-wrap">
                <span>{{ __('период') }} {{ $month }}</span>
                @if ($dashboard->is_default)<span class="crm-chip crm-chip--green">{{ __('по умолчанию') }}</span>@endif
                <span class="text-secondary small">{{ __('значения считаются из витрины метрик') }}</span>
            </div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('bi.index', ['month' => $month]) }}"><i class="ti ti-arrow-left me-1"></i>{{ __('К дашбордам') }}</a>
            @can('checkBoxPermission', 'bi.manage')
            <button type="button" class="btn btn-outline-primary me-1" data-bi-generate data-ai-write><i class="ti ti-sparkles me-1"></i>{{ __('Сгенерировать ИИ') }}</button>
            <form method="post" action="{{ route('bi.rebuild') }}" class="d-inline me-1">
                @csrf
                <input type="hidden" name="month" value="{{ $month }}">
                <button class="btn btn-ghost-secondary"><i class="ti ti-refresh me-1"></i>{{ __('Пересобрать') }}</button>
            </form>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newWidget"><i class="ti ti-plus me-1"></i>{{ __('Виджет') }}</button>
            @endcan
        </div>
    </div>
</div>

@if ($dashboard->origin === 'ai' && $dashboard->status === 'generating')
    <span id="bumi-gen-state" data-status-url="{{ route('bi.dashboards.generation-status', $dashboard) }}" hidden></span>
    <div class="visually-hidden" role="status" aria-live="polite" aria-atomic="true" data-bm-progress-live>{{ __('Генерация дашборда запущена') }}</div>
    <div class="bi-gen-veil" data-gen-veil aria-hidden="true">
        <div class="bi-gen-veil__chart bi-skeleton">
            <span class="bi-skeleton__bar"></span><span class="bi-skeleton__bar"></span><span class="bi-skeleton__bar"></span><span class="bi-skeleton__bar"></span><span class="bi-skeleton__bar"></span><span class="bi-skeleton__bar"></span>
            <svg class="bi-skeleton__line" viewBox="0 0 120 34" preserveAspectRatio="none"><polyline points="0,27 18,20 34,23 52,10 70,16 88,6 120,12"/></svg>
        </div>
        <span class="bi-gen-veil__spark">✦</span>
        <span class="visually-hidden" aria-live="polite" data-bm-status>{{ __('Bumi собирает дашборд…') }}</span>
    </div>
@endif

@if ($dashboard->origin === 'ai' && $dashboard->status === 'error' && $dashboard->last_error)
    <div class="alert alert-danger">{{ __('Ошибка генерации') }}: {{ $dashboard->last_error }}</div>
@endif

<form method="get" class="crm-toolbar">
    <input type="month" name="month" class="form-control" value="{{ $month }}" aria-label="{{ __("Месяц") }}" onchange="this.form.submit()">
    <button class="btn btn-ghost-secondary"><i class="ti ti-calendar me-1"></i>{{ __('Показать месяц') }}</button>
</form>

<div class="row g-4 bi-art-grid">
    @forelse ($widgets as $item)
        @php
            $widget = $item['widget'];
            $data = $item['data'];
            $unit = $data['meta']['unit'] ?? 'count';
            $isMoney = $unit === 'money';
            $palette = ['#206bc4', '#7651c7', '#2fb344', '#e8590c'];
            $accent = $palette[$loop->index % count($palette)];
            $valueText = number_format((float) ($data['value'] ?? 0), 0, ',', ' ');
            $isAi = $widget->isAiWidget();
            $artFamily = $isAi
                ? ($widget->widget_family ?: 'chart')
                : ($widget->kind->value === 'kpi' ? 'kpi' : 'trend');
        @endphp
        <div class="col-md-6 col-xl-{{ $isAi && $widget->span >= 6 ? 6 : ($isAi ? 4 : ($widget->kind->value === 'kpi' ? 3 : 6)) }} bi-art-cell"
             data-bm-widget-id="{{ $widget->id }}" style="--art-accent: {{ $accent }}">
            <div class="card bi-art-card bi-art-card--{{ preg_replace('/[^a-z0-9-]/', '', strtolower((string) $artFamily)) }} h-100">
                <div class="bi-art-card__header">
                    <div class="bi-art-card__heading">
                        <div class="bi-art-card__title">{{ $widget->title }}</div>
                    </div>
                    <div class="bi-art-card__tools">
                        @if ($widget->status === 'generating' || $widget->status === 'pending')
                            <span class="crm-chip">{{ __('генерация…') }}</span>
                        @elseif ($widget->status === 'error')
                            <span class="crm-chip crm-chip--red">{{ __('ошибка') }}</span>
                        @endif
                        @can('checkBoxPermission', 'bi.manage')
                        <form method="post" action="{{ route('bi.widgets.destroy', [$dashboard, $widget]) }}"
                              data-confirm="{{ __('Удалить виджет') }} «{{ $widget->title }}»?" data-confirm-danger="1" data-confirm-ok="{{ __('Удалить') }}">
                            @csrf
                            @method('delete')
                            <button class="btn btn-sm btn-ghost-danger bi-art-card__remove" title="{{ __("Удалить") }}"><i class="ti ti-trash"></i></button>
                        </form>
                        @endcan
                    </div>
                </div>
                <div class="bi-art-card__body">
                    @if ($isAi)
                        @if ($widget->status === 'generating' || $widget->status === 'pending')
                            <div class="bi-art-loading bi-skeleton" role="status" aria-label="{{ __('Bumi рисует график') }}">
                                <span class="bi-skeleton__bar"></span><span class="bi-skeleton__bar"></span><span class="bi-skeleton__bar"></span><span class="bi-skeleton__bar"></span><span class="bi-skeleton__bar"></span>
                                <svg class="bi-skeleton__line" viewBox="0 0 120 34" preserveAspectRatio="none" aria-hidden="true"><polyline points="0,27 18,20 34,23 52,10 70,16 88,6 120,12"/></svg>
                            </div>
                        @elseif (!empty($data['last_error']))
                            <div class="bi-art-error">{{ $data['last_error'] }}</div>
                        @else
                            <div class="bi-widget" id="bi-widget-{{ $widget->id }}"
                                 data-spec="{{ json_encode(['family' => $widget->widget_family, 'type' => $widget->widget_type, 'content' => $data['content'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"></div>
                        @endif
                    @elseif ($widget->kind->value === 'kpi')
                        <div class="bi-kpi">
                            <div class="bi-kpi__value">{{ $valueText }}</div>
                            <div class="bi-kpi__meta">
                                <span class="bi-kpi__unit">{{ $isMoney ? __('деньги') : __('штуки') }}</span>
                                <span>{{ $data['meta']['label'] ?? '' }}</span>
                            </div>
                            <div class="bi-kpi__bars" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></div>
                        </div>
                    @else
                        @if (($data['points'] ?? '') !== '')
                            <svg class="bi-trend__chart" viewBox="0 0 240 48" preserveAspectRatio="none" role="img" aria-label="{{ __('Тренд') }}: {{ $widget->title }}">
                                <defs>
                                    <linearGradient id="bi-trend-{{ $widget->id }}" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="{{ $accent }}" stop-opacity=".34"/>
                                        <stop offset="100%" stop-color="{{ $accent }}" stop-opacity="0"/>
                                    </linearGradient>
                                </defs>
                                <polygon class="bi-trend__area" points="0,48 {{ $data['points'] }} 240,48" fill="url(#bi-trend-{{ $widget->id }})"></polygon>
                                <polyline class="bi-trend__line" points="{{ $data['points'] }}"></polyline>
                            </svg>
                        @else
                            <div class="bi-art-error">{{ __('Недостаточно данных за период — пересоберите витрину') }}</div>
                        @endif
                        <div class="bi-trend__footer">
                            <span>{{ __('Сумма') }} <strong>{{ number_format((float) ($data['summary']['sum'] ?? 0), 0, ',', ' ') }}</strong></span>
                            <span>{{ __('В среднем за день') }} <strong>{{ number_format((float) ($data['summary']['avg'] ?? 0), 0, ',', ' ') }}</strong></span>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            @include('partials.empty-state', ['icon' => 'layout-dashboard', 'title' => __('Виджетов нет'), 'text' => __('Добавьте KPI или тренд по метрике витрины — значения появятся после пересборки.')])
        </div>
    @endforelse
</div>

@if ($forecasts !== [])
    @php $firstForecast = reset($forecasts); @endphp
    <div class="card bi-art-forecast mt-3">
        <div class="card-header">
            <div class="card-title">{{ __('Прогноз на 3 месяца') }}</div>
            <div class="card-actions text-secondary small">{{ __('линейный тренд по последним месяцам витрины') }}</div>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>{{ __('Метрика') }}</th>
                        @foreach ($firstForecast as $point)
                            <th class="text-end">{{ $point['month'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($forecasts as $code => $points)
                        <tr>
                            <td>{{ $catalog[$code]['label'] ?? $code }}</td>
                            @foreach ($points as $point)
                                <td class="text-end">{{ number_format((float) $point['value'], 0, ',', ' ') }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@can('checkBoxPermission', 'bi.manage')
<div class="modal modal-blur fade" id="newWidget" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('bi.widgets.store', $dashboard) }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Новый виджет') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Виджет') }}</h3>
                    <div class="crm-form__desc">{{ __('KPI — итог за период, тренд — дневная динамика и спарклайн') }}</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="bw-title">{{ __('Название') }}<span class="req">*</span></label>
                        <input type="text" id="bw-title" name="title" class="form-control" value="{{ old('title') }}" placeholder="{{ __("Доходы за период") }}" required>
                    </div>
                    <div class="crm-form__row mb-0">
                        <div class="crm-form__field">
                            <label class="form-label" for="bw-kind">{{ __('Тип') }}<span class="req">*</span></label>
                            <select id="bw-kind" name="kind" class="form-select" required>
                                @foreach ($kinds as $kindOption)
                                    <option value="{{ $kindOption->value }}" @selected(old('kind', 'kpi') === $kindOption->value)>{{ $crm_label('bi_widget_kind', $kindOption->value) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="bw-metric">{{ __('Метрика') }}<span class="req">*</span></label>
                            <select id="bw-metric" name="metric_code" class="form-select" required>
                                @foreach ($catalog as $code => $meta)
                                    <option value="{{ $code }}" @selected(old('metric_code') === $code)>{{ $meta['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Добавить') }}</button>
            </div>
        </form>
    </div>
</div>
@endcan

@push('styles')
<link rel="stylesheet" href="/assets/css/bumi-bi.css?v=9">
<link rel="stylesheet" href="/assets/css/bi-art.css?v=5">
@endpush

@push('scripts')
<script src="/assets/js/apexcharts.min.js?v=1"></script>
<script src="/assets/js/bi-widgets.js?v=1"></script>
<script src="/assets/js/bumi-runner.js?v=8"></script>
<script src="/assets/js/bumi-bi-panel.js?v=5"></script>
<script>
(function () {
    document.querySelectorAll('.bi-widget').forEach(function (el) {
        var spec;
        try { spec = JSON.parse(el.dataset.spec || '{}'); } catch (e) { return; }
        if (window.BiWidgets) { window.BiWidgets.render(el, spec); }
    });
    document.body.classList.add('bi-page');
    // Резервный обновлятор: только если polling недоступен/упал и статус всё ещё generating.
    var genState = document.getElementById('bumi-gen-state');
    if (genState && genState.dataset.statusUrl) {
        setTimeout(function () {
            if (document.body.classList.contains('bi-generating')) window.location.reload();
        }, 120000);
    }
})();
</script>
@endpush
@endsection
