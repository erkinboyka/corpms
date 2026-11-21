@extends('layouts.tabler')
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

<form method="get" class="crm-toolbar">
    <input type="month" name="month" class="form-control" value="{{ $month }}" aria-label="{{ __("Месяц") }}" onchange="this.form.submit()">
    <button class="btn btn-ghost-secondary"><i class="ti ti-calendar me-1"></i>{{ __('Показать месяц') }}</button>
</form>

<div class="row g-3">
    @forelse ($widgets as $item)
        @php
            $widget = $item['widget'];
            $data = $item['data'];
            $unit = $data['meta']['unit'] ?? 'count';
            $isMoney = $unit === 'money';
        @endphp
        @if ($widget->kind->value === 'kpi')
            <div class="col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-start justify-content-between">
                            <div class="crm-metric__label">{{ $widget->title }}</div>
                            @can('checkBoxPermission', 'bi.manage')
                            <form method="post" action="{{ route('bi.widgets.destroy', [$dashboard, $widget]) }}"
                                  onsubmit="return confirm('{{ __('Удалить виджет') }} «{{ $widget->title }}»?')">
                                @csrf
                                @method('delete')
                                <button class="btn btn-sm btn-ghost-danger" title="{{ __("Удалить") }}"><i class="ti ti-trash"></i></button>
                            </form>
                            @endcan
                        </div>
                        <div class="crm-metric__value mt-2">{{ number_format((float) ($data['value'] ?? 0), 0, ',', ' ') }}</div>
                        <div class="crm-metric__hint">{{ $data['meta']['label'] ?? '' }} · {{ $isMoney ? __('деньги') : __('штуки') }}</div>
                    </div>
                </div>
            </div>
        @else
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">
                        <div class="card-title">{{ $widget->title }}</div>
                        <div class="card-actions d-flex align-items-center gap-2">
                            <span class="text-secondary small">{{ $data['meta']['label'] ?? '' }}</span>
                            @can('checkBoxPermission', 'bi.manage')
                            <form method="post" action="{{ route('bi.widgets.destroy', [$dashboard, $widget]) }}"
                                  onsubmit="return confirm('{{ __('Удалить виджет') }} «{{ $widget->title }}»?')">
                                @csrf
                                @method('delete')
                                <button class="btn btn-sm btn-ghost-danger" title="{{ __("Удалить") }}"><i class="ti ti-trash"></i></button>
                            </form>
                            @endcan
                        </div>
                    </div>
                    <div class="card-body">
                        @if (($data['points'] ?? '') !== '')
                            <svg viewBox="0 0 240 48" width="100%" height="56" preserveAspectRatio="none" role="img" aria-label="{{ __('Тренд') }}: {{ $widget->title }}">
                                <polyline points="{{ $data['points'] }}" fill="none" stroke="var(--crm-accent, var(--crm-accent))" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></polyline>
                            </svg>
                        @else
                            <div class="text-secondary small">{{ __('Недостаточно данных за период — пересоберите витрину') }}</div>
                        @endif
                        <div class="d-flex justify-content-between mt-2 small">
                            <span class="text-secondary">{{ __('Сумма') }} <strong>{{ number_format((float) ($data['summary']['sum'] ?? 0), 0, ',', ' ') }}</strong></span>
                            <span class="text-secondary">{{ __('В среднем за день') }} <strong>{{ number_format((float) ($data['summary']['avg'] ?? 0), 0, ',', ' ') }}</strong></span>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @empty
        <div class="col-12">
            @include('partials.empty-state', ['icon' => 'layout-dashboard', 'title' => __('Виджетов нет'), 'text' => __('Добавьте KPI или тренд по метрике витрины — значения появятся после пересборки.')])
        </div>
    @endforelse
</div>

@if ($forecasts !== [])
    @php $firstForecast = reset($forecasts); @endphp
    <div class="card mt-3">
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
@endsection
