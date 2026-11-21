@extends('layouts.tabler')
@section('workspace_layout', 'stream')

@section('title', __('Отпуска и заявки'))

@php
    $statusChips = [
        'pending' => 'crm-chip--orange',
        'approved' => 'crm-chip--green',
        'rejected' => 'crm-chip--red',
        'cancelled' => 'crm-chip--grey',
    ];
    $typeIcons = [
        'vacation' => 'sun',
        'sick' => 'heartbeat',
        'business_leave' => 'briefcase',
        'day_off' => 'coffee',
        'other' => 'dots',
    ];

    $pending = $canApprove ? $leaves->getCollection()->filter(fn ($l) => $l->status->value === 'pending') : collect();
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Кадры') }}</div>
            <h1 class="page-title mt-1">{{ __('Отпуска и заявки') }}</h1>
            <div class="text-secondary mt-1">{{ __('Заявки на отпуск, больничные и отгулы · всего') }} {{ $leaves->total() }}</div>
        </div>
        <div class="col-auto d-print-none">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newLeave">
                <i class="ti ti-plus me-1"></i>{{ __('Отправить заявку') }}
            </button>
        </div>
    </div>
</div>

<div class="crm-metrics mb-3">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-text-secondary);"><i class="ti ti-file-text"></i>{{ __('Всего заявок') }}</div>
        <div class="crm-metric__value">{{ $counts->sum() }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-hourglass"></i>{{ __('Ждут решения') }}</div>
        <div class="crm-metric__value">{{ $counts['pending'] ?? 0 }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-circle-check"></i>{{ __('Одобрено') }}</div>
        <div class="crm-metric__value">{{ $counts['approved'] ?? 0 }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-danger);"><i class="ti ti-circle-x"></i>{{ __('Отклонено') }}</div>
        <div class="crm-metric__value">{{ $counts['rejected'] ?? 0 }}</div>
    </div>
</div>

{{-- ===== Очередь согласования ===== --}}
@if ($canApprove && $pending->isNotEmpty())
    <div class="crm-form__section">
        <h3>{{ __('Очередь согласования') }} <span class="crm-chip crm-chip--orange ms-1">{{ $pending->count() }}</span></h3>
        <div class="crm-form__desc">{{ __('Заявки, ждущие вашего решения. Решение попадает в историю') }}</div>
        <div class="crm-queue">
            @foreach ($pending as $leave)
                <div class="crm-queue__item" style="--c: var(--crm-warning);">
                    <span class="crm-entity__logo" style="width:38px;height:38px;font-size:15px;border-radius:11px;background:color-mix(in srgb, var(--crm-warning) 12%, transparent);color:var(--crm-warning);">
                        <i class="ti ti-{{ $typeIcons[$leave->type->value] ?? 'dots' }}"></i>
                    </span>
                    <div class="crm-queue__body">
                        <div class="crm-queue__title">
                            {{ $leave->employee->last_name }} {{ $leave->employee->first_name }}
                            <span class="crm-chip crm-chip--grey ms-1">{{ $crm_label('leave_type', $leave->type->value) }}</span>
                        </div>
                        <div class="crm-queue__sub">
                            {{ $leave->from_date->format('d.m.Y') }} — {{ $leave->to_date->format('d.m.Y') }}
                            · {{ (int) $leave->from_date->diffInDays($leave->to_date) + 1 }} {{ __('дн') }}.
                            @if ($leave->reason) · «{{ Str::limit($leave->reason, 60) }}»@endif
                        </div>
                    </div>
                    <form method="post" action="{{ route('leaves.decide', $leave) }}" class="d-flex gap-1" data-submit-guard>
                        @csrf @method('PUT')
                        <input type="hidden" name="decision" value="approved">
                        <button class="btn btn-sm btn-success" title="{{ __("Одобрить") }}"><i class="ti ti-check me-1"></i>{{ __('Одобрить') }}</button>
                    </form>
                    <form method="post" action="{{ route('leaves.decide', $leave) }}" class="d-flex" data-submit-guard>
                        @csrf @method('PUT')
                        <input type="hidden" name="decision" value="rejected">
                        <button class="btn btn-sm btn-ghost-danger" title="{{ __("Отклонить") }}"><i class="ti ti-x"></i></button>
                    </form>
                </div>
            @endforeach
        </div>
    </div>
@endif

{{-- ===== Все заявки ===== --}}
<form method="get" class="crm-toolbar">
    <select name="status" class="form-select" aria-label="{{ __("Статус") }}" onchange="this.form.submit()">
        <option value="">{{ __('Все статусы') }}</option>
        @foreach (['pending', 'approved', 'rejected', 'cancelled'] as $statusOption)
            <option value="{{ $statusOption }}" @selected($statusFilter === $statusOption)>{{ $crm_label('leave_status', $statusOption) }}</option>
        @endforeach
    </select>
    <div class="crm-toolbar__spacer"></div>
    <span class="text-secondary small">{{ __('Показано') }} {{ $leaves->count() }} {{ __('из') }} {{ $leaves->total() }}</span>
</form>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table crm-thead-sticky">
            <thead><tr><th scope="col">{{ __('Сотрудник') }}</th><th scope="col">{{ __('Тип') }}</th><th scope="col">{{ __('Период') }}</th><th scope="col">{{ __('Дней') }}</th><th scope="col">{{ __('Статус') }}</th><th scope="col">{{ __('Решение') }}</th><th scope="col"></th></tr></thead>
            <tbody>
                @forelse ($leaves as $leave)
                    <tr>
                        <td>
                            <span class="crm-table__main">{{ $leave->employee->last_name }} {{ $leave->employee->first_name }}</span>
                            <div class="crm-table__sub">{{ $leave->employee->employee_no ?? '' }}</div>
                        </td>
                        <td><span class="crm-chip crm-chip--cyan"><i class="ti ti-{{ $typeIcons[$leave->type->value] ?? 'dots' }} me-1"></i>{{ $crm_label('leave_type', $leave->type->value) }}</span></td>
                        <td class="crm-table-num">{{ $leave->from_date->format('d.m.Y') }} — {{ $leave->to_date->format('d.m.Y') }}</td>
                        <td class="crm-table-num">{{ (int) $leave->from_date->diffInDays($leave->to_date) + 1 }}</td>
                        <td><span class="crm-chip {{ $statusChips[$leave->status->value] ?? 'crm-chip--grey' }}">{{ $crm_label('leave_status', $leave->status->value) }}</span></td>
                        <td class="text-secondary">
                            {{ $leave->approver?->last_name ?? '—' }}
                            @if ($leave->decided_at)<div class="crm-table__sub">{{ $leave->decided_at->format('d.m H:i') }}</div>@endif
                        </td>
                        <td class="text-end">
                            @if ($canApprove && $leave->status->value === 'pending')
                                <form method="post" action="{{ route('leaves.decide', $leave) }}" class="d-inline" data-submit-guard>
                                    @csrf @method('PUT')
                                    <input type="hidden" name="decision" value="approved">
                                    <button class="btn btn-sm btn-ghost-success" title="{{ __("Одобрить") }}"><i class="ti ti-check"></i></button>
                                </form>
                                <form method="post" action="{{ route('leaves.decide', $leave) }}" class="d-inline" data-submit-guard>
                                    @csrf @method('PUT')
                                    <input type="hidden" name="decision" value="rejected">
                                    <button class="btn btn-sm btn-ghost-danger" title="{{ __("Отклонить") }}"><i class="ti ti-x"></i></button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">@include('partials.empty-state', ['icon' => 'calendar-off', 'title' => __('Заявок нет'), 'text' => __('Отправьте заявку — она появится здесь и уйдёт руководителю.')])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($leaves->hasPages())
        <div class="card-footer d-flex align-items-center">
            <div class="text-secondary me-auto">{{ __('Страница') }} {{ $leaves->currentPage() }} {{ __('из') }} {{ $leaves->lastPage() }}</div>
            {{ $leaves->links('pagination::tabler') }}
        </div>
    @endif
</div>

{{-- ===== Новая заявка ===== --}}
<div class="modal modal-blur fade" id="newLeave" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('leaves.request') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Новая заявка') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Период') }}</h3>
                    <div class="crm-form__desc">{{ __('Даты начала и конца — включительно') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="nl-from">{{ __('С') }}<span class="req">*</span></label>
                            <input type="date" id="nl-from" class="form-control" name="from_date" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nl-to">{{ __('По') }}<span class="req">*</span></label>
                            <input type="date" id="nl-to" class="form-control" name="to_date" required>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Суть заявки') }}</h3>
                    <div class="crm-form__desc">{{ __('Тип отсутствия и пояснение для руководителя') }}</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nl-type">{{ __('Тип') }}<span class="req">*</span></label>
                        <select id="nl-type" class="form-select" name="type" required>
                            @foreach ($types as $type)
                                <option value="{{ $type }}">{{ $crm_label('leave_type', $type) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="nl-reason">{{ __('Комментарий') }}</label>
                        <textarea id="nl-reason" class="form-control" name="reason" rows="4" data-crm-rich placeholder="{{ __("Пояснение к заявке") }}"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary"><i class="ti ti-send me-1"></i>{{ __('Отправить заявку') }}</button>
            </div>
        </form>
    </div>
</div>
@endsection
