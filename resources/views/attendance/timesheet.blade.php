@extends('layouts.tabler')
@section('workspace_layout', 'ledger')

@section('title', __('Табель') . ' — ' . $employee->last_name)

@php
    $monthNames = [
        1 => __('Январь'), 2 => __('Февраль'), 3 => __('Март'), 4 => __('Апрель'), 5 => __('Май'), 6 => __('Июнь'),
        7 => __('Июль'), 8 => __('Август'), 9 => __('Сентябрь'), 10 => __('Октябрь'), 11 => __('Ноябрь'), 12 => __('Декабрь'),
    ];
    $workedHours = number_format($totals['worked'] / 60, 1, ',', '');
    $planHours = number_format($totals['planned'] / 60, 1, ',', '');
    $fill = $totals['planned'] > 0 ? min(100, round($totals['worked'] / $totals['planned'] * 100)) : 0;
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Посещаемость') }}</div>
            <h1 class="page-title mt-1">{{ __('Табель') }} {{ $monthNames[$month] }} {{ $year }}</h1>
            <div class="text-secondary mt-1">{{ $employee->last_name }} {{ $employee->first_name }} · {{ $employee->employee_no }}</div>
        </div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    @if ($scope && $scope->value !== 'own')
        @php
            $me = auth()->user()->employee;
            if ($scope->value === 'department') {
                $options = \App\Models\Employee::query()->where('department_id', $me->department_id)->orderBy('last_name')->get();
            } else {
                $options = \App\Models\Employee::query()->where('company_id', $me->company_id)->where('status', 'active')->orderBy('last_name')->get();
            }
        @endphp
        <label class="form-label mb-0 me-1" for="ts-emp">{{ __('Сотрудник') }}</label>
        <select id="ts-emp" name="employee_id" class="form-select">
            @foreach ($options as $option)
                <option value="{{ $option->id }}" @selected($option->id === $employee->id)>{{ $option->last_name }} {{ $option->first_name }}</option>
            @endforeach
        </select>
    @endif
    <label class="form-label mb-0 me-1" for="ts-month">{{ __('Месяц') }}</label>
    <select id="ts-month" name="month" class="form-select">
        @for ($m = 1; $m <= 12; $m++)
            <option value="{{ $m }}" @selected($m === $month)>{{ $monthNames[$m] }}</option>
        @endfor
    </select>
    <label class="form-label mb-0 me-1" for="ts-year">{{ __('Год') }}</label>
    <input type="number" id="ts-year" name="year" value="{{ $year }}" class="form-control" style="width: 6.5rem" min="2020" max="2100">
    <button class="btn btn-primary"><i class="ti ti-refresh me-1"></i>{{ __('Показать') }}</button>
    <div class="crm-toolbar__spacer"></div>
    @if (app(\App\Support\PermissionService::class)->check(auth()->user(), 'attendance.timesheet.export'))
        <a class="btn btn-ghost-secondary" href="{{ route('attendance.timesheet.export') }}?{{ http_build_query(['year' => $year, 'month' => $month, 'employee_id' => $employee->id]) }}">
            <i class="ti ti-download me-1"></i>CSV/Excel
        </a>
    @endif
</form>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-clock-play"></i>{{ __('Отработано') }}</div>
        <div class="crm-metric__value">{{ $workedHours }}<small> {{ __('ч') }}</small></div>
        <div class="crm-metric__hint">{{ $totals['worked'] }} {{ __('мин. всего') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-calendar-stats"></i>{{ __('План') }}</div>
        <div class="crm-metric__value">{{ $planHours }}<small> {{ __('ч') }}</small></div>
        <div class="crm-metric__hint">{{ $totals['planned'] }} {{ __('мин. по плану') }}</div>
        <div class="progress mt-2" style="height: 5px;">
            <div class="progress-bar bg-primary" role="progressbar" style="width: {{ $fill }}%"></div>
        </div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-alarm"></i>{{ __('Опоздания') }}</div>
        <div class="crm-metric__value" data-count="{{ $totals['late'] }}">{{ $totals['late'] }}<small> {{ __('мин') }}</small></div>
        <div class="crm-metric__hint">{{ __('Суммарно за месяц') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-clock-up"></i>{{ __('Переработка') }}</div>
        <div class="crm-metric__value" data-count="{{ $totals['overtime'] }}">{{ $totals['overtime'] }}<small> {{ __('мин') }}</small></div>
        <div class="crm-metric__hint">{{ __('Сверх плана') }}</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title bm-section-title">{{ __('Дни за месяц') }}</h2>
        <span class="crm-chip ms-auto">{{ count($grid) }} {{ __('дней') }}</span>
    </div>
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table mb-0">
            <thead>
                <tr>
                    <th scope="col">{{ __('Дата') }}</th><th scope="col">{{ __('Статус') }}</th><th scope="col">{{ __('Вход') }}</th><th scope="col">{{ __('Выход') }}</th>
                    <th scope="col" class="text-end">{{ __('Отраб.') }}</th><th scope="col" class="text-end">{{ __('Опозд.') }}</th><th scope="col" class="text-end">{{ __('Ранний уход') }}</th><th scope="col" class="text-end">{{ __('Перер.') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($grid as $cell)
                    <tr class="{{ $cell['is_off'] ? 'crm-row-off' : '' }}">
                        <td>
                            <span class="crm-table__main">{{ $cell['day'] }} {{ \Illuminate\Support\Str::substr($monthNames[$month], 0, 3) }}</span>
                            <div class="crm-table__sub">{{ \Carbon\Carbon::create($year, $month, $cell['day'])->isoFormat('dd') }}</div>
                        </td>
                        @if ($cell['record'] !== null)
                            @php
                                $st = $cell['record']->status->value;
                                $stChip = match ($st) {
                                    'late', 'early_leave' => 'crm-chip--orange',
                                    'absent' => 'crm-chip--red',
                                    'overtime' => 'crm-chip--cyan',
                                    'weekend', 'holiday' => 'crm-chip--grey',
                                    default => 'crm-chip--green',
                                };
                            @endphp
                            <td><span class="crm-chip {{ $stChip }}">{{ $crm_label('attendance_status', $st) }}</span></td>
                            <td class="crm-table-num">{{ $cell['record']->clock_in_at?->format('H:i') ?? '—' }}</td>
                            <td class="crm-table-num">{{ $cell['record']->clock_out_at?->format('H:i') ?? '—' }}</td>
                            <td class="text-end crm-table-num">{{ $cell['record']->worked_minutes }}</td>
                            <td class="text-end crm-table-num @if($cell['record']->late_minutes > 0) text-danger fw-semibold @endif">{{ $cell['record']->late_minutes }}</td>
                            <td class="text-end crm-table-num">{{ $cell['record']->early_leave_minutes }}</td>
                            <td class="text-end crm-table-num @if($cell['record']->overtime_minutes > 0) text-success fw-semibold @endif">{{ $cell['record']->overtime_minutes }}</td>
                        @else
                            <td colspan="7" class="text-secondary">{{ $cell['is_off'] ? __('Выходной') : __('Нет отметок') }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
