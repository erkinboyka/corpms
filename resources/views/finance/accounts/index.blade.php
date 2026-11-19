@extends('layouts.tabler')
@section('workspace_layout', 'ledger')

@section('title', 'Финансы — Касса и счета')
@section('body_class', 'bm-finance-accounts')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Финансы</div>
            <h1 class="page-title mt-1">Касса и счета</h1>
            <div class="text-secondary mt-1">Баланс считается по журналу операций</div>
        </div>
        <div class="col-auto d-print-none">


            @can('checkBoxPermission', 'finance.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newAccount"><i class="ti ti-plus me-1"></i>Новый счёт</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-cash-banknote"></i>Баланс</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">@include('finance.partials.metric', ['key' => 'balance'])</div>
        <div class="crm-metric__hint">по всем счетам</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-arrow-down-left"></i>Доходы</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">@include('finance.partials.metric', ['key' => 'income'])</div>
        <div class="crm-metric__hint">за текущий месяц</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-danger);"><i class="ti ti-arrow-up-right"></i>Расходы</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">@include('finance.partials.metric', ['key' => 'expense'])</div>
        <div class="crm-metric__hint">за текущий месяц</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-file-invoice"></i>Не оплачено</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">@include('finance.partials.metric', ['key' => 'unpaid'])</div>
        <div class="crm-metric__hint">{{ $metrics['invoices_open'] }} выставленных</div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th scope="col">Счёт</th>
                    <th scope="col">Вид</th>
                    <th scope="col">Валюта</th>
                    <th scope="col" class="text-end">Начальный остаток</th>
                    <th scope="col" class="text-end">Баланс</th>
                    <th scope="col">Статус</th>
                    <th scope="col" class="text-end">Действия</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($accounts as $account)
                    <tr>
                        <td>
                            <div class="crm-table__main">{{ $account->name }}</div>
                            @if ($account->notes)<div class="crm-table__sub">{{ \Illuminate\Support\Str::limit($account->notes, 60) }}</div>@endif
                        </td>
                        <td><span class="crm-chip {{ $account->kind->value === 'cash' ? 'crm-chip--cyan' : 'crm-chip--grey' }}">{{ $crm_label('fin_account_kind', $account->kind->value) }}</span></td>
                        <td class="text-secondary">{{ $account->currency }}</td>
                        <td class="text-end text-secondary">{{ number_format((float) $account->opening_balance, 2, ',', ' ') }}</td>
                        <td class="text-end"><strong>{{ number_format((float) $account->balance, 2, ',', ' ') }}</strong></td>
                        <td>
                            <span class="crm-chip {{ $account->is_active ? 'crm-chip--green' : 'crm-chip--grey' }}">{{ $account->is_active ? 'активен' : 'выключен' }}</span>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-ghost-secondary" href="{{ route('finance.transactions.index', ['account' => $account->id]) }}" title="Операции"><i class="ti ti-receipt"></i></a>
                            @can('checkBoxPermission', 'finance.manage')
                            <form method="post" action="{{ route('finance.accounts.toggle', $account) }}" class="d-inline"
                                  onsubmit="return confirm('{{ $account->is_active ? 'Выключить' : 'Включить' }} счёт «{{ $account->name }}»? История операций сохранится.')">
                                @csrf
                                <button class="btn btn-sm btn-ghost-secondary" title="{{ $account->is_active ? 'Выключить' : 'Включить' }}">
                                    <i class="ti ti-{{ $account->is_active ? 'toggle-right' : 'toggle-left' }}"></i>
                                </button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">@include('partials.empty-state', ['icon' => 'cash-banknote', 'title' => 'Счетов нет', 'text' => 'Добавьте кассу или расчётный счёт, чтобы вести доходы и расходы.'])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('checkBoxPermission', 'finance.manage')
<div class="modal modal-blur fade" id="newAccount" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('finance.accounts.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новый счёт</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>Счёт</h3>
                    <div class="crm-form__desc">Название и вид — как в отчётах он будет называться.</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="fa-name">Название<span class="req">*</span></label>
                            <input type="text" id="fa-name" name="name" class="form-control" value="{{ old('name') }}" placeholder="Касса офиса" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="fa-kind">Вид<span class="req">*</span></label>
                            <select id="fa-kind" name="kind" class="form-select" required>
                                @foreach ($kinds as $kind)
                                    <option value="{{ $kind->value }}" @selected(old('kind', 'bank') === $kind->value)>{{ $crm_label('fin_account_kind', $kind->value) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>Валюта и остаток</h3>
                    <div class="crm-form__desc">Валюта счёта и начальный остаток — рядом, чтобы не перепутать.</div>
                    <div class="crm-form__row">
                        @include('partials.currency-select', [
                            'id' => 'fa-currency',
                            'name' => 'currency',
                            'value' => old('currency', 'RUB'),
                            'label' => 'Валюта',
                        ])
                        <div class="crm-form__field">
                            <label class="form-label" for="fa-opening">Начальный остаток</label>
                            <input type="number" id="fa-opening" name="opening_balance" class="form-control" value="{{ old('opening_balance', 0) }}" step="0.01">
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>Примечание</h3>
                    <div class="crm-form__desc">Для чего этот счёт и кто им пользуется.</div>
                    <div class="crm-form__field mb-0">
                        <input type="text" id="fa-notes" name="notes" class="form-control" value="{{ old('notes') }}" maxlength="2000" placeholder="Например: операционный счёт, ведёт финансовый отдел">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Создать счёт</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
