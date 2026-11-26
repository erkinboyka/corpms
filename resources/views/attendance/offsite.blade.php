@extends('layouts.tabler')
@section('workspace_layout', 'stream')

@section('title', __('Удалённая работа'))

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Посещаемость') }}</div>
            <h1 class="page-title mt-1">{{ __('Удалённая работа') }}</h1>
            <div class="text-secondary mt-1">{{ __('Заявка не учитывается как присутствие, пока руководитель её не согласовал') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newOffsite"><i class="ti ti-plus me-1"></i>{{ __('Подать заявку') }}</button>
        </div>
    </div>
</div>

@php
    $labels = [
        'pending' => __('Ожидает решения'),
        'approved' => __('Согласовано'),
        'rejected' => __('Отклонено'),
        'cancelled' => __('Отозвано'),
    ];
    $tones = [
        'pending' => 'crm-chip--orange',
        'approved' => 'crm-chip--green',
        'rejected' => 'crm-chip--red',
        'cancelled' => 'crm-chip--grey',
    ];
    $pendingCount = $requests->filter(fn ($r) => $r->status->value === 'pending')->count();
    $approvedCount = $requests->filter(fn ($r) => $r->status->value === 'approved')->count();
@endphp

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-hourglass"></i>{{ __('Ожидают решения') }}</div>
        <div class="crm-metric__value">{{ $pendingCount }}</div>
        <div class="crm-metric__hint">{{ __('присутствие НЕ учитывается') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-circle-check"></i>{{ __('Согласовано') }}</div>
        <div class="crm-metric__value">{{ $approvedCount }}</div>
        <div class="crm-metric__hint">{{ __('PRESENT / OFFSITE без face') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-list"></i>{{ __('Всего заявок') }}</div>
        <div class="crm-metric__value">{{ $requests->count() }}</div>
        <div class="crm-metric__hint">{{ __('за всё время') }}</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title bm-section-title">{{ __('Заявки') }}</h2>
        <span class="crm-chip ms-auto">{{ $requests->count() }} {{ __('всего') }}</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-vcenter card-table mb-0">
            <thead>
                <tr>
                    <th scope="col">{{ __('Заявка') }}</th>
                    <th scope="col">{{ __('Сотрудник') }}</th>
                    <th scope="col">{{ __('Дата') }}</th>
                    <th scope="col">{{ __('Причина') }}</th>
                    <th scope="col">{{ __('Статус') }}</th>
                    <th scope="col" class="text-end">{{ __('Действия') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($requests as $r)
                    <tr>
                        <td class="text-secondary crm-table-num">#{{ $r->id }}</td>
                        <td>
                            <div class="crm-table__main">{{ $r->employee?->last_name }} {{ $r->employee?->first_name }}</div>
                            <div class="crm-table__sub">{{ $r->employee?->employee_no ?? '' }}</div>
                        </td>
                        <td class="crm-table-num">{{ $r->date->format('d.m.Y') }}</td>
                        <td class="text-secondary small">{{ \Illuminate\Support\Str::limit($r->reason, 70) }}</td>
                        <td>
                            <span class="crm-chip {{ $tones[$r->status->value] ?? 'crm-chip--grey' }}">{{ $labels[$r->status->value] ?? $r->status->value }}</span>
                            @if ($r->decided_at)
                                <div class="crm-table__sub">{{ $r->decider?->last_name }} · {{ $r->decided_at->format('d.m H:i') }}</div>
                            @endif
                        </td>
                        <td class="text-end">
                            @if ($r->status->value === 'pending')
                                <div class="d-inline-flex gap-1 crm-row-actions">
                                    @if ($canDecide)
                                        <form method="post" action="{{ route('attendance.offsite.approve', $r) }}" class="d-inline" data-submit-guard>
                                            @csrf
                                            <button class="btn btn-sm btn-primary" title="{{ __('Согласовать') }}"><i class="ti ti-check"></i> {{ __('Согласовать') }}</button>
                                        </form>
                                        <form method="post" action="{{ route('attendance.offsite.reject', $r) }}" class="d-inline" data-submit-guard>
                                            @csrf
                                            <input type="hidden" name="decision_comment" value="{{ __('Отклонено руководителем') }}">
                                            <button class="btn btn-sm btn-ghost-danger" title="{{ __('Отклонить') }}"><i class="ti ti-x"></i></button>
                                        </form>
                                    @endif
                                    <form method="post" action="{{ route('attendance.offsite.cancel', $r) }}" class="d-inline" data-submit-guard>
                                        @csrf
                                        <button class="btn btn-sm btn-ghost" title="{{ __('Отозвать') }}"><i class="ti ti-archive"></i></button>
                                    </form>
                                </div>
                            @else
                                <span class="text-secondary">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            @include('partials.empty-state', ['icon' => 'home-bolt', 'title' => __('Заявок нет'), 'text' => __('Подайте заявку, чтобы день был учтён как удалённая работа после согласования.'), 'actionUrl' => '#newOffsite', 'actionLabel' => __('Подать заявку')])
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="modal modal-blur fade" id="newOffsite" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('attendance.offsite.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Заявка на удалённую работу') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section mb-0">
                    <div class="crm-form__desc">{{ __('До согласования день не считается присутствием и не требует биометрии') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="off-date">{{ __('Дата') }}<span class="req">*</span></label>
                            <input type="date" id="off-date" name="date" class="form-control" required value="{{ now()->toDateString() }}">
                        </div>
                        @if ($employees->count() > 1)
                            <div class="crm-form__field">
                                <label class="form-label" for="off-employee">{{ __('Сотрудник') }}</label>
                                <select id="off-employee" name="employee_id" class="form-select">
                                    @foreach ($employees as $employee)
                                        <option value="{{ $employee->id }}" @selected((int) $employee->id === (int) $me->id)>{{ $employee->last_name }} {{ $employee->first_name }} · {{ $employee->employee_no }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">{{ __('За другого сотрудника — только при наличии права') }}</div>
                            </div>
                        @endif
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="off-reason">{{ __('Причина') }}<span class="req">*</span></label>
                        <textarea id="off-reason" name="reason" class="form-control" rows="4" required minlength="3" maxlength="500" placeholder="{{ __('Например: работа по договору вне офиса') }}">{{ old('reason') }}</textarea>
                        <div class="form-text">{{ __('Причина видна руководителю и попадает в журнал аудита') }}</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary"><i class="ti ti-send me-1"></i>{{ __('Отправить') }}</button>
            </div>
        </form>
    </div>
</div>
@endsection
