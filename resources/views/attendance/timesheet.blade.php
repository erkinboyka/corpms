@extends('layouts.tabler')

@section('title', 'Табель — ' . $employee->last_name)

@php
    $monthNames = [
        1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
        7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
    ];
    $workedHours = number_format($totals['worked'] / 60, 1, ',', '');
    $planHours = number_format($totals['planned'] / 60, 1, ',', '');
    $fill = $totals['planned'] > 0 ? min(100, round($totals['worked'] / $totals['planned'] * 100)) : 0;
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Посещаемость</div>
            <h2 class="page-title mt-1">Табель · {{ $monthNames[$month] }} {{ $year }}</h2>
            <div class="text-secondary mt-1">{{ $employee->last_name }} {{ $employee->first_name }} · {{ $employee->employee_no }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary" href="{{ route('attendance.index') }}"><i class="ti ti-arrow-left me-1"></i>Сегодня</a>
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
        <label class="form-label mb-0 me-1" for="ts-emp">Сотрудник</label>
        <select id="ts-emp" name="employee_id" class="form-select">
            @foreach ($options as $option)
                <option value="{{ $option->id }}" @selected($option->id === $employee->id)>{{ $option->last_name }} {{ $option->first_name }}</option>
            @endforeach
        </select>
    @endif
    <label class="form-label mb-0 me-1" for="ts-month">Месяц</label>
    <select id="ts-month" name="month" class="form-select">
        @for ($m = 1; $m <= 12; $m++)
            <option value="{{ $m }}" @selected($m === $month)>{{ $monthNames[$m] }}</option>
        @endfor
    </select>
    <label class="form-label mb-0 me-1" for="ts-year">Год</label>
    <input type="number" id="ts-year" name="year" value="{{ $year }}" class="form-control" style="width: 6.5rem" min="2020" max="2100">
    <button class="btn btn-primary"><i class="ti ti-refresh me-1"></i>Показать</button>
    <div class="crm-toolbar__spacer"></div>
    @if (app(\App\Support\PermissionService::class)->check(auth()->user(), 'attendance.timesheet.export'))
        <a class="btn btn-ghost-secondary" href="{{ route('attendance.timesheet.export') }}?{{ http_build_query(['year' => $year, 'month' => $month, 'employee_id' => $employee->id]) }}">
            <i class="ti ti-download me-1"></i>CSV/Excel
        </a>
    @endif
</form>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-clock-play"></i>Отработано</div>
        <div class="crm-metric__value">{{ $workedHours }}<small> ч</small></div>
        <div class="crm-metric__hint">{{ $totals['worked'] }} мин. фактически</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-calendar-stats"></i>План</div>
        <div class="crm-metric__value">{{ $planHours }}<small> ч</small></div>
        <div class="crm-metric__hint">{{ $totals['planned'] }} мин. по графику</div>
        <div class="progress mt-2" style="height: 5px;">
            <div class="progress-bar bg-primary" role="progressbar" style="width: {{ $fill }}%"></div>
        </div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-alarm"></i>Опоздания</div>
        <div class="crm-metric__value" data-count="{{ $totals['late'] }}">{{ $totals['late'] }}<small> мин</small></div>
        <div class="crm-metric__hint">суммарно за месяц</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-clock-up"></i>Переработки</div>
        <div class="crm-metric__value" data-count="{{ $totals['overtime'] }}">{{ $totals['overtime'] }}<small> мин</small></div>
        <div class="crm-metric__hint">сверх графика</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <strong>Сетка за месяц</strong>
        <span class="crm-chip ms-auto">{{ count($grid) }} дней</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-vcenter card-table mb-0">
            <thead>
                <tr>
                    <th>День</th><th>Статус</th><th>Вход</th><th>Выход</th>
                    <th class="text-end">Отраб.</th><th class="text-end">Опозд.</th><th class="text-end">Ранний уход</th><th class="text-end">Сверх</th>
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
                            <td colspan="7" class="text-secondary">{{ $cell['is_off'] ? 'выходной' : 'нет данных' }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
