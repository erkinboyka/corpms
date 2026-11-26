@extends('layouts.tabler')
@section('body_class', 'bi-page')
@section('workspace_layout', 'overview')

@section('title', __('BI — Дашборды'))

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('BI-витрина') }}</div>
            <h1 class="page-title mt-1">{{ __('Дашборды') }}</h1>
            <div class="text-secondary mt-1">{{ __('Витрина метрик · период') }} {{ $month }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('bi.metrics.index', ['month' => $month]) }}"><i class="ti ti-chart-line me-1"></i>{{ __('Срезы витрины') }}</a>
            @can('checkBoxPermission', 'bi.manage')
            <form method="post" action="{{ route('bi.rebuild') }}" class="d-inline me-1">
                @csrf
                <input type="hidden" name="month" value="{{ $month }}">
                <button class="btn btn-ghost-secondary"><i class="ti ti-refresh me-1"></i>{{ __('Пересобрать витрину') }}</button>
            </form>
            <button class="btn btn-primary me-1" data-bi-generate><i class="ti ti-sparkles me-1"></i>{{ __('Сгенерировать ИИ') }}</button>
            <button class="btn btn-ghost-secondary" data-bs-toggle="modal" data-bs-target="#newDashboard"><i class="ti ti-plus me-1"></i>{{ __('Новый дашборд') }}</button>
            @endcan
        </div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <input type="month" name="month" class="form-control" value="{{ $month }}" aria-label="{{ __("Месяц") }}" onchange="this.form.submit()">
    <button class="btn btn-ghost-secondary"><i class="ti ti-calendar me-1"></i>{{ __('Показать месяц') }}</button>
</form>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label text-success"><i class="ti ti-arrow-down-left"></i>{{ __('Доходы') }}</div>
        <div class="crm-metric__value fs-5 pt-2">{{ number_format((float) ($totals['finance_income'] ?? 0), 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ __('за') }} {{ $month }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label text-danger"><i class="ti ti-arrow-up-right"></i>{{ __('Расходы') }}</div>
        <div class="crm-metric__value fs-5 pt-2">{{ number_format((float) ($totals['finance_expense'] ?? 0), 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ __('за') }} {{ $month }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label text-crm-purple"><i class="ti ti-trophy"></i>{{ __('Выиграно') }}</div>
        <div class="crm-metric__value fs-5 pt-2">{{ number_format((float) ($totals['deals_won_amount'] ?? 0), 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ __('сделок') }} {{ number_format((float) ($totals['deals_won_count'] ?? 0), 0, ',', ' ') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label text-warning"><i class="ti ti-list-check"></i>{{ __('Задачи') }}</div>
        <div class="crm-metric__value">{{ number_format((float) ($totals['tasks_completed'] ?? 0), 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ __('завершено за период') }}</div>
    </div>
</div>

<div class="row g-3">
    @forelse ($dashboards as $dashboard)
        <div class="col-md-6 col-xl-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="crm-table__main">{{ $dashboard->name }}</div>
                        @if ($dashboard->status === 'generating')<span class="crm-chip">{{ __('генерация…') }}</span>@endif
                        @if ($dashboard->status === 'error')<span class="crm-chip crm-chip--red" title="{{ $dashboard->last_error }}">{{ __('ошибка') }}</span>@endif
                        @if ($dashboard->origin === 'ai')<span class="crm-chip crm-chip--green">{{ __('ИИ') }}</span>@endif
                        @if ($dashboard->is_default)<span class="crm-chip crm-chip--green">{{ __('по умолчанию') }}</span>@endif
                    </div>
                    <div class="text-secondary small mt-1">{{ __('Виджетов') }} {{ $dashboard->widgets_count }}</div>
                    <a class="btn btn-ghost-secondary btn-sm mt-3" href="{{ route('bi.dashboards.show', ['dashboard' => $dashboard, 'month' => $month]) }}">
                        <i class="ti ti-layout-dashboard me-1"></i>{{ __('Открыть') }}
                    </a>
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            @include('partials.empty-state', ['icon' => 'chart-histogram', 'title' => __('Дашбордов нет'), 'text' => __('Создайте дашборд и добавьте на него KPI и тренды по метрикам витрины.')])
        </div>
    @endforelse
</div>

@can('checkBoxPermission', 'bi.manage')
<div class="modal modal-blur fade" id="newDashboard" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('bi.dashboards.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Новый дашборд') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__field">
                    <label class="form-label" for="bd-name">{{ __('Название') }}<span class="req">*</span></label>
                    <input type="text" id="bd-name" name="name" class="form-control" value="{{ old('name') }}" placeholder="{{ __("Финансы и продажи") }}" required>
                </div>
                <div class="form-text">{{ __('Виджеты добавляются на странице дашборда') }}</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Создать') }}</button>
            </div>
        </form>
    </div>
</div>
@endcan

@push('styles')
<link rel="stylesheet" href="/assets/css/bumi-bi.css?v=9">
@endpush

@push('scripts')
<script>document.body.classList.add('bi-page');</script>
<script src="/assets/js/bumi-bi-panel.js?v=7"></script>
@endpush
@endsection
