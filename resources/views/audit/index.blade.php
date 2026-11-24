@extends('layouts.tabler')
@section('workspace_layout', 'stream')

@section('title', __('Журнал действий'))

@php
    $hasFilters = filled($action) || filled(request('user_id')) || filled(request('from')) || filled(request('to'));
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Система') }}</div>
            <h1 class="page-title mt-1">{{ __('Журнал действий') }}</h1>
            <div class="text-secondary mt-1">{{ __('Audit Log: кто, что и когда изменил — со старыми и новыми значениями') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <span class="crm-chip {{ $hasFilters ? 'crm-chip--orange' : 'crm-chip--cyan' }}">
                <i class="ti ti-filter me-1"></i>{{ $hasFilters ? __('фильтр включён') : __('весь журнал') }}
            </span>
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-history"></i>{{ __('Записей') }}</div>
        <div class="crm-metric__value">{{ $logs->total() }}</div>
        <div class="crm-metric__hint">{{ __('с учётом фильтра · страница') }} {{ $logs->currentPage() }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-users"></i>{{ __('Пользователей') }}</div>
        <div class="crm-metric__value">{{ $users->count() }}</div>
        <div class="crm-metric__hint">{{ __('доступно для фильтра') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-code"></i>{{ __('Действий') }}</div>
        <div class="crm-metric__value">{{ $actions->count() }}</div>
        <div class="crm-metric__hint">{{ __('уникальных типов в журнале') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-purple);"><i class="ti ti-calendar"></i>{{ __('Период') }}</div>
        <div class="crm-metric__value" style="font-size: 1.1rem; padding-top: 6px;">
            {{ request('from') ?: __('весь') }} — {{ request('to') ?: __('сегодня') }}
        </div>
        <div class="crm-metric__hint">{{ __('границы выборки') }}</div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <div>
        <label class="form-label" for="audit-action">{{ __('Действие') }}</label>
        <select id="audit-action" name="action" class="form-select" onchange="this.form.submit()">
            <option value="">{{ __('все действия') }}</option>
            @forelse ($actions as $a)
                <option value="{{ $a }}" @selected($action === $a)>{{ \App\Support\EnumLabels::label('audit_action', $a) }}</option>
            @empty
                <option value="">{{ __('(журнал пуст)') }}</option>
            @endforelse
        </select>
    </div>
    <div>
        <label class="form-label" for="audit-user">{{ __('Пользователь') }}</label>
        <select id="audit-user" name="user_id" class="form-select" onchange="this.form.submit()">
            <option value="">{{ __('любой') }}</option>
            @foreach ($users as $u)
                <option value="{{ $u->id }}" @selected($userId == $u->id)>{{ $u->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="form-label" for="audit-from">{{ __('С') }}</label>
        <input type="date" id="audit-from" name="from" value="{{ request('from') }}" class="form-control" onchange="this.form.submit()">
    </div>
    <div>
        <label class="form-label" for="audit-to">{{ __('По') }}</label>
        <input type="date" id="audit-to" name="to" value="{{ request('to') }}" class="form-control" onchange="this.form.submit()">
    </div>
    <div class="crm-toolbar__spacer"></div>
    <span class="crm-toolbar__count">{{ __('Показано') }} {{ $logs->count() }} {{ __('из') }} {{ $logs->total() }}</span>
</form>

{{-- кнопка сброса — отдельным уровнем под фильтрами, чтобы не тянуться к краю --}}
<div class="d-flex justify-content-end mb-3">
    <a href="{{ route('audit.index') }}" class="btn btn-outline-secondary"><i class="ti ti-filter-off me-1"></i>{{ __('Сбросить') }}</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="2" class="table table-hover table-vcenter card-table mb-0 crm-thead-sticky">
            <thead>
                <tr>
                    <th scope="col" style="width: 150px">{{ __('Когда') }}</th>
                    <th scope="col">{{ __('Пользователь') }}</th>
                    <th scope="col">{{ __('Действие') }}</th>
                    <th scope="col">{{ __('Объект') }}</th>
                    <th scope="col" style="width: 320px">{{ __('Изменения') }}</th>
                    <th scope="col" style="width: 130px">IP</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="text-secondary crm-table-num">{{ $log->created_at->format('d.m.Y H:i') }}</td>
                        <td>
                            @if ($log->user)
                                <span class="crm-table__main">{{ $log->user->name }}</span>
                                <div class="crm-table__sub">{{ $log->user->email }}</div>
                            @else
                                <span class="crm-chip crm-chip--grey">{{ __('система') }}</span>
                            @endif
                        </td>
                        <td><span class="crm-chip crm-chip--cyan" title="{{ $log->action }}">{{ \App\Support\EnumLabels::label('audit_action', $log->action) }}</span><div class="text-secondary small mt-1">{{ $log->action }}</div></td>
                        <td class="text-secondary small">
                            @if ($log->object_type !== null)
                                <span class="fw-semibold text-body">{{ $log->object_type }}</span> #{{ $log->object_id }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="small" style="max-width: 320px;">
                            @if ($log->old_value !== null || $log->new_value !== null)
                                <code class="small" style="display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ \Illuminate\Support\Str::limit(json_encode($log->old_value ?? [], JSON_UNESCAPED_UNICODE), 48) }} → {{ \Illuminate\Support\Str::limit(json_encode($log->new_value ?? [], JSON_UNESCAPED_UNICODE), 48) }}</code>
                            @else
                                <span class="text-secondary">—</span>
                            @endif
                        </td>
                        <td class="text-secondary small crm-table-num">{{ $log->ip ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            @include('partials.empty-state', ['icon' => 'search', 'title' => __('Записей нет'), 'text' => $hasFilters ? __('По выбранным фильтрам записей не найдено — снимите фильтр или расширьте период.') : __('Журнал пуст: действия появятся после первых изменений в системе.')])
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($logs->hasPages())
        <div class="card-footer">{{ $logs->links() }}</div>
    @endif
</div>
@endsection
