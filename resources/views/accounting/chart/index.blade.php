@extends('layouts.tabler')
@section('workspace_layout', 'ledger')

@section('title', 'Бухгалтерия — План счетов')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Бухгалтерия</div>
            <h1 class="page-title mt-1">План счетов</h1>
            <div class="text-secondary mt-1">Счета компании · {{ $totals['accounts'] }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('accounting.entries.index') }}"><i class="ti ti-book-2 me-1"></i>Проводки</a>
            @can('checkBoxPermission', 'accounting.manage')
            <form method="post" action="{{ route('accounting.chart.default') }}" class="d-inline me-1">
                @csrf
                <button class="btn btn-ghost-secondary"><i class="ti ti-list-check me-1"></i>Типовой план</button>
            </form>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newAccount"><i class="ti ti-plus me-1"></i>Новый счёт</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-list-numbers"></i>Счетов</div>
        <div class="crm-metric__value">{{ $totals['accounts'] }}</div>
        <div class="crm-metric__hint">в плане компании</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-building-bank"></i>Активы</div>
        <div class="crm-metric__value">{{ $totals['assets'] }}</div>
        <div class="crm-metric__hint">дебетовое сальдо</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-purple);"><i class="ti ti-arrow-down-left"></i>Доходные</div>
        <div class="crm-metric__value">{{ $totals['income'] }}</div>
        <div class="crm-metric__hint">счета доходов</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-danger);"><i class="ti ti-arrow-up-right"></i>Расходные</div>
        <div class="crm-metric__value">{{ $totals['expense'] }}</div>
        <div class="crm-metric__hint">счета расходов</div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th scope="col">Код</th>
                    <th scope="col">Название</th>
                    <th scope="col">Вид</th>
                    <th scope="col">Статус</th>
                    <th scope="col" class="text-end">Действия</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($accounts as $account)
                    <tr>
                        <td><span class="crm-chip crm-chip--grey">{{ $account->code }}</span></td>
                        <td><div class="crm-table__main">{{ $account->name }}</div></td>
                        <td>
                            @php
                                $kindChip = match ($account->kind->value) {
                                    'asset' => 'crm-chip--cyan',
                                    'liability' => 'crm-chip--orange',
                                    'equity' => 'crm-chip--grey',
                                    'income' => 'crm-chip--green',
                                    default => 'crm-chip--red',
                                };
                            @endphp
                            <span class="crm-chip {{ $kindChip }}">{{ $crm_label('acc_account_kind', $account->kind->value) }}</span>
                        </td>
                        <td>
                            <span class="crm-chip {{ $account->is_active ? 'crm-chip--green' : 'crm-chip--grey' }}">{{ $account->is_active ? 'активен' : 'выключен' }}</span>
                        </td>
                        <td class="text-end">
                            @can('checkBoxPermission', 'accounting.manage')
                            <form method="post" action="{{ route('accounting.chart.toggle', $account) }}" class="d-inline"
                                  onsubmit="return confirm('{{ $account->is_active ? 'Выключить' : 'Включить' }} счёт {{ $account->code }}?')">
                                @csrf
                                <button class="btn btn-sm btn-ghost-secondary" title="{{ $account->is_active ? 'Выключить' : 'Включить' }}">
                                    <i class="ti ti-{{ $account->is_active ? 'toggle-right' : 'toggle-left' }}"></i>
                                </button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'list-numbers', 'title' => 'План счетов пуст', 'text' => 'Нажмите «Типовой план», чтобы добавить базовые счета, или создайте счёт вручную.'])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('checkBoxPermission', 'accounting.manage')
<div class="modal modal-blur fade" id="newAccount" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('accounting.chart.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новый счёт плана</h5></div>
            <div class="modal-body">
                <div class="crm-form__section mb-0">
                    <h3>Счёт плана</h3>
                    <div class="crm-form__desc">Код, вид и название — как в плане счетов.</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="aa-code">Код<span class="req">*</span></label>
                            <input type="text" id="aa-code" name="code" class="form-control" value="{{ old('code') }}" placeholder="90.3" maxlength="10" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="aa-kind">Вид<span class="req">*</span></label>
                            <select id="aa-kind" name="kind" class="form-select" required>
                                @foreach ($kinds as $kind)
                                    <option value="{{ $kind->value }}" @selected(old('kind', 'asset') === $kind->value)>{{ $crm_label('acc_account_kind', $kind->value) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="aa-name">Название<span class="req">*</span></label>
                        <input type="text" id="aa-name" name="name" class="form-control" value="{{ old('name') }}" placeholder="Прочие доходы и расходы" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Добавить счёт</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
