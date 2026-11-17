@extends('layouts.tabler')

@section('title', 'BI — Дашборды')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">BI</div>
            <h2 class="page-title mt-1">Дашборды</h2>
            <div class="text-secondary mt-1">Витрина метрик · период {{ $month }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('bi.metrics.index', ['month' => $month]) }}"><i class="ti ti-chart-line me-1"></i>Срезы витрины</a>
            @can('checkBoxPermission', 'bi.manage')
            <form method="post" action="{{ route('bi.rebuild') }}" class="d-inline me-1">
                @csrf
                <input type="hidden" name="month" value="{{ $month }}">
                <button class="btn btn-ghost-secondary"><i class="ti ti-refresh me-1"></i>Пересобрать витрину</button>
            </form>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newDashboard"><i class="ti ti-plus me-1"></i>Новый дашборд</button>
            @endcan
        </div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <input type="month" name="month" class="form-control" value="{{ $month }}" aria-label="Месяц" onchange="this.form.submit()">
    <button class="btn btn-ghost-secondary"><i class="ti ti-calendar me-1"></i>Показать месяц</button>
</form>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #0b9b72;"><i class="ti ti-arrow-down-left"></i>Доходы</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) ($totals['finance_income'] ?? 0), 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">за {{ $month }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #e5697a;"><i class="ti ti-arrow-up-right"></i>Расходы</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) ($totals['finance_expense'] ?? 0), 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">за {{ $month }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #ae3ec9;"><i class="ti ti-trophy"></i>Выиграно</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) ($totals['deals_won_amount'] ?? 0), 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">сделок: {{ number_format((float) ($totals['deals_won_count'] ?? 0), 0, ',', ' ') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #f76707;"><i class="ti ti-list-check"></i>Задачи</div>
        <div class="crm-metric__value">{{ number_format((float) ($totals['tasks_completed'] ?? 0), 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">завершено за период</div>
    </div>
</div>

<div class="row g-3">
    @forelse ($dashboards as $dashboard)
        <div class="col-md-6 col-xl-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="crm-table__main">{{ $dashboard->name }}</div>
                        @if ($dashboard->is_default)<span class="crm-chip crm-chip--green">по умолчанию</span>@endif
                    </div>
                    <div class="text-secondary small mt-1">Виджетов: {{ $dashboard->widgets_count }}</div>
                    <a class="btn btn-ghost-secondary btn-sm mt-3" href="{{ route('bi.dashboards.show', ['dashboard' => $dashboard, 'month' => $month]) }}">
                        <i class="ti ti-layout-dashboard me-1"></i>Открыть
                    </a>
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            @include('partials.empty-state', ['icon' => 'chart-histogram', 'title' => 'Дашбордов нет', 'text' => 'Создайте дашборд и добавьте на него KPI и тренды по метрикам витрины.'])
        </div>
    @endforelse
</div>

@can('checkBoxPermission', 'bi.manage')
<div class="modal modal-blur fade" id="newDashboard" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('bi.dashboards.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новый дашборд</h5></div>
            <div class="modal-body">
                <div class="crm-form__field">
                    <label class="form-label" for="bd-name">Название<span class="req">*</span></label>
                    <input type="text" id="bd-name" name="name" class="form-control" value="{{ old('name') }}" placeholder="Финансы и продажи" required>
                </div>
                <div class="form-text">Виджеты добавляются на странице дашборда.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Создать</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
