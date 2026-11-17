@extends('layouts.tabler')

@section('title', 'CRM — Лиды')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">CRM</div>
            <h2 class="page-title mt-1">Лиды</h2>
            <div class="text-secondary mt-1">Входящий интерес · в работе {{ $metrics['leads_open'] }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('crm.clients.index') }}"><i class="ti ti-address-book me-1"></i>Клиенты</a>
            <a class="btn btn-ghost-secondary me-1" href="{{ route('crm.deals.index') }}"><i class="ti ti-chart-funnel me-1"></i>Сделки</a>
            @can('checkBoxPermission', 'crm.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newLead"><i class="ti ti-plus me-1"></i>Новый лид</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #4299e1;"><i class="ti ti-user-search"></i>В работе</div>
        <div class="crm-metric__value">{{ $metrics['leads_open'] }}</div>
        <div class="crm-metric__hint">новые и квалифицированные</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #0b9b72;"><i class="ti ti-address-book"></i>Клиенты</div>
        <div class="crm-metric__value">{{ $metrics['clients_active'] }}</div>
        <div class="crm-metric__hint">активных</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #ae3ec9;"><i class="ti ti-chart-funnel"></i>Сделки</div>
        <div class="crm-metric__value">{{ $metrics['deals_open'] }}</div>
        <div class="crm-metric__hint">на {{ number_format((float) $metrics['deals_amount'], 0, ',', ' ') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #f76707;"><i class="ti ti-calendar-event"></i>Просрочено</div>
        <div class="crm-metric__value">{{ $metrics['activities_overdue'] }}</div>
        <div class="crm-metric__hint">активностей</div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <div class="input-icon">
        <input type="search" name="q" class="form-control crm-search-input" value="{{ $search }}" placeholder="Название, контакт, телефон…" aria-label="Поиск лидов">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <select name="status" class="form-select" aria-label="Статус" onchange="this.form.submit()">
        <option value="open" @selected($status === 'open')>В работе ({{ $metrics['leads_open'] }})</option>
        <option value="new" @selected($status === 'new')>Новые ({{ $counts['new'] ?? 0 }})</option>
        <option value="qualified" @selected($status === 'qualified')>Квалифицированы ({{ $counts['qualified'] ?? 0 }})</option>
        <option value="converted" @selected($status === 'converted')>Конвертированы ({{ $counts['converted'] ?? 0 }})</option>
        <option value="lost" @selected($status === 'lost')>Потеряны ({{ $counts['lost'] ?? 0 }})</option>
        <option value="all" @selected($status === 'all')>Все</option>
    </select>
    <select name="source" class="form-select" aria-label="Источник" onchange="this.form.submit()">
        <option value="">Любой источник</option>
        @foreach ($sources as $sourceOption)
            <option value="{{ $sourceOption->value }}" @selected($source === $sourceOption->value)>{{ $crm_label('crm_lead_source', $sourceOption->value) }}</option>
        @endforeach
    </select>
    <button class="btn btn-ghost-secondary"><i class="ti ti-search me-1"></i>Найти</button>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th>Лид</th>
                    <th>Источник</th>
                    <th class="text-end">Сумма</th>
                    <th>Статус</th>
                    <th>Ответственный</th>
                    <th class="text-end">Действия</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($leads as $lead)
                    <tr>
                        <td>
                            <div class="crm-table__main">{{ $lead->title }}</div>
                            <div class="crm-table__sub">
                                {{ $lead->contact_name ?: 'контакт не указан' }}@if ($lead->phone) · {{ $lead->phone }}@endif
                                @if ($lead->client) · клиент: {{ $lead->client->name }}@endif
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
                                        <button class="btn btn-sm btn-ghost-secondary" title="Квалифицировать"><i class="ti ti-check"></i></button>
                                    </form>
                                @endif
                                <form method="post" action="{{ route('crm.leads.lose', $lead) }}" class="d-inline"
                                      onsubmit="return confirm('Отметить лид «{{ $lead->title }}» потерянным?')">
                                    @csrf
                                    <button class="btn btn-sm btn-ghost-danger" title="Потерян"><i class="ti ti-x"></i></button>
                                </form>
                                @endcan
                                @can('checkBoxPermission', 'crm.deals.manage')
                                <form method="post" action="{{ route('crm.leads.convert', $lead) }}" class="d-inline"
                                      onsubmit="return confirm('Сконвертировать лид «{{ $lead->title }}» в клиента и сделку?')">
                                    @csrf
                                    <button class="btn btn-sm btn-primary" title="Конвертировать в сделку"><i class="ti ti-arrow-right me-1"></i>Сделка</button>
                                </form>
                                @endcan
                            @else
                                <span class="text-secondary small">закрыт</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">@include('partials.empty-state', ['icon' => 'user-search', 'title' => 'Лидов нет', 'text' => 'Зафиксируйте входящий интерес: сайт, звонок, рекомендация или мероприятие.'])</td></tr>
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
            <div class="modal-header"><h5 class="modal-title">Новый лид</h5></div>
            <div class="modal-body">
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="nl-title">Название<span class="req">*</span></label>
                        <input type="text" id="nl-title" name="title" class="form-control" value="{{ old('title') }}" placeholder="Интерес к внедрению CRM" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nl-source">Источник<span class="req">*</span></label>
                        <select id="nl-source" name="source" class="form-select" required>
                            @foreach ($sources as $sourceOption)
                                <option value="{{ $sourceOption->value }}" @selected(old('source', 'web') === $sourceOption->value)>{{ $crm_label('crm_lead_source', $sourceOption->value) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nl-owner">Ответственный</label>
                        <select id="nl-owner" name="owner_id" class="form-select">
                            <option value="">— не назначен —</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected((string) old('owner_id') === (string) $employee->id)>{{ $employee->last_name }} {{ $employee->first_name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="nl-contact">Контактное лицо</label>
                        <input type="text" id="nl-contact" name="contact_name" class="form-control" value="{{ old('contact_name') }}">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nl-phone">Телефон</label>
                        <input type="text" id="nl-phone" name="phone" class="form-control" value="{{ old('phone') }}" maxlength="32">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nl-email">E-mail</label>
                        <input type="email" id="nl-email" name="email" class="form-control" value="{{ old('email') }}">
                    </div>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="nl-amount">Ожидаемая сумма</label>
                        <input type="number" id="nl-amount" name="estimated_amount" class="form-control" value="{{ old('estimated_amount', 0) }}" step="0.01" min="0">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nl-notes">Примечание</label>
                        <input type="text" id="nl-notes" name="notes" class="form-control" value="{{ old('notes') }}" maxlength="2000">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Создать лид</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
