@extends('layouts.tabler')

@section('title', 'CRM — Активности')

@php
    $tabs = [
        'today' => 'Сегодня',
        'upcoming' => 'Предстоящие',
        'overdue' => 'Просроченные',
        'done' => 'Выполненные',
        'all' => 'Все',
    ];
@endphp

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">CRM</div>
            <h2 class="page-title mt-1">Активности</h2>
            <div class="text-secondary mt-1">В работе {{ $metrics['activities_pending'] }} · просрочено {{ $metrics['activities_overdue'] }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('crm.clients.index') }}"><i class="ti ti-address-book me-1"></i>Клиенты</a>
            <a class="btn btn-ghost-secondary me-1" href="{{ route('crm.deals.index') }}"><i class="ti ti-chart-funnel me-1"></i>Сделки</a>
            @can('checkBoxPermission', 'crm.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newActivity"><i class="ti ti-plus me-1"></i>Новая активность</button>
            @endcan
        </div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <input type="hidden" name="tab" value="{{ $tab }}">
    <div class="crm-switch" role="group" aria-label="Период активностей">
        @foreach ($tabs as $tabKey => $tabLabel)
            <button type="submit" name="tab" value="{{ $tabKey }}" class="{{ $tab === $tabKey ? 'is-active' : '' }}">{{ $tabLabel }}</button>
        @endforeach
    </div>
    <div class="crm-toolbar__spacer"></div>
    <select name="kind" class="form-select" aria-label="Вид активности" onchange="this.form.submit()">
        <option value="">Все виды</option>
        @foreach ($kinds as $kindOption)
            <option value="{{ $kindOption->value }}" @selected($kind === $kindOption->value)>{{ $crm_label('crm_activity_kind', $kindOption->value) }}</option>
        @endforeach
    </select>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th>Активность</th>
                    <th>Связь</th>
                    <th>Срок</th>
                    <th>Ответственный</th>
                    <th>Статус</th>
                    <th class="text-end">Действия</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($activities as $activity)
                    <tr>
                        <td>
                            <div class="crm-table__main">{{ $activity->subject }}</div>
                            <div class="crm-table__sub">{{ $crm_label('crm_activity_kind', $activity->kind->value) }}@if ($activity->notes) · {{ \Illuminate\Support\Str::limit($activity->notes, 50) }}@endif</div>
                        </td>
                        <td class="text-secondary small">
                            @if ($activity->deal)
                                <i class="ti ti-chart-funnel me-1"></i>{{ $activity->deal->title }}
                            @elseif ($activity->lead)
                                <i class="ti ti-user-search me-1"></i>{{ $activity->lead->title }}
                            @elseif ($activity->client)
                                <i class="ti ti-address-book me-1"></i>{{ $activity->client->name }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="{{ $activity->isOverdue() ? 'text-danger fw-semibold' : 'text-secondary' }}">
                            {{ $activity->due_at?->format('d.m.Y H:i') ?? '—' }}
                        </td>
                        <td class="text-secondary">{{ $activity->owner ? $activity->owner->last_name.' '.$activity->owner->first_name : '—' }}</td>
                        <td>
                            @if ($activity->isCompleted())
                                <span class="crm-chip crm-chip--green">выполнена</span>
                            @elseif ($activity->isOverdue())
                                <span class="crm-chip crm-chip--red">просрочена</span>
                            @else
                                <span class="crm-chip">в работе</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @can('checkBoxPermission', 'crm.manage')
                            @if ($activity->isCompleted())
                                <form method="post" action="{{ route('crm.activities.reopen', $activity) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-ghost-secondary" title="Вернуть в работу"><i class="ti ti-rotate"></i></button>
                                </form>
                            @else
                                <form method="post" action="{{ route('crm.activities.complete', $activity) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-ghost-secondary" title="Выполнено"><i class="ti ti-check"></i></button>
                                </form>
                            @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">@include('partials.empty-state', ['icon' => 'calendar-event', 'title' => 'Активностей нет', 'text' => 'Запланируйте звонок, встречу или письмо по сделке, лиду или клиенту.'])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($activities->hasPages())
        <div class="card-footer">{{ $activities->links('pagination::tabler') }}</div>
    @endif
</div>

@can('checkBoxPermission', 'crm.manage')
<div class="modal modal-blur fade" id="newActivity" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('crm.activities.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новая активность</h5></div>
            <div class="modal-body">
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="na-kind">Вид<span class="req">*</span></label>
                        <select id="na-kind" name="kind" class="form-select" required>
                            @foreach ($kinds as $kindOption)
                                <option value="{{ $kindOption->value }}" @selected(old('kind', 'task') === $kindOption->value)>{{ $crm_label('crm_activity_kind', $kindOption->value) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="na-subject">Тема<span class="req">*</span></label>
                        <input type="text" id="na-subject" name="subject" class="form-control" value="{{ old('subject') }}" placeholder="Позвонить по КП" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="na-due">Срок</label>
                        <input type="datetime-local" id="na-due" name="due_at" class="form-control" value="{{ old('due_at') }}">
                    </div>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="na-deal">Сделка</label>
                        <select id="na-deal" name="deal_id" class="form-select">
                            <option value="">— нет —</option>
                            @foreach ($deals as $deal)
                                <option value="{{ $deal->id }}" @selected((string) old('deal_id') === (string) $deal->id)>№{{ $deal->id }} · {{ $deal->title }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="na-client">Клиент</label>
                        <select id="na-client" name="client_id" class="form-select">
                            <option value="">— нет —</option>
                            @foreach ($clients as $client)
                                <option value="{{ $client->id }}" @selected((string) old('client_id') === (string) $client->id)>{{ $client->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="na-lead">Лид</label>
                        <select id="na-lead" name="lead_id" class="form-select">
                            <option value="">— нет —</option>
                            @foreach ($leads as $lead)
                                <option value="{{ $lead->id }}" @selected((string) old('lead_id') === (string) $lead->id)>{{ $lead->title }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="na-owner">Ответственный</label>
                        <select id="na-owner" name="owner_id" class="form-select">
                            <option value="">— не назначен —</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected((string) old('owner_id') === (string) $employee->id)>{{ $employee->last_name }} {{ $employee->first_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="na-notes">Примечание</label>
                        <input type="text" id="na-notes" name="notes" class="form-control" value="{{ old('notes') }}" maxlength="2000">
                    </div>
                </div>
                <div class="form-text">Свяжите активность хотя бы с одним объектом CRM, чтобы она попала в историю клиента или сделки.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Добавить</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
