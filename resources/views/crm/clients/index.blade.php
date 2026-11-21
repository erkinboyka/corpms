@extends('layouts.tabler')
@section('workspace_layout', 'directory')

@section('title', __('CRM — Клиенты'))

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">CRM</div>
            <h1 class="page-title mt-1">{{ __('Клиенты') }}</h1>
            <div class="text-secondary mt-1">{{ __('Всего') }} {{ $clients->total() }} {{ __('показаны') }} {{ $clients->firstItem() ?? 0 }}–{{ $clients->lastItem() ?? 0 }}</div>
        </div>
        <div class="col-auto d-print-none">


            @can('checkBoxPermission', 'crm.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newClient"><i class="ti ti-plus me-1"></i>{{ __('Новый клиент') }}</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-address-book"></i>{{ __('Клиенты') }}</div>
        <div class="crm-metric__value">{{ $metrics['clients_active'] }}</div>
        <div class="crm-metric__hint">{{ __('активных из') }} {{ $metrics['clients'] }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-purple);"><i class="ti ti-user-search"></i>{{ __('Лиды') }}</div>
        <div class="crm-metric__value">{{ $metrics['leads_open'] }}</div>
        <div class="crm-metric__hint">{{ __('в работе') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-chart-funnel"></i>{{ __('Сделки') }}</div>
        <div class="crm-metric__value">{{ $metrics['deals_open'] }}</div>
        <div class="crm-metric__hint">{{ __('на') }} {{ number_format((float) $metrics['deals_amount'], 0, ',', ' ') }} {{ __('в работе') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-calendar-event"></i>{{ __('Активности') }}</div>
        <div class="crm-metric__value">{{ $metrics['activities_overdue'] }}</div>
        <div class="crm-metric__hint">{{ __('просрочено из') }} {{ $metrics['activities_pending'] }}</div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <div class="input-icon">
        <input type="search" name="q" class="form-control crm-search-input" value="{{ $search }}" placeholder="{{ __("Название, ИНН, телефон, e-mail…") }}" aria-label="{{ __("Поиск клиентов") }}">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <select name="status" class="form-select" aria-label="{{ __("Статус") }}" onchange="this.form.submit()">
        <option value="active" @selected($status === 'active')>{{ __('Активные') }}</option>
        <option value="archived" @selected($status === 'archived')>{{ __('Архив') }}</option>
        <option value="all" @selected($status === 'all')>{{ __('Все') }}</option>
    </select>
    <button class="btn btn-ghost-secondary"><i class="ti ti-search me-1"></i>{{ __('Найти') }}</button>
</form>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('Клиент') }}</th>
                    <th scope="col">{{ __('Вид') }}</th>
                    <th scope="col" class="text-center">{{ __('Контакты') }}</th>
                    <th scope="col" class="text-center">{{ __('Сделки') }}</th>
                    <th scope="col">{{ __('Ответственный') }}</th>
                    <th scope="col">{{ __('Статус') }}</th>
                    <th scope="col" class="text-end">{{ __('Действия') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($clients as $client)
                    <tr>
                        <td>
                            <a class="crm-table__main text-decoration-none" href="{{ route('crm.clients.show', $client) }}">{{ $client->name }}</a>
                            <div class="crm-table__sub">
                                {{ $client->inn ? 'ИНН '.$client->inn : __('ИНН не указан') }}@if ($client->phone) · {{ $client->phone }}@endif
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
                            <a class="btn btn-sm btn-ghost-secondary" href="{{ route('crm.clients.show', $client) }}" title="{{ __("Открыть") }}"><i class="ti ti-eye"></i></a>
                            @can('checkBoxPermission', 'crm.manage')
                            <form method="post" action="{{ route('crm.clients.toggle', $client) }}" class="d-inline"
                                  onsubmit="return confirm('{{ $client->status->value === 'active' ? __('Отправить клиента «').$client->name.__('» в архив?') : __('Вернуть клиента «').$client->name.__('» в работу?') }}')">
                                @csrf
                                <button class="btn btn-sm btn-ghost-secondary" title="{{ $client->status->value === 'active' ? __('В архив') : __('В работу') }}">
                                    <i class="ti ti-{{ $client->status->value === 'active' ? 'archive' : 'archive-off' }}"></i>
                                </button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">@include('partials.empty-state', ['icon' => 'address-book', 'title' => __('Клиентов нет'), 'text' => __('Добавьте клиента вручную или сконвертируйте лид в сделку.')])</td></tr>
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
            <div class="modal-header"><h5 class="modal-title">{{ __('Новый клиент') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Клиент') }}</h3>
                    <div class="crm-form__desc">{{ __('Название, вид и ответственный — одна строка, всё видно сразу') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-name">{{ __('Название') }}<span class="req">*</span></label>
                            <input type="text" id="nc-name" name="name" class="form-control" value="{{ old('name') }}" placeholder="{{ __("ООО «Ромашка»") }}" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-kind">{{ __('Вид') }}<span class="req">*</span></label>
                            <select id="nc-kind" name="kind" class="form-select" required>
                                @foreach ($kinds as $kind)
                                    <option value="{{ $kind->value }}" @selected(old('kind', 'company') === $kind->value)>{{ $crm_label('crm_client_kind', $kind->value) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-owner">{{ __('Ответственный') }}</label>
                            <select id="nc-owner" name="owner_id" class="form-select">
                                <option value="">{{ __('не назначен') }}</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}" @selected((string) old('owner_id') === (string) $employee->id)>{{ $employee->last_name }} {{ $employee->first_name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>{{ __('Реквизиты и контакты') }}</h3>
                    <div class="crm-form__desc">{{ __('ИНН, телефон и почта рядом — для счетов и быстрой связи') }}</div>
                    <div class="crm-form__row crm-form__row--3">
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-inn">{{ __('ИНН') }}</label>
                            <input type="text" id="nc-inn" name="inn" class="form-control" value="{{ old('inn') }}" maxlength="32">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-phone">{{ __('Телефон') }}</label>
                            <input type="text" id="nc-phone" name="phone" class="form-control" value="{{ old('phone') }}" maxlength="32">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-email">E-mail</label>
                            <input type="email" id="nc-email" name="email" class="form-control" value="{{ old('email') }}">
                        </div>
                    </div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-website">{{ __('Сайт') }}</label>
                            <input type="text" id="nc-website" name="website" class="form-control" value="{{ old('website') }}" placeholder="example.ru">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-address">{{ __('Адрес') }}</label>
                            <input type="text" id="nc-address" name="address" class="form-control" value="{{ old('address') }}" maxlength="1000">
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Примечание') }}</h3>
                    <div class="crm-form__desc">{{ __('Особенности работы с клиентом и договорённости') }}</div>
                    <div class="crm-form__field mb-0">
                        <textarea data-crm-rich id="nc-notes" name="notes" class="form-control" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Создать клиента') }}</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
