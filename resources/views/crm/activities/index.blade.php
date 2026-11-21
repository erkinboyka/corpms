@extends('layouts.tabler')
@section('workspace_layout', 'stream')

@section('title', __('CRM — Активности'))

@php
    $tabs = [
        'today' => __('Сегодня'),
        'upcoming' => __('Предстоящие'),
        'overdue' => __('Просроченные'),
        'done' => __('Выполненные'),
        'all' => __('Все'),
    ];
@endphp

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">CRM</div>
            <h1 class="page-title mt-1">{{ __('Активности') }}</h1>
            <div class="text-secondary mt-1">{{ __('В работе') }} {{ $metrics['activities_pending'] }} {{ __('просрочено') }} {{ $metrics['activities_overdue'] }}</div>
        </div>
        <div class="col-auto d-print-none">


            @can('checkBoxPermission', 'crm.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newActivity"><i class="ti ti-plus me-1"></i>{{ __('Новая активность') }}</button>
            @endcan
        </div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <input type="hidden" name="tab" value="{{ $tab }}">
    <div class="crm-switch" role="group" aria-label="{{ __("Период активностей") }}">
        @foreach ($tabs as $tabKey => $tabLabel)
            <button type="submit" name="tab" value="{{ $tabKey }}" class="{{ $tab === $tabKey ? 'is-active' : '' }}">{{ $tabLabel }}</button>
        @endforeach
    </div>
    <div class="crm-toolbar__spacer"></div>
    <select name="kind" class="form-select" aria-label="{{ __("Вид активности") }}" onchange="this.form.submit()">
        <option value="">{{ __('Все виды') }}</option>
        @foreach ($kinds as $kindOption)
            <option value="{{ $kindOption->value }}" @selected($kind === $kindOption->value)>{{ $crm_label('crm_activity_kind', $kindOption->value) }}</option>
        @endforeach
    </select>
</form>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('Активность') }}</th>
                    <th scope="col">{{ __('Связь') }}</th>
                    <th scope="col">{{ __('Срок') }}</th>
                    <th scope="col">{{ __('Ответственный') }}</th>
                    <th scope="col">{{ __('Статус') }}</th>
                    <th scope="col" class="text-end">{{ __('Действия') }}</th>
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
                                <span class="crm-chip crm-chip--green">{{ __('выполнена') }}</span>
                            @elseif ($activity->isOverdue())
                                <span class="crm-chip crm-chip--red">{{ __('просрочена') }}</span>
                            @else
                                <span class="crm-chip">{{ __('в работе') }}</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @can('checkBoxPermission', 'crm.manage')
                            @if ($activity->isCompleted())
                                <form method="post" action="{{ route('crm.activities.reopen', $activity) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-ghost-secondary" title="{{ __("Вернуть в работу") }}"><i class="ti ti-rotate"></i></button>
                                </form>
                            @else
                                <form method="post" action="{{ route('crm.activities.complete', $activity) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-ghost-secondary" title="{{ __("Выполнено") }}"><i class="ti ti-check"></i></button>
                                </form>
                            @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">@include('partials.empty-state', ['icon' => 'calendar-event', 'title' => __('Активностей нет'), 'text' => __('Запланируйте звонок, встречу или письмо по сделке, лиду или клиенту.')])</td></tr>
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
            <div class="modal-header"><h5 class="modal-title">{{ __('Новая активность') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Активность') }}</h3>
                    <div class="crm-form__desc">{{ __('Что делаем и на каком объекте — вид и тема задают тон истории') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="na-kind">{{ __('Вид') }}<span class="req">*</span></label>
                            <select id="na-kind" name="kind" class="form-select" required>
                                @foreach ($kinds as $kindOption)
                                    <option value="{{ $kindOption->value }}" @selected(old('kind', 'task') === $kindOption->value)>{{ $crm_label('crm_activity_kind', $kindOption->value) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="na-subject">{{ __('Тема') }}<span class="req">*</span></label>
                            <input type="text" id="na-subject" name="subject" class="form-control" value="{{ old('subject') }}" placeholder="{{ __("Позвонить по КП") }}" required>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>{{ __('Сроки и ответственные') }}</h3>
                    <div class="crm-form__desc">{{ __('Срок и ответственный в одной строке — видно, кто и когда должен отработать') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="na-due">{{ __('Срок') }}</label>
                            <input type="datetime-local" id="na-due" name="due_at" class="form-control" value="{{ old('due_at') }}">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="na-owner">{{ __('Ответственный') }}</label>
                            <select id="na-owner" name="owner_id" class="form-select">
                                <option value="">{{ __('не назначен') }}</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}" @selected((string) old('owner_id') === (string) $employee->id)>{{ $employee->last_name }} {{ $employee->first_name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>{{ __('Связи') }}</h3>
                    <div class="crm-form__desc">{{ __('Свяжите активность хотя бы с одним объектом CRM — она попадёт в его историю') }}</div>
                    <div class="crm-form__row crm-form__row--3">
                        <div class="crm-form__field">
                            <label class="form-label" for="na-deal">{{ __('Сделка') }}</label>
                            <select id="na-deal" name="deal_id" class="form-select">
                                <option value="">{{ __('нет') }}</option>
                                @foreach ($deals as $deal)
                                    <option value="{{ $deal->id }}" @selected((string) old('deal_id') === (string) $deal->id)>№{{ $deal->id }} · {{ $deal->title }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="na-client">{{ __('Клиент') }}</label>
                            <select id="na-client" name="client_id" class="form-select">
                                <option value="">{{ __('нет') }}</option>
                                @foreach ($clients as $client)
                                    <option value="{{ $client->id }}" @selected((string) old('client_id') === (string) $client->id)>{{ $client->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="na-lead">{{ __('Лид') }}</label>
                            <select id="na-lead" name="lead_id" class="form-select">
                                <option value="">{{ __('нет') }}</option>
                                @foreach ($leads as $lead)
                                    <option value="{{ $lead->id }}" @selected((string) old('lead_id') === (string) $lead->id)>{{ $lead->title }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Примечание') }}</h3>
                    <div class="crm-form__desc">{{ __('Короткая заметка о договорённости или результате звонка') }}</div>
                    <div class="crm-form__field mb-0">
                        <input type="text" id="na-notes" name="notes" class="form-control" value="{{ old('notes') }}" maxlength="2000" placeholder="{{ __("Условия, договорённости, следующий шаг") }}">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Добавить') }}</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
