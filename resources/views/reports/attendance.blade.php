@extends('layouts.tabler')
@section('workspace_layout', 'ledger')

@section('title', __('Отчёт: посещаемость'))

@php
    $totalDays = $rows->sum('days_worked');
    $totalLate = $rows->sum('late_total');
    $totalEarly = $rows->sum('early_total');
    $totalOvertime = $rows->sum('overtime_total');
    $totalMissing = $rows->sum('missing_checkout');
    $periodQuery = http_build_query(request()->query());
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Отчёты') }}</div>
            <h1 class="page-title mt-1">{{ __('Отчёт по посещаемости') }}</h1>
            <div class="text-secondary mt-1">{{ __('Период') }} {{ $from->format('d.m.Y') }} — {{ $to->format('d.m.Y') }} · scope: {{ $scope === 'company' ? __('компания') : ($scope === 'department' ? __('подразделение') : __('личный')) }}</div>
        </div>
        <div class="col-auto d-print-none">
            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <a class="btn btn-ghost-secondary" href="{{ route('reports.index') }}"><i class="ti ti-arrow-left me-1"></i>{{ __('К отчётам') }}</a>
                <a class="btn btn-primary" href="{{ route('reports.export', 'attendance') }}?{{ $periodQuery }}"><i class="ti ti-download me-1"></i>CSV/Excel</a>
            </div>
        </div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <div>
        <label class="form-label" for="att-from">{{ __('С') }}</label>
        <input type="date" id="att-from" name="from" value="{{ $from->toDateString() }}" class="form-control">
    </div>
    <div>
        <label class="form-label" for="att-to">{{ __('По') }}</label>
        <input type="date" id="att-to" name="to" value="{{ $to->toDateString() }}" class="form-control">
    </div>
    <div class="align-self-end"><button class="btn btn-outline-primary"><i class="ti ti-refresh me-1"></i>{{ __('Обновить') }}</button></div>
    <div class="crm-toolbar__spacer"></div>
    <span class="crm-toolbar__count">{{ __('Показано сотрудников') }} {{ $rows->count() }}</span>
</form>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-calendar-check"></i>{{ __('Дней отработано') }}</div>
        <div class="crm-metric__value">{{ $totalDays }}</div>
        <div class="crm-metric__hint">{{ __('по всем сотрудникам за период') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-danger);"><i class="ti ti-clock-exclamation"></i>{{ __('Опоздания') }}</div>
        <div class="crm-metric__value">{{ $totalLate }} <small>{{ __('мин') }}</small></div>
        <div class="crm-metric__hint">{{ __('суммарное опоздание') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-clock-off"></i>{{ __('Ранние уходы') }}</div>
        <div class="crm-metric__value">{{ $totalEarly }} <small>{{ __('мин') }}</small></div>
        <div class="crm-metric__hint">{{ __('без учёта отгулов') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-clock-hour-4"></i>{{ __('Сверхурочные') }}</div>
        <div class="crm-metric__value">{{ $totalOvertime }} <small>{{ __('мин') }}</small></div>
        <div class="crm-metric__hint">{{ __('по отметкам выхода') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-door-exit"></i>{{ __('Незакрытых смен') }}</div>
        <div class="crm-metric__value">{{ $totalMissing }}</div>
        <div class="crm-metric__hint">{{ __('нет отметки ухода') }}</div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="1" class="table table-hover table-vcenter card-table mb-0 crm-thead-sticky">
            <thead>
                <tr>
                    <th scope="col">{{ __('Таб.№') }}</th>
                    <th scope="col">{{ __('Сотрудник') }}</th>
                    <th scope="col" class="text-end">{{ __('Дней') }}</th>
                    <th scope="col" class="text-end">{{ __('Опоздания (мин)') }}</th>
                    <th scope="col" class="text-end">{{ __('Ранние уходы (мин)') }}</th>
                    <th scope="col" class="text-end">{{ __('Сверхурочные (мин)') }}</th>
                    <th scope="col" class="text-end">{{ __('Незакрытых смен') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td class="text-secondary crm-table-num">{{ $row['employee']->employee_no }}</td>
                        <td>
                            <span class="crm-table__main">{{ $row['employee']->last_name }} {{ $row['employee']->first_name }}</span>
                            <div class="crm-table__sub">{{ $row['employee']->department?->name ?? __('без подразделения') }}</div>
                        </td>
                        <td class="text-end crm-table-num">{{ $row['days_worked'] }}</td>
                        <td class="text-end crm-table-num">
                            @if ($row['late_total'] > 0)
                                <span class="crm-chip crm-chip--red">{{ $row['late_total'] }}</span>
                            @else
                                <span class="text-secondary">0</span>
                            @endif
                        </td>
                        <td class="text-end crm-table-num">{{ $row['early_total'] }}</td>
                        <td class="text-end crm-table-num {{ $row['overtime_total'] > 0 ? 'text-success' : 'text-secondary' }}">{{ $row['overtime_total'] }}</td>
                        <td class="text-end crm-table-num {{ $row['missing_checkout'] > 0 ? 'text-warning' : 'text-secondary' }}">{{ $row['missing_checkout'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            @include('partials.empty-state', ['icon' => 'calendar-x', 'title' => __('Данных нет за период'), 'text' => __('В выбранном периоде нет отметок посещаемости у сотрудников вашего scope.')])
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <th scope="col" colspan="2">{{ __('Итого') }}</th>
                    <th scope="col" class="text-end crm-table-num">{{ $totalDays }}</th>
                    <th scope="col" class="text-end crm-table-num">{{ $totalLate }}</th>
                    <th scope="col" class="text-end crm-table-num">{{ $totalEarly }}</th>
                    <th scope="col" class="text-end crm-table-num">{{ $totalOvertime }}</th>
                    <th scope="col" class="text-end crm-table-num">{{ $totalMissing }}</th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
@endsection
