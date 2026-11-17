@extends('layouts.tabler')

@section('title', 'CRM — Клиенты')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">CRM</div>
            <h2 class="page-title mt-1">Клиенты</h2>
            <div class="text-secondary mt-1">Всего {{ $clients->total() }} · показаны {{ $clients->firstItem() ?? 0 }}–{{ $clients->lastItem() ?? 0 }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('crm.leads.index') }}"><i class="ti ti-user-search me-1"></i>Лиды</a>
            <a class="btn btn-ghost-secondary me-1" href="{{ route('crm.deals.index') }}"><i class="ti ti-chart-funnel me-1"></i>Сделки</a>
            @can('checkBoxPermission', 'crm.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newClient"><i class="ti ti-plus me-1"></i>Новый клиент</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #4299e1;"><i class="ti ti-address-book"></i>Клиенты</div>
        <div class="crm-metric__value">{{ $metrics['clients_active'] }}</div>
        <div class="crm-metric__hint">активных из {{ $metrics['clients'] }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #ae3ec9;"><i class="ti ti-user-search"></i>Лиды</div>
        <div class="crm-metric__value">{{ $metrics['leads_open'] }}</div>
        <div class="crm-metric__hint">в работе</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #0b9b72;"><i class="ti ti-chart-funnel"></i>Сделки</div>
        <div class="crm-metric__value">{{ $metrics['deals_open'] }}</div>
        <div class="crm-metric__hint">на {{ number_format((float) $metrics['deals_amount'], 0, ',', ' ') }} в работе</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #f76707;"><i class="ti ti-calendar-event"></i>Активности</div>
        <div class="crm-metric__value">{{ $metrics['activities_overdue'] }}</div>
        <div class="crm-metric__hint">просрочено из {{ $metrics['activities_pending'] }}</div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <div class="input-icon">
        <input type="search" name="q" class="form-control crm-search-input" value="{{ $search }}" placeholder="Название, ИНН, телефон, e-mail…" aria-label="Поиск клиентов">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <select name="status" class="form-select" aria-label="Статус" onchange="this.form.submit()">
        <option value="active" @selected($status === 'active')>Активные</option>
        <option value="archived" @selected($status === 'archived')>Архив</option>
        <option value="all" @selected($status === 'all')>Все</option>
    </select>
    <button class="btn btn-ghost-secondary"><i class="ti ti-search me-1"></i>Найти</button>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th>Клиент</th>
                    <th>Вид</th>
                    <th class="text-center">Контакты</th>
                    <th class="text-center">Сделки</th>
                    <th>Ответственный</th>
                    <th>Статус</th>
                    <th class="text-end">Действия</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($clients as $client)
                    <tr>
                        <td>
                            <a class="crm-table__main text-decoration-none" href="{{ route('crm.clients.show', $client) }}">{{ $client->name }}</a>
                            <div class="crm-table__sub">
                                {{ $client->inn ? 'ИНН '.$client->inn : 'ИНН не указан' }}@if ($client->phone) · {{ $client->phone }}@endif
                            </div>
                        </td>
                        <td><span class="crm-chip crm-chip--grey">{{ $crm_label('crm_client_kind', $client->kind->value) }}</span></td>
                        <td class="text-center">{{ $client->contacts_count }}</td>
                        <td class="text-center">{{ $client->deals_count }}</td>
                        <td class="text-secondary">{{ $client->owner ? $client->owner->last_name.' '.$client->owner->first_name : '—' }}</td>
                        <td>
                            <span class="crm-chip {{ $client->status->value === 'active' ? 'crm-chip--green' : 'crm-chip--grey' }}">
                                {{ $crm_label('crm_client_status', $client->status->value) }}
                            </span>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-ghost-secondary" href="{{ route('crm.clients.show', $client) }}" title="Открыть"><i class="ti ti-eye"></i></a>
                            @can('checkBoxPermission', 'crm.manage')
                            <form method="post" action="{{ route('crm.clients.toggle', $client) }}" class="d-inline"
                                  onsubmit="return confirm('{{ $client->status->value === 'active' ? 'Отправить клиента «'.$client->name.'» в архив?' : 'Вернуть клиента «'.$client->name.'» в работу?' }}')">
                                @csrf
                                <button class="btn btn-sm btn-ghost-secondary" title="{{ $client->status->value === 'active' ? 'В архив' : 'В работу' }}">
                                    <i class="ti ti-{{ $client->status->value === 'active' ? 'archive' : 'archive-off' }}"></i>
                                </button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">@include('partials.empty-state', ['icon' => 'address-book', 'title' => 'Клиентов нет', 'text' => 'Добавьте клиента вручную или сконвертируйте лид в сделку.'])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($clients->hasPages())
        <div class="card-footer">{{ $clients->links('pagination::tabler') }}</div>
    @endif
</div>

@can('checkBoxPermission', 'crm.manage')
<div class="modal modal-blur fade" id="newClient" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('crm.clients.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новый клиент</h5></div>
            <div class="modal-body">
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="nc-name">Название<span class="req">*</span></label>
                        <input type="text" id="nc-name" name="name" class="form-control" value="{{ old('name') }}" placeholder="ООО «Ромашка»" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nc-kind">Вид<span class="req">*</span></label>
                        <select id="nc-kind" name="kind" class="form-select" required>
                            @foreach ($kinds as $kind)
                                <option value="{{ $kind->value }}" @selected(old('kind', 'company') === $kind->value)>{{ $crm_label('crm_client_kind', $kind->value) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nc-owner">Ответственный</label>
                        <select id="nc-owner" name="owner_id" class="form-select">
                            <option value="">— не назначен —</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected((string) old('owner_id') === (string) $employee->id)>{{ $employee->last_name }} {{ $employee->first_name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="nc-inn">ИНН</label>
                        <input type="text" id="nc-inn" name="inn" class="form-control" value="{{ old('inn') }}" maxlength="32">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nc-phone">Телефон</label>
                        <input type="text" id="nc-phone" name="phone" class="form-control" value="{{ old('phone') }}" maxlength="32">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nc-email">E-mail</label>
                        <input type="email" id="nc-email" name="email" class="form-control" value="{{ old('email') }}">
                    </div>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="nc-website">Сайт</label>
                        <input type="text" id="nc-website" name="website" class="form-control" value="{{ old('website') }}" placeholder="example.ru">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nc-address">Адрес</label>
                        <input type="text" id="nc-address" name="address" class="form-control" value="{{ old('address') }}" maxlength="1000">
                    </div>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="nc-notes">Примечание</label>
                    <textarea id="nc-notes" name="notes" class="form-control" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Создать клиента</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
