@extends('layouts.tabler')
@section('workspace_layout', 'ledger')

@section('title', __('Отчёт: задачи'))

@php
    $totalAll = $rows->sum('total');
    $totalDone = $rows->sum('completed');
    $totalProgress = $rows->sum('in_progress');
    $totalOverdue = $rows->sum('overdue');
    $periodQuery = http_build_query(request()->query());
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Отчёты') }}</div>
            <h1 class="page-title mt-1">{{ __('Отчёт по задачам') }}</h1>
            <div class="text-secondary mt-1">{{ __('Период') }} {{ $from->format('d.m.Y') }} — {{ $to->format('d.m.Y') }} {{ __('назначения сотрудников за период') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <a class="btn btn-ghost-secondary" href="{{ route('reports.index') }}"><i class="ti ti-arrow-left me-1"></i>{{ __('К отчётам') }}</a>
                <a class="btn btn-primary" href="{{ route('reports.export', 'tasks') }}?{{ $periodQuery }}"><i class="ti ti-download me-1"></i>CSV/Excel</a>
            </div>
        </div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <div>
        <label class="form-label" for="tsk-from">{{ __('С') }}</label>
        <input type="date" id="tsk-from" name="from" value="{{ $from->toDateString() }}" class="form-control">
    </div>
    <div>
        <label class="form-label" for="tsk-to">{{ __('По') }}</label>
        <input type="date" id="tsk-to" name="to" value="{{ $to->toDateString() }}" class="form-control">
    </div>
    <div class="pb-1"><button class="btn btn-outline-primary"><i class="ti ti-refresh me-1"></i>{{ __('Обновить') }}</button></div>
    <div class="crm-toolbar__spacer"></div>
    <span class="crm-toolbar__count">{{ __('Сотрудников с задачами') }} {{ $rows->count() }}</span>
</form>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-list-check"></i>{{ __('Всего задач') }}</div>
        <div class="crm-metric__value">{{ $totalAll }}</div>
        <div class="crm-metric__hint">{{ __('назначено за период') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-circle-check"></i>{{ __('Завершено') }}</div>
        <div class="crm-metric__value">{{ $totalDone }}</div>
        <div class="crm-metric__hint">{{ $totalAll > 0 ? (int) round(100 * $totalDone / $totalAll) : 0 }}% {{ __('от всех задач') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-progress"></i>{{ __('В работе') }}</div>
        <div class="crm-metric__value">{{ $totalProgress }}</div>
        <div class="crm-metric__hint">{{ __('в процессе исполнения') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-danger);"><i class="ti ti-alert-triangle"></i>{{ __('Просрочено') }}</div>
        <div class="crm-metric__value">{{ $totalOverdue }}</div>
        <div class="crm-metric__hint">{{ $totalOverdue > 0 ? __('требует внимания') : __('просроченных нет') }}</div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="1" class="table table-hover table-vcenter card-table mb-0 crm-thead-sticky">
            <thead>
                <tr>
                    <th scope="col">{{ __('Таб.№') }}</th>
                    <th scope="col">{{ __('Сотрудник') }}</th>
                    <th scope="col" class="text-end">{{ __('Всего') }}</th>
                    <th scope="col" class="text-end">{{ __('Завершено') }}</th>
                    <th scope="col" class="text-end">{{ __('В работе') }}</th>
                    <th scope="col" class="text-end">{{ __('Просрочено') }}</th>
                    <th scope="col" style="width: 22%">{{ __('Доля завершённых') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php($employee = $row['employee'])
                    @continue($employee === null)
                    <tr>
                        <td class="text-secondary crm-table-num">{{ $employee->employee_no }}</td>
                        <td>
                            <span class="crm-table__main">{{ $employee->last_name }} {{ $employee->first_name }}</span>
                            <div class="crm-table__sub">{{ $employee->department?->name ?? __('без подразделения') }}</div>
                        </td>
                        <td class="text-end crm-table-num fw-semibold">{{ $row['total'] }}</td>
                        <td class="text-end crm-table-num text-success">{{ $row['completed'] }}</td>
                        <td class="text-end crm-table-num">{{ $row['in_progress'] }}</td>
                        <td class="text-end crm-table-num">
                            @if ($row['overdue'] > 0)
                                <span class="crm-chip crm-chip--red">{{ $row['overdue'] }}</span>
                            @else
                                <span class="text-secondary">0</span>
                            @endif
                        </td>
                        <td>
                            <div class="crm-kpi__bar" style="--w: {{ $row['total'] > 0 ? (int) round(100 * $row['completed'] / $row['total']) : 0 }}%"><i></i></div>
                            <div class="crm-table__sub mt-1">{{ $row['total'] > 0 ? (int) round(100 * $row['completed'] / $row['total']) : 0 }}%</div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            @include('partials.empty-state', ['icon' => 'list-check', 'title' => __('Данных нет за период'), 'text' => __('В выбранном периоде у сотрудников вашего scope не было назначенных задач.')])
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <th scope="col" colspan="2">{{ __('Итого') }}</th>
                    <th scope="col" class="text-end crm-table-num">{{ $totalAll }}</th>
                    <th scope="col" class="text-end crm-table-num">{{ $totalDone }}</th>
                    <th scope="col" class="text-end crm-table-num">{{ $totalProgress }}</th>
                    <th scope="col" class="text-end crm-table-num">{{ $totalOverdue }}</th>
                    <th scope="col"></th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
@endsection
