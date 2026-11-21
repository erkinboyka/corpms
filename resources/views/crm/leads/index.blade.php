@extends('layouts.tabler')
@section('workspace_layout', 'directory')

@section('title', __('CRM — Лиды'))

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">CRM</div>
            <h1 class="page-title mt-1">{{ __('Лиды') }}</h1>
            <div class="text-secondary mt-1">{{ __('Входящий интерес · в работе') }} {{ $metrics['leads_open'] }}</div>
        </div>
        <div class="col-auto d-print-none">


            @can('checkBoxPermission', 'crm.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newLead"><i class="ti ti-plus me-1"></i>{{ __('Новый лид') }}</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-user-search"></i>{{ __('В работе') }}</div>
        <div class="crm-metric__value">{{ $metrics['leads_open'] }}</div>
        <div class="crm-metric__hint">{{ __('новые и квалифицированные') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-address-book"></i>{{ __('Клиенты') }}</div>
        <div class="crm-metric__value">{{ $metrics['clients_active'] }}</div>
        <div class="crm-metric__hint">{{ __('активных') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-purple);"><i class="ti ti-chart-funnel"></i>{{ __('Сделки') }}</div>
        <div class="crm-metric__value">{{ $metrics['deals_open'] }}</div>
        <div class="crm-metric__hint">{{ __('на') }} {{ number_format((float) $metrics['deals_amount'], 0, ',', ' ') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-calendar-event"></i>{{ __('Просрочено') }}</div>
        <div class="crm-metric__value">{{ $metrics['activities_overdue'] }}</div>
        <div class="crm-metric__hint">{{ __('активностей') }}</div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <div class="input-icon">
        <input type="search" name="q" class="form-control crm-search-input" value="{{ $search }}" placeholder="{{ __("Название, контакт, телефон…") }}" aria-label="{{ __("Поиск лидов") }}">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <select name="status" class="form-select" aria-label="{{ __("Статус") }}" onchange="this.form.submit()">
        <option value="open" @selected($status === 'open')>{{ __('В работе') }} ({{ $metrics['leads_open'] }})</option>
        <option value="new" @selected($status === 'new')>{{ __('Новые') }} ({{ $counts['new'] ?? 0 }})</option>
        <option value="qualified" @selected($status === 'qualified')>{{ __('Квалифицированы') }} ({{ $counts['qualified'] ?? 0 }})</option>
        <option value="converted" @selected($status === 'converted')>{{ __('Конвертированы') }} ({{ $counts['converted'] ?? 0 }})</option>
        <option value="lost" @selected($status === 'lost')>{{ __('Потеряны') }} ({{ $counts['lost'] ?? 0 }})</option>
        <option value="all" @selected($status === 'all')>{{ __('Все') }}</option>
    </select>
    <select name="source" class="form-select" aria-label="{{ __("Источник") }}" onchange="this.form.submit()">
        <option value="">{{ __('Любой источник') }}</option>
        @foreach ($sources as $sourceOption)
            <option value="{{ $sourceOption->value }}" @selected($source === $sourceOption->value)>{{ $crm_label('crm_lead_source', $sourceOption->value) }}</option>
        @endforeach
    </select>
    <button class="btn btn-ghost-secondary"><i class="ti ti-search me-1"></i>{{ __('Найти') }}</button>
</form>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('Лид') }}</th>
                    <th scope="col">{{ __('Источник') }}</th>
                    <th scope="col" class="text-end">{{ __('Сумма') }}</th>
                    <th scope="col">{{ __('Статус') }}</th>
                    <th scope="col">{{ __('Ответственный') }}</th>
                    <th scope="col" class="text-end">{{ __('Действия') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($leads as $lead)
                    <tr>
                        <td>
                            <div class="crm-table__main">{{ $lead->title }}</div>
                            <div class="crm-table__sub">
                                {{ $lead->contact_name ?: __('контакт не указан') }}@if ($lead->phone) · {{ $lead->phone }}@endif
                                @if ($lead->client) · {{ __('клиент') }}: {{ $lead->client->name }}@endif
                            </div>
                        </td>
                        <td><span class="crm-chip crm-chip--grey">{{ $crm_label('crm_lead_source', $lead->source->value) }}</span></td>
                        <td class="text-end">{{ number_format((float) $lead->estimated_amount, 0, ',', ' ') }}</td>
                        <td>
                            @php
                                $leadChip = match ($lead->status->value) {
                                    'new' => 'crm-chip',
                                    'qualified' => 'crm-chip--cyan',
                                    'converted' => 'crm-chip--green',
                                    default => 'crm-chip--red',
                                };
                            @endphp
                            <span class="crm-chip {{ $leadChip }}">{{ $crm_label('crm_lead_status', $lead->status->value) }}</span>
                        </td>
                        <td class="text-secondary">{{ $lead->owner ? $lead->owner->last_name.' '.$lead->owner->first_name : '—' }}</td>
                        <td class="text-end">
                            @if ($lead->status->isOpen())
                                @can('checkBoxPermission', 'crm.manage')
                                @if ($lead->status->value === 'new')
                                    <form method="post" action="{{ route('crm.leads.qualify', $lead) }}" class="d-inline">
                                        @csrf
                                        <button class="btn btn-sm btn-ghost-secondary" title="{{ __("Квалифицировать") }}"><i class="ti ti-check"></i></button>
                                    </form>
                                @endif
                                <form method="post" action="{{ route('crm.leads.lose', $lead) }}" class="d-inline"
                                      onsubmit="return confirm('{{ __('Отметить лид') }} «{{ $lead->title }}» {{ __('потерянным') }}?')">
                                    @csrf
                                    <button class="btn btn-sm btn-ghost-danger" title="{{ __("Потерян") }}"><i class="ti ti-x"></i></button>
                                </form>
                                @endcan
                                @can('checkBoxPermission', 'crm.deals.manage')
                                <form method="post" action="{{ route('crm.leads.convert', $lead) }}" class="d-inline"
                                      onsubmit="return confirm('{{ __('Сконвертировать лид') }} «{{ $lead->title }}» {{ __('в клиента и сделку') }}?')">
                                    @csrf
                                    <button class="btn btn-sm btn-primary" title="{{ __("Конвертировать в сделку") }}"><i class="ti ti-arrow-right me-1"></i>{{ __('Сделка') }}</button>
                                </form>
                                @endcan
                            @else
                                <span class="text-secondary small">{{ __('закрыт') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">@include('partials.empty-state', ['icon' => 'user-search', 'title' => __('Лидов нет'), 'text' => __('Зафиксируйте входящий интерес: сайт, звонок, рекомендация или мероприятие.')])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($leads->hasPages())
        <div class="card-footer">{{ $leads->links('pagination::tabler') }}</div>
    @endif
</div>

@can('checkBoxPermission', 'crm.manage')
<div class="modal modal-blur fade" id="newLead" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('crm.leads.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Новый лид') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Лид') }}</h3>
                    <div class="crm-form__desc">{{ __('Откуда пришёл интерес и кто ведёт — всё видно в списке') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="nl-title">{{ __('Название') }}<span class="req">*</span></label>
                            <input type="text" id="nl-title" name="title" class="form-control" value="{{ old('title') }}" placeholder="{{ __("Интерес к внедрению CRM") }}" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nl-source">{{ __('Источник') }}<span class="req">*</span></label>
                            <select id="nl-source" name="source" class="form-select" required>
                                @foreach ($sources as $sourceOption)
                                    <option value="{{ $sourceOption->value }}" @selected(old('source', 'web') === $sourceOption->value)>{{ $crm_label('crm_lead_source', $sourceOption->value) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nl-owner">{{ __('Ответственный') }}</label>
                            <select id="nl-owner" name="owner_id" class="form-select">
                                <option value="">{{ __('не назначен') }}</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}" @selected((string) old('owner_id') === (string) $employee->id)>{{ $employee->last_name }} {{ $employee->first_name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>{{ __('Контакты') }}</h3>
                    <div class="crm-form__desc">{{ __('Кому звоним и куда пишем — в одной строке') }}</div>
                    <div class="crm-form__row crm-form__row--3">
                        <div class="crm-form__field">
                            <label class="form-label" for="nl-contact">{{ __('Контактное лицо') }}</label>
                            <input type="text" id="nl-contact" name="contact_name" class="form-control" value="{{ old('contact_name') }}">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nl-phone">{{ __('Телефон') }}</label>
                            <input type="text" id="nl-phone" name="phone" class="form-control" value="{{ old('phone') }}" maxlength="32">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nl-email">E-mail</label>
                            <input type="email" id="nl-email" name="email" class="form-control" value="{{ old('email') }}">
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Оценка и примечание') }}</h3>
                    <div class="crm-form__desc">{{ __('Потенциальная сумма и заметка о первом контакте') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="nl-amount">{{ __('Ожидаемая сумма') }}</label>
                            <input type="number" id="nl-amount" name="estimated_amount" class="form-control" value="{{ old('estimated_amount', 0) }}" step="0.01" min="0">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nl-notes">{{ __('Примечание') }}</label>
                            <input type="text" id="nl-notes" name="notes" class="form-control" value="{{ old('notes') }}" maxlength="2000">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Создать лид') }}</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
