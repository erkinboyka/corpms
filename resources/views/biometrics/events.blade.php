@extends('layouts.tabler')
@section('workspace_layout', 'stream')

@section('title', __('События биометрии'))

@php
    $dirChips = ['in' => 'crm-chip--green', 'out' => 'crm-chip--orange'];
    $procChips = [
        'processed' => 'crm-chip--green',
        'failed' => 'crm-chip--red',
        'skipped' => 'crm-chip--grey',
    ];
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Доступ') }}</div>
            <h1 class="page-title mt-1">{{ __('Журнал биометрических событий') }}</h1>
            <div class="text-secondary mt-1">{{ __('События терминалов: проходы, liveness, обработка · показано') }} {{ $events->count() }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary" href="{{ route('biometrics.index') }}"><i class="ti ti-arrow-left me-1"></i>{{ __('К устройствам') }}</a>
        </div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <select name="device_id" class="form-select" aria-label="{{ __("Устройство") }}" onchange="this.form.submit()">
        <option value="">{{ __('Все устройства') }}</option>
        @foreach ($devices as $device)
            <option value="{{ $device->id }}" @selected((int) request('device_id') === $device->id)>{{ $device->name }}</option>
        @endforeach
    </select>
    <div class="crm-toolbar__spacer"></div>
    <span class="text-secondary small">{{ request('device_id') ? __('Фильтр по устройству включён') : __('Без фильтра — все устройства') }}</span>
</form>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="1" class="table table-hover table-vcenter card-table mb-0 crm-thead-sticky">
            <thead>
                <tr><th scope="col">{{ __('Время') }}</th><th scope="col">{{ __('Сотрудник') }}</th><th scope="col">{{ __('Устройство') }}</th><th scope="col">{{ __('Направление') }}</th><th scope="col">{{ __('Достоверность') }}</th><th scope="col">Liveness</th><th scope="col">{{ __('Обработка') }}</th></tr>
            </thead>
            <tbody>
                @forelse ($events as $event)
                    <tr>
                        <td class="crm-table-num text-secondary">{{ $event->occurred_at->format('d.m H:i:s') }}</td>
                        <td>
                            @if ($event->employee)
                                <span class="crm-table__main">{{ $event->employee->last_name }} {{ $event->employee->first_name }}</span>
                                <div class="crm-table__sub">{{ $event->employee->employee_no ?? '' }}</div>
                            @else
                                <span class="crm-chip crm-chip--orange">{{ __('не распознан') }}</span>
                            @endif
                        </td>
                        <td class="text-secondary">{{ $event->device?->name ?? '—' }}</td>
                        <td>
                            <span class="crm-chip {{ $dirChips[$event->direction] ?? 'crm-chip--grey' }}">
                                <i class="ti ti-{{ $event->direction === 'in' ? 'log-in' : 'log-out' }} me-1"></i>{{ $event->direction === 'in' ? __('вход') : __('выход') }}
                            </span>
                        </td>
                        <td class="crm-table-num">
                            @if ($event->confidence !== null)
                                <span class="fw-semibold {{ $event->confidence >= 0.9 ? '' : 'text-warning' }}">{{ number_format($event->confidence * 100, 1) }}%</span>
                            @else
                                <span class="text-secondary">—</span>
                            @endif
                        </td>
                        <td>
                            @if ($event->liveness_required)
                                <span class="crm-chip {{ $event->liveness_passed ? 'crm-chip--green' : 'crm-chip--red' }}">{{ $event->liveness_passed ? __('пройдена') : __('не пройдена') }}</span>
                            @else
                                <span class="text-secondary small">{{ __('не требуется') }}</span>
                            @endif
                        </td>
                        <td>
                            <span class="crm-chip {{ $procChips[$event->processing_status->value] ?? 'crm-chip--cyan' }}">{{ $crm_label('biometric_event_status', $event->processing_status->value) }}</span>
                            @if ($event->failure_reason)
                                <div class="text-danger small mt-1">{{ $event->failure_reason }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">@include('partials.empty-state', ['icon' => 'activity', 'title' => __('Событий нет'), 'text' => __('По выбранному фильтру событий не найдено — снимите фильтр устройства.')])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
