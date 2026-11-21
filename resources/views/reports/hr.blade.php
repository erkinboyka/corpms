@extends('layouts.tabler')
@section('workspace_layout', 'overview')

@section('title', __('Отчёт: кадры'))

@php
    $total = $headcount['total'];
    $maxDept = 1;
    foreach ($departments as $row) {
        $maxDept = max($maxDept, $row['headcount']);
    }
    $periodQuery = http_build_query(array_filter(['from' => request('from'), 'to' => request('to')]));
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Отчёты') }}</div>
            <h1 class="page-title mt-1">{{ __('Кадровый отчёт') }}</h1>
            <div class="text-secondary mt-1">{{ __('Численность по подразделениям · scope') }} {{ $scope === 'company' ? __('компания') : ($scope === 'department' ? __('подразделение') : __('личный')) }}</div>
        </div>
        <div class="col-auto d-print-none">
            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <a class="btn btn-ghost-secondary" href="{{ route('reports.index') }}"><i class="ti ti-arrow-left me-1"></i>{{ __('К отчётам') }}</a>
                <a class="btn btn-primary" href="{{ route('reports.export', 'hr') . '?' . $periodQuery }}"><i class="ti ti-download me-1"></i>CSV/Excel</a>
            </div>
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-user-check"></i>{{ __('Активных сотрудников') }}</div>
        <div class="crm-metric__value">{{ $headcount['active'] }}</div>
        <div class="crm-metric__hint">{{ __('со статусом «активен') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-users"></i>{{ __('Всего в scope') }}</div>
        <div class="crm-metric__value">{{ $total }}</div>
        <div class="crm-metric__hint">{{ __('включая неактивных') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-building"></i>{{ __('Подразделений') }}</div>
        <div class="crm-metric__value">{{ count($departments) }}</div>
        <div class="crm-metric__hint">{{ __('с сотрудниками в scope') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-purple);"><i class="ti ti-trending-up"></i>{{ __('Крупнейший отдел') }}</div>
        <div class="crm-metric__value">{{ $maxDept }} <small>{{ __('чел') }}</small></div>
        <div class="crm-metric__hint">{{ $total > 0 ? (int) round(100 * $maxDept / $total) : 0 }}% {{ __('численности') }}</div>
    </div>
</div>

<div class="card crm-panel">
    <div class="card-header d-flex align-items-start justify-content-between gap-2">
        <div>
            <div class="card-title mb-0">{{ __('Распределение по подразделениям') }}</div>
            <div class="crm-panel__sub">{{ __('длина полосы — доля отдела от крупнейшего') }}</div>
        </div>
        <span class="crm-chip crm-chip--green">{{ $headcount['active'] }} {{ __('активных') }}</span>
    </div>
    <div class="card-body">
        @if (count($departments) > 0)
            <div class="d-flex flex-column gap-4">
                @foreach ($departments as $row)
                    <div>
                        <div class="d-flex align-items-center justify-content-between gap-3 mb-2">
                            <div class="d-flex align-items-center gap-3 min-w-0">
                                <span class="crm-entity__logo" style="width: 36px; height: 36px; font-size: 13px;">
                                    {{ mb_strtoupper(mb_substr($row['name'], 0, 2)) }}
                                </span>
                                <div class="min-w-0">
                                    <div class="fw-semibold">{{ $row['name'] }}</div>
                                    <div class="crm-table__sub">{{ __('Руководитель') }} {{ $row['director'] ?? '—' }}</div>
                                </div>
                            </div>
                            <div class="text-end flex-none">
                                <div class="fw-semibold crm-table-num">{{ $row['headcount'] }} {{ __('чел') }}</div>
                                <div class="crm-table__sub">{{ $total > 0 ? (int) round(100 * $row['headcount'] / $total) : 0 }}% {{ __('от') }} scope</div>
                            </div>
                        </div>
                        <div class="crm-kpi__bar" style="--w: {{ max(4, (int) round(100 * $row['headcount'] / $maxDept)) }}%"><i></i></div>
                    </div>
                @endforeach
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4 pt-3" style="border-top: 1px solid var(--tblr-border-color);">
                <span class="text-secondary small">{{ __('Итого подразделений') }} {{ count($departments) }}</span>
                <span class="fw-semibold crm-table-num">{{ __('Итого') }} {{ $total }} {{ __('чел') }}</span>
            </div>
        @else
            @include('partials.empty-state', ['icon' => 'building', 'title' => __('Данных нет'), 'text' => __('В вашей области доступа нет сотрудников, привязанных к подразделениям.')])
        @endif
    </div>
</div>
@endsection
