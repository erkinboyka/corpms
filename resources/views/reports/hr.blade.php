@extends('layouts.tabler')

@section('title', 'Отчёт: кадры')

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
            <div class="crm-eyebrow">Отчёты</div>
            <h2 class="page-title mt-1">Кадровый отчёт</h2>
            <div class="text-secondary mt-1">Численность по подразделениям · scope: {{ $scope === 'company' ? 'компания' : ($scope === 'department' ? 'подразделение' : 'личный') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <a class="btn btn-ghost-secondary" href="{{ route('reports.index') }}"><i class="ti ti-arrow-left me-1"></i>К отчётам</a>
                <a class="btn btn-primary" href="{{ route('reports.export', 'hr') . '?' . $periodQuery }}"><i class="ti ti-download me-1"></i>CSV/Excel</a>
            </div>
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #0b9b72;"><i class="ti ti-user-check"></i>Активных сотрудников</div>
        <div class="crm-metric__value">{{ $headcount['active'] }}</div>
        <div class="crm-metric__hint">со статусом «активен»</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #4299e1;"><i class="ti ti-users"></i>Всего в scope</div>
        <div class="crm-metric__value">{{ $total }}</div>
        <div class="crm-metric__hint">включая неактивных</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #f76707;"><i class="ti ti-building"></i>Подразделений</div>
        <div class="crm-metric__value">{{ count($departments) }}</div>
        <div class="crm-metric__hint">с сотрудниками в scope</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #ae3ec9;"><i class="ti ti-trending-up"></i>Крупнейший отдел</div>
        <div class="crm-metric__value">{{ $maxDept }} <small>чел.</small></div>
        <div class="crm-metric__hint">{{ $total > 0 ? (int) round(100 * $maxDept / $total) : 0 }}% численности</div>
    </div>
</div>

<div class="card crm-panel">
    <div class="card-header d-flex align-items-start justify-content-between gap-2">
        <div>
            <div class="card-title mb-0">Распределение по подразделениям</div>
            <div class="crm-panel__sub">длина полосы — доля отдела от крупнейшего</div>
        </div>
        <span class="crm-chip crm-chip--green">{{ $headcount['active'] }} активных</span>
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
                                    <div class="crm-table__sub">Руководитель: {{ $row['director'] ?? '—' }}</div>
                                </div>
                            </div>
                            <div class="text-end flex-none">
                                <div class="fw-semibold crm-table-num">{{ $row['headcount'] }} чел.</div>
                                <div class="crm-table__sub">{{ $total > 0 ? (int) round(100 * $row['headcount'] / $total) : 0 }}% от scope</div>
                            </div>
                        </div>
                        <div class="crm-kpi__bar" style="--w: {{ max(4, (int) round(100 * $row['headcount'] / $maxDept)) }}%"><i></i></div>
                    </div>
                @endforeach
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4 pt-3" style="border-top: 1px solid var(--tblr-border-color);">
                <span class="text-secondary small">Итого подразделений: {{ count($departments) }}</span>
                <span class="fw-semibold crm-table-num">Итого: {{ $total }} чел.</span>
            </div>
        @else
            @include('partials.empty-state', ['icon' => 'building', 'title' => 'Данных нет', 'text' => 'В вашей области доступа нет сотрудников, привязанных к подразделениям.'])
        @endif
    </div>
</div>
@endsection
