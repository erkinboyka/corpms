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
                <span class="text-secondary small">
                    @if ($dashboard->origin === 'ai')
                        {{ __('данные из SQL-источников компании') }}
                    @else
                        {{ __('значения считаются из витрины метрик') }}
                    @endif
                </span>
            </div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('bi.index', ['month' => $month]) }}"><i class="ti ti-arrow-left me-1"></i>{{ __('К дашбордам') }}</a>
            @can('checkBoxPermission', 'bi.manage')
            <button type="button" class="btn btn-outline-primary me-1" data-bi-generate data-ai-write><i class="ti ti-sparkles me-1"></i>{{ __('Создать дашборд') }}</button>
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

    <div class="card mt-3" data-gen-progress>
        <div class="card-header">
            <div class="card-title">{{ __('Bumi собирает дашборд') }}</div>
            <div class="card-actions text-secondary small" data-bm-progress-count>0 / 0</div>
        </div>
        <div class="list-group list-group-flush" data-bm-progress-list>
            @forelse ($widgets as $progress)
                @php
                    $progressWidget = $progress['widget'];
                    $progressStatus = (string) $progressWidget->status;
                    $progressLabel = match ($progressStatus) {
                        'ready' => __('готов'),
                        'error' => __('ошибка'),
                        'generating', 'pending' => __('в работе'),
                        default => $progressStatus,
                    };
                @endphp
                <li class="list-group-item d-flex align-items-center justify-content-between gap-2" data-widget-id="{{ $progressWidget->id }}">
                    <span data-title>{{ $progressWidget->title }}</span>
                    <span class="crm-chip" data-status>{{ $progressLabel }}</span>
                </li>
            @empty
                <li class="list-group-item text-secondary" data-empty>{{ __('Bumi ещё не составил план виджетов…') }}</li>
            @endforelse
        </div>
    </div>

    <div class="bi-gen-veil" data-gen-veil aria-hidden="true">
        <div class="bi-gen-veil__chart bi-skeleton">
            <span class="bi-skeleton__bar"></span><span class="bi-skeleton__bar"></span><span class="bi-skeleton__bar"></span><span class="bi-skeleton__bar"></span><span class="bi-skeleton__bar"></span><span class="bi-skeleton__bar"></span>
            <svg class="bi-skeleton__line" viewBox="0 0 120 34" preserveAspectRatio="none"><polyline points="0,27 18,20 34,23 52,10 70,16 88,6 120,12"/></svg>
        </div>
        <span class="bi-gen-veil__spark">✦</span>
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
        @php
            $aiSpan = in_array((int) $widget->span, [4, 6, 12], true) ? (int) $widget->span : 4;
        @endphp
        <div class="col-12 col-md-{{ $isAi ? ($aiSpan === 4 ? 6 : 12) : 6 }} col-xl-{{ $isAi ? $aiSpan : ($widget->kind->value === 'kpi' ? 3 : 6) }} bi-art-cell"
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
                        @if ($isAi && $widget->status === 'ready' && empty($data['has_data']))
                            <span class="crm-chip">{{ __('нет данных') }}</span>
                        @endif
                        @can('checkBoxPermission', 'bi.manage')
                        @if ($isAi)
                            <button type="button" class="btn btn-sm btn-ghost-secondary bi-art-card__edit"
                                    data-bs-toggle="modal" data-bs-target="#editWidget" data-widget-edit
                                    data-id="{{ $widget->id }}"
                                    data-title="{{ $widget->title }}"
                                    data-family="{{ $widget->widget_family }}"
                                    data-type="{{ $widget->widget_type }}"
                                    data-span="{{ $aiSpan }}"
                                    data-presentation="{{ json_encode($widget->query_spec['presentation'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
                                    title="{{ __('Изменить') }}"><i class="ti ti-settings"></i></button>
                        @endif
                        <form method="post" action="{{ route('bi.widgets.destroy', [$dashboard, $widget]) }}"
                              data-confirm="{{ __('Удалить виджет') }} «{{ $widget->title }}»?" data-confirm-danger="1" data-confirm-ok="{{ __('Удалить') }}">
                            @csrf
                            @method('delete')
                            <button type="submit" class="btn btn-sm btn-ghost-danger bi-art-card__remove" title="{{ __("Удалить") }}"><i class="ti ti-trash"></i></button>
                        </form>
                        @endcan
                    </div>
                </div>
                <div class="bi-art-card__body">
                    @if ($isAi && $widget->last_run_at)
                        <div class="text-secondary small mb-2">{{ __('обновлено') }} {{ $widget->last_run_at->format('d.m H:i') }}</div>
                    @endif
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
                                 data-spec="{{ json_encode(['family' => $widget->widget_family, 'type' => $widget->widget_type, 'content' => $data['content'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}">
                                @include('bi.widgets.fallback', [
                                    'widget' => $widget,
                                    'family' => $widget->widget_family,
                                    'type' => $widget->widget_type,
                                    'content' => $data['content'] ?? null,
                                    'accent' => $accent,
                                ])
                            </div>
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
            @if ($dashboard->origin === 'ai' && $dashboard->status === 'generating')
                <div class="text-secondary small">{{ __('Виджеты появятся здесь, когда Bumi закончит план.') }}</div>
            @else
                @include('partials.empty-state', ['icon' => 'layout-dashboard', 'title' => __('Виджетов нет'), 'text' => __('Добавьте KPI или тренд по метрике витрины — значения появятся после пересборки.')])
            @endif
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

<div class="modal modal-blur fade" id="editWidget" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" data-update-url="{{ route('bi.widgets.update', [$dashboard, '__WIDGET__']) }}" data-submit-guard>
            @csrf
            @method('put')
            <div class="modal-header"><h5 class="modal-title">{{ __('Изменить виджет') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Вид и раскладка') }}</h3>
                    <div class="crm-form__desc">{{ __('SQL и фильтр компании остаются прежними — меняется только представление.') }}</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ew-title">{{ __('Название') }}<span class="req">*</span></label>
                        <input type="text" id="ew-title" name="title" class="form-control" required>
                    </div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ew-family">{{ __('Семейство') }}<span class="req">*</span></label>
                            <select id="ew-family" name="widget_family" class="form-select" required>
                                @foreach ($widgetFamilies as $familyOption)
                                    <option value="{{ $familyOption['name'] }}">{{ $familyOption['name'] }} — {{ $familyOption['purpose'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ew-type">{{ __('Тип') }}</label>
                            <select id="ew-type" name="widget_type" class="form-select"></select>
                        </div>
                    </div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ew-span">{{ __('Ширина') }}<span class="req">*</span></label>
                            <select id="ew-span" name="span" class="form-select" required>
                                @foreach ($spanOptions as $spanOption)
                                    <option value="{{ $spanOption }}">{{ $spanOption === 4 ? __('узкий (1/3)') : ($spanOption === 6 ? __('половина') : __('вся строка')) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ew-height">{{ __('Высота') }}</label>
                            <input id="ew-height" name="presentation_height" type="number" min="180" max="640" step="20" class="form-control" placeholder="280">
                            <div class="form-text">{{ __('Оставь пустым, чтобы высота выбралась автоматически') }}</div>
                        </div>
                    </div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ew-colors">{{ __('Цвета серий') }}</label>
                            <div id="ew-colors" class="bi-edit-colors" data-bi-colors>
                                <div class="bi-edit-colors__row">
                                    <input type="color" class="form-control form-control-color" value="#206bc4" aria-label="{{ __('Цвет 1') }}">
                                    <input type="color" class="form-control form-control-color" value="#d63939" aria-label="{{ __('Цвет 2') }}">
                                    <input type="color" class="form-control form-control-color" value="#2fb344" aria-label="{{ __('Цвет 3') }}">
                                    <input type="color" class="form-control form-control-color" value="#f76707" aria-label="{{ __('Цвет 4') }}">
                                    <button type="button" class="btn btn-sm btn-ghost-secondary" data-bi-colors-add aria-label="{{ __('Добавить цвет') }}"><i class="ti ti-plus"></i></button>
                                </div>
                            </div>
                            <div class="form-text">{{ __('Первые цвета задают серии; остальные — по умолчанию') }}</div>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ew-suffix">{{ __('Суффикс счётчиков') }}</label>
                            <input id="ew-suffix" name="presentation_suffix" type="text" class="form-control" maxlength="16" placeholder="%, ₽, шт.">
                            <div class="form-text">{{ __('Например, % или ₽ — только для плиток-счётчиков') }}</div>
                        </div>
                    </div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ew-series-kind-0">{{ __('Тип строк') }}</label>
                            <div id="ew-series-kinds" data-bi-series-kinds>
                                <div class="bi-edit-series">
                                    <input type="text" class="form-control" data-bi-series-name placeholder="{{ __('Название series') }}" aria-label="{{ __('Название series 1') }}">
                                    <select class="form-select" data-bi-series-kind aria-label="{{ __('Тип series 1') }}">
                                        <option value="column">{{ __('столбец') }}</option>
                                        <option value="line">{{ __('линия') }}</option>
                                        <option value="area">{{ __('заливка') }}</option>
                                    </select>
                                </div>
                                <button type="button" class="btn btn-sm btn-ghost-secondary mt-2" data-bi-series-add><i class="ti ti-plus me-1"></i>{{ __('Добавить series') }}</button>
                            </div>
                            <div class="form-text">{{ __('Нужно только для combo: какой ряд столбец, какой линией') }}</div>
                        </div>
                    </div>
                    <input type="hidden" name="presentation" value="{}">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Сохранить') }}</button>
            </div>
        </form>
    </div>
</div>
@endcan

@push('styles')
<link rel="stylesheet" href="/assets/css/bumi-bi.css?v=10">
<link rel="stylesheet" href="/assets/css/bi-art.css?v=9">
@endpush

@push('scripts')
<script src="/assets/js/apexcharts.min.js?v=1"></script>
<script src="/assets/js/bi-widgets.js?v=4"></script>
<script src="/assets/js/bumi-runner.js?v=10"></script>
<script src="/assets/js/bumi-bi-panel.js?v=7"></script>
<script>
(function () {
    var families = @json($widgetFamilies);

    function parsePresentation(raw) {
        try {
            var parsed = JSON.parse(raw || '{}');
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function hex(value, fallback) {
        return typeof value === 'string' && /^#[0-9a-f]{6}$/i.test(value) ? value : fallback;
    }

    function syncPresentation(form) {
        var presentation = {};
        var colors = [...form.querySelectorAll('[data-bi-colors] input[type="color"]')]
            .map(function (input) { return input.value; })
            .filter(function (color, index, all) { return color && all.indexOf(color) === index; });

        if (colors.length) presentation.colors = colors;

        var height = parseInt(form.querySelector('[name="presentation_height"]').value, 10);
        if (!isNaN(height) && height >= 180 && height <= 640) presentation.height = height;

        var counters = {};
        var suffix = form.querySelector('[name="presentation_suffix"]').value.trim();
        if (suffix !== '') counters['*'] = { suffix: suffix };
        if (Object.keys(counters).length) presentation.counters = counters;

        var kinds = {};
        form.querySelectorAll('[data-bi-series-kinds] .bi-edit-series:not([hidden])').forEach(function (row) {
            var name = row.querySelector('[data-bi-series-name]').value.trim();
            var kind = row.querySelector('[data-bi-series-kind]').value;
            if (name && kind) kinds[name] = kind;
        });
        if (Object.keys(kinds).length) presentation.series_kinds = kinds;

        form.querySelector('[name="presentation"]').value = JSON.stringify(presentation);
    }

    function fillPresentation(form, raw) {
        var presentation = parsePresentation(raw);
        var colorInputs = [...form.querySelectorAll('[data-bi-colors] input[type="color"]')];
        var picked = Array.isArray(presentation.colors) ? presentation.colors : [];
        colorInputs.forEach(function (input, index) {
            input.value = hex(picked[index], input.value);
        });

        form.querySelector('[name="presentation_height"]').value = presentation.height || '';

        var counters = presentation.counters && typeof presentation.counters === 'object' ? presentation.counters : {};
        var anyCounter = counters['*'] || Object.values(counters).find(function (item) { return item && typeof item === 'object'; }) || {};
        form.querySelector('[name="presentation_suffix"]').value = anyCounter.suffix || '';

        var holder = form.querySelector('[data-bi-series-kinds]');
        var kinds = presentation.series_kinds && typeof presentation.series_kinds === 'object' ? presentation.series_kinds : {};
        var pairs = Object.entries(kinds);
        if (pairs.length === 0) pairs.push(['', 'column']);
        holder.querySelectorAll('.bi-edit-series').forEach(function (row, index) {
            var pair = pairs[index] || ['', 'column'];
            var input = row.querySelector('[data-bi-series-name]');
            var select = row.querySelector('[data-bi-series-kind]');
            input.value = pair[0];
            input.setAttribute('aria-label', index === 0 ? 'Название series 1' : 'Название series ' + (index + 1));
            select.value = pair[1] === 'line' || pair[1] === 'area' ? pair[1] : 'column';
            select.setAttribute('aria-label', index === 0 ? 'Тип series 1' : 'Тип series ' + (index + 1));
            row.hidden = false;
        });
        for (var i = holder.querySelectorAll('.bi-edit-series').length; i < pairs.length; i++) {
            var extra = document.createElement('div');
            extra.className = 'bi-edit-series';
            extra.innerHTML = '<input type="text" class="form-control" data-bi-series-name placeholder="Название series" aria-label="Название series">'
                + '<select class="form-select" data-bi-series-kind aria-label="Тип series">'
                + '<option value="column">столбец</option><option value="line">линия</option><option value="area">заливка</option></select>';
            extra.querySelector('[data-bi-series-name]').value = pairs[i][0];
            extra.querySelector('[data-bi-series-kind]').value = pairs[i][1] === 'line' || pairs[i][1] === 'area' ? pairs[i][1] : 'column';
            holder.insertBefore(extra, holder.querySelector('[data-bi-series-add]'));
        }

        syncPresentation(form);
    }

    document.querySelectorAll('[data-bi-colors-add]').forEach(function (button) {
        button.addEventListener('click', function () {
            var row = button.closest('.bi-edit-colors__row');
            var input = document.createElement('input');
            input.type = 'color';
            input.className = 'form-control form-control-color';
            input.setAttribute('aria-label', 'Дополнительный цвет');
            input.value = '#4299e1';
            row.insertBefore(input, button);
            syncPresentation(button.closest('form'));
        });
    });

    document.querySelectorAll('[data-bi-series-add]').forEach(function (button) {
        button.addEventListener('click', function () {
            var holder = button.closest('[data-bi-series-kinds]');
            var existing = [...holder.querySelectorAll('.bi-edit-series')];
            var template = existing.find(function (row) { return row.hidden; }) || existing[0];
            var row = template.cloneNode(true);
            row.hidden = false;
            row.querySelector('[data-bi-series-name]').value = '';
            row.querySelector('[data-bi-series-kind]').value = 'column';
            var index = existing.filter(function (item) { return !item.hidden; }).length + 1;
            row.querySelector('[data-bi-series-name]').setAttribute('aria-label', 'Название series ' + index);
            row.querySelector('[data-bi-series-kind]').setAttribute('aria-label', 'Тип series ' + index);
            holder.insertBefore(row, button);
        });
    });

    document.querySelectorAll('#editWidget input, #editWidget select').forEach(function (control) {
        control.addEventListener('input', function () { syncPresentation(control.closest('form')); });
        control.addEventListener('change', function () { syncPresentation(control.closest('form')); });
    });

    document.querySelectorAll('[data-widget-edit]').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = document.querySelector('#editWidget form');
            if (!form) return;

            var family = button.dataset.family || '';
            var type = button.dataset.type || '';
            var entry = families.find(function (item) { return item.name === family; });

            form.action = form.dataset.updateUrl.replace('__WIDGET__', button.dataset.id);
            form.querySelector('[name="title"]').value = button.dataset.title || '';

            var familySelect = form.querySelector('[name="widget_family"]');
            familySelect.value = family;

            var typeSelect = form.querySelector('[name="widget_type"]');
            typeSelect.innerHTML = '';
            typeSelect.appendChild(new Option('—', '', false, type === ''));
            (entry ? entry.types : []).forEach(function (option) {
                typeSelect.appendChild(new Option(option.type, option.type, false, option.type === type));
            });

            form.querySelector('[name="span"]').value = button.dataset.span || '4';
            fillPresentation(form, button.dataset.presentation || '{}');
        });
    });

    document.querySelectorAll('.bi-widget').forEach(function (el) {
        var spec;
        try { spec = JSON.parse(el.dataset.spec || '{}'); } catch (e) { return; }
        // Серверная разметка остаётся на месте, если ApexCharts не загрузился
        // или рендер упал: данные уже видны без JavaScript.
        if (!window.BiWidgets || !window.ApexCharts) return;
        var ssr = el.innerHTML;
        try {
            window.BiWidgets.render(el, spec);
            if (!el.childNodes.length) { el.innerHTML = ssr; }
        } catch (e) {
            el.innerHTML = ssr;
        }
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
