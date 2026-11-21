@extends('layouts.tabler')
@section('workspace_layout', 'directory')

@section('title', __('Финансы — Счета и акты'))

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Финансы') }}</div>
            <h1 class="page-title mt-1">{{ __('Счета и акты') }}</h1>
            <div class="text-secondary mt-1">{{ __('Реестр документов на оплату · всего') }} {{ $invoices->total() }}</div>
        </div>
        <div class="col-auto d-print-none">


            @can('checkBoxPermission', 'finance.invoices.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newInvoice"><i class="ti ti-plus me-1"></i>{{ __('Новый счёт') }}</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-file-invoice"></i>{{ __('Не оплачено') }}</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">@include('finance.partials.metric', ['key' => 'unpaid'])</div>
        <div class="crm-metric__hint">{{ $metrics['invoices_open'] }} {{ __('выставленных') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-danger);"><i class="ti ti-alarm"></i>{{ __('Просрочено') }}</div>
        <div class="crm-metric__value">{{ $metrics['invoices_overdue'] }}</div>
        <div class="crm-metric__hint">{{ __('срок оплаты прошёл') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-arrow-down-left"></i>{{ __('Поступления') }}</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">@include('finance.partials.metric', ['key' => 'income'])</div>
        <div class="crm-metric__hint">{{ __('за месяц') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-cash-banknote"></i>{{ __('Баланс') }}</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">@include('finance.partials.metric', ['key' => 'balance'])</div>
        <div class="crm-metric__hint">{{ __('все счета') }}</div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <div class="input-icon">
        <input type="search" name="q" class="form-control crm-search-input" value="{{ $search }}" placeholder="{{ __("Номер, клиент, контрагент…") }}" aria-label="{{ __("Поиск счетов") }}">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <select name="status" class="form-select" aria-label="{{ __("Статус") }}" onchange="this.form.submit()">
        <option value="open" @selected($status === 'open')>{{ __('К оплате') }}</option>
        <option value="draft" @selected($status === 'draft')>{{ __('Черновики') }}</option>
        <option value="paid" @selected($status === 'paid')>{{ __('Оплаченные') }}</option>
        <option value="cancelled" @selected($status === 'cancelled')>{{ __('Отменённые') }}</option>
        <option value="all" @selected($status === 'all')>{{ __('Все') }}</option>
    </select>
    <select name="kind" class="form-select" aria-label="{{ __("Вид") }}" onchange="this.form.submit()">
        <option value="">{{ __('Все виды') }}</option>
        @foreach ($kinds as $kindOption)
            <option value="{{ $kindOption->value }}" @selected($kind === $kindOption->value)>{{ $crm_label('fin_invoice_kind', $kindOption->value) }}</option>
        @endforeach
    </select>
    <button class="btn btn-ghost-secondary"><i class="ti ti-search me-1"></i>{{ __('Найти') }}</button>
</form>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('Документ') }}</th>
                    <th scope="col">{{ __('Вид') }}</th>
                    <th scope="col">{{ __('Дата / срок') }}</th>
                    <th scope="col" class="text-end">{{ __('Сумма') }}</th>
                    <th scope="col" class="text-end">{{ __('Оплачено') }}</th>
                    <th scope="col">{{ __('Статус') }}</th>
                    <th scope="col" class="text-end">{{ __('Действия') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoices as $invoice)
                    @php
                        $paid = (float) $invoice->payments_sum_amount;
                    @endphp
                    <tr>
                        <td>
                            <a class="crm-table__main text-decoration-none" href="{{ route('finance.invoices.show', $invoice) }}">{{ $invoice->number }}</a>
                            <div class="crm-table__sub">{{ $invoice->client?->name ?? $invoice->counterparty_name ?? __('контрагент не указан') }}</div>
                        </td>
                        <td><span class="crm-chip crm-chip--grey">{{ $crm_label('fin_invoice_kind', $invoice->kind->value) }}</span></td>
                        <td class="text-secondary small">
                            {{ $invoice->issue_date?->format('d.m.Y') }}@if ($invoice->due_date) → {{ $invoice->due_date->format('d.m.Y') }}@endif
                        </td>
                        <td class="text-end">{{ number_format((float) $invoice->amount, 2, ',', ' ') }}</td>
                        <td class="text-end {{ $paid > 0 ? 'text-success' : 'text-secondary' }}">{{ number_format($paid, 2, ',', ' ') }}</td>
                        <td>
                            @php
                                $statusChip = match ($invoice->status->value) {
                                    'draft' => 'crm-chip--grey',
                                    'issued' => $invoice->isOverdue() ? 'crm-chip--red' : 'crm-chip',
                                    'paid' => 'crm-chip--green',
                                    default => 'crm-chip--grey',
                                };
                            @endphp
                            <span class="crm-chip {{ $statusChip }}">{{ $crm_label('fin_invoice_status', $invoice->status->value) }}</span>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-ghost-secondary" href="{{ route('finance.invoices.show', $invoice) }}" title="{{ __("Открыть") }}"><i class="ti ti-eye"></i></a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">@include('partials.empty-state', ['icon' => 'file-invoice', 'title' => __('Счетов нет'), 'text' => __('Создайте счёт или акт, выставьте его клиенту и принимайте оплату.')])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($invoices->hasPages())
        <div class="card-footer">{{ $invoices->links('pagination::tabler') }}</div>
    @endif
</div>

@can('checkBoxPermission', 'finance.invoices.manage')
<div class="modal modal-blur fade" id="newInvoice" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('finance.invoices.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Новый счёт или акт') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Документ') }}</h3>
                    <div class="crm-form__desc">{{ __('Номер и вид — счёт на оплату или акт выполненных работ') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="fi-number">{{ __('Номер') }}<span class="req">*</span></label>
                            <input type="text" id="fi-number" name="number" class="form-control" value="{{ old('number', 'INV-'.now()->format('Ymd-His')) }}" maxlength="32" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="fi-kind">{{ __('Вид') }}<span class="req">*</span></label>
                            <select id="fi-kind" name="kind" class="form-select" required>
                                @foreach ($kinds as $kindOption)
                                    <option value="{{ $kindOption->value }}" @selected(old('kind', 'invoice') === $kindOption->value)>{{ $crm_label('fin_invoice_kind', $kindOption->value) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>{{ __('Контрагент') }}</h3>
                    <div class="crm-form__desc">{{ __('Клиент из CRM или свободное название — выберите одно из двух') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="fi-client">{{ __('Клиент CRM') }}</label>
                            <select id="fi-client" name="client_id" class="form-select">
                                <option value="">{{ __('не выбран') }}</option>
                                @foreach ($clients as $client)
                                    <option value="{{ $client->id }}" @selected((string) old('client_id') === (string) $client->id)>{{ $client->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="fi-counterparty">{{ __('Контрагент (если нет в CRM)') }}</label>
                            <input type="text" id="fi-counterparty" name="counterparty_name" class="form-control" value="{{ old('counterparty_name') }}" maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>{{ __('Суммы и даты') }}</h3>
                    <div class="crm-form__desc">{{ __('Сумма с НДС и срок оплаты рядом — видно, сколько и когда') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="fi-amount">{{ __('Сумма') }}<span class="req">*</span></label>
                            <input type="number" id="fi-amount" name="amount" class="form-control" value="{{ old('amount') }}" step="0.01" min="0.01" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="fi-vat">{{ __('НДС, %') }}</label>
                            <input type="number" id="fi-vat" name="vat_rate" class="form-control" value="{{ old('vat_rate', 0) }}" step="0.01" min="0" max="100">
                        </div>
                    </div>
                    <div class="crm-form__row mb-0">
                        <div class="crm-form__field">
                            <label class="form-label" for="fi-issue">{{ __('Дата документа') }}<span class="req">*</span></label>
                            <input type="date" id="fi-issue" name="issue_date" class="form-control" value="{{ old('issue_date', now()->toDateString()) }}" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="fi-due">{{ __('Оплатить до') }}</label>
                            <input type="date" id="fi-due" name="due_date" class="form-control" value="{{ old('due_date') }}">
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Примечание') }}</h3>
                    <div class="crm-form__desc">{{ __('Условия оплаты и реквизиты для клиента') }}</div>
                    <div class="crm-form__field mb-0">
                        <input type="text" id="fi-notes" name="notes" class="form-control" value="{{ old('notes') }}" maxlength="2000">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Создать черновик') }}</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
