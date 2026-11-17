@extends('layouts.tabler')

@section('title', 'Финансы — Счета и акты')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Финансы</div>
            <h2 class="page-title mt-1">Счета и акты</h2>
            <div class="text-secondary mt-1">Реестр документов на оплату · всего {{ $invoices->total() }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('finance.accounts.index') }}"><i class="ti ti-cash-banknote me-1"></i>Счета</a>
            <a class="btn btn-ghost-secondary me-1" href="{{ route('finance.transactions.index') }}"><i class="ti ti-receipt me-1"></i>Операции</a>
            @can('checkBoxPermission', 'finance.invoices.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newInvoice"><i class="ti ti-plus me-1"></i>Новый счёт</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #f76707;"><i class="ti ti-file-invoice"></i>Не оплачено</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $metrics['unpaid'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ $metrics['invoices_open'] }} выставленных</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #e5697a;"><i class="ti ti-alarm"></i>Просрочено</div>
        <div class="crm-metric__value">{{ $metrics['invoices_overdue'] }}</div>
        <div class="crm-metric__hint">срок оплаты прошёл</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #0b9b72;"><i class="ti ti-arrow-down-left"></i>Поступления</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $metrics['income'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">за месяц</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #4299e1;"><i class="ti ti-cash-banknote"></i>Баланс</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $metrics['balance'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">все счета</div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <div class="input-icon">
        <input type="search" name="q" class="form-control crm-search-input" value="{{ $search }}" placeholder="Номер, клиент, контрагент…" aria-label="Поиск счетов">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <select name="status" class="form-select" aria-label="Статус" onchange="this.form.submit()">
        <option value="open" @selected($status === 'open')>К оплате</option>
        <option value="draft" @selected($status === 'draft')>Черновики</option>
        <option value="paid" @selected($status === 'paid')>Оплаченные</option>
        <option value="cancelled" @selected($status === 'cancelled')>Отменённые</option>
        <option value="all" @selected($status === 'all')>Все</option>
    </select>
    <select name="kind" class="form-select" aria-label="Вид" onchange="this.form.submit()">
        <option value="">Все виды</option>
        @foreach ($kinds as $kindOption)
            <option value="{{ $kindOption->value }}" @selected($kind === $kindOption->value)>{{ $crm_label('fin_invoice_kind', $kindOption->value) }}</option>
        @endforeach
    </select>
    <button class="btn btn-ghost-secondary"><i class="ti ti-search me-1"></i>Найти</button>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th>Документ</th>
                    <th>Вид</th>
                    <th>Дата / срок</th>
                    <th class="text-end">Сумма</th>
                    <th class="text-end">Оплачено</th>
                    <th>Статус</th>
                    <th class="text-end">Действия</th>
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
                            <div class="crm-table__sub">{{ $invoice->client?->name ?? $invoice->counterparty_name ?? 'контрагент не указан' }}</div>
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
                            <a class="btn btn-sm btn-ghost-secondary" href="{{ route('finance.invoices.show', $invoice) }}" title="Открыть"><i class="ti ti-eye"></i></a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">@include('partials.empty-state', ['icon' => 'file-invoice', 'title' => 'Счетов нет', 'text' => 'Создайте счёт или акт, выставьте его клиенту и принимайте оплату.'])</td></tr>
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
            <div class="modal-header"><h5 class="modal-title">Новый счёт или акт</h5></div>
            <div class="modal-body">
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="fi-number">Номер<span class="req">*</span></label>
                        <input type="text" id="fi-number" name="number" class="form-control" value="{{ old('number', 'INV-'.now()->format('Ymd-His')) }}" maxlength="32" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="fi-kind">Вид<span class="req">*</span></label>
                        <select id="fi-kind" name="kind" class="form-select" required>
                            @foreach ($kinds as $kindOption)
                                <option value="{{ $kindOption->value }}" @selected(old('kind', 'invoice') === $kindOption->value)>{{ $crm_label('fin_invoice_kind', $kindOption->value) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="fi-client">Клиент CRM</label>
                        <select id="fi-client" name="client_id" class="form-select">
                            <option value="">— не выбран —</option>
                            @foreach ($clients as $client)
                                <option value="{{ $client->id }}" @selected((string) old('client_id') === (string) $client->id)>{{ $client->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="fi-counterparty">Контрагент (если нет в CRM)</label>
                        <input type="text" id="fi-counterparty" name="counterparty_name" class="form-control" value="{{ old('counterparty_name') }}" maxlength="255">
                    </div>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="fi-issue">Дата документа<span class="req">*</span></label>
                        <input type="date" id="fi-issue" name="issue_date" class="form-control" value="{{ old('issue_date', now()->toDateString()) }}" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="fi-due">Оплатить до</label>
                        <input type="date" id="fi-due" name="due_date" class="form-control" value="{{ old('due_date') }}">
                    </div>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="fi-amount">Сумма<span class="req">*</span></label>
                        <input type="number" id="fi-amount" name="amount" class="form-control" value="{{ old('amount') }}" step="0.01" min="0.01" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="fi-vat">НДС, %</label>
                        <input type="number" id="fi-vat" name="vat_rate" class="form-control" value="{{ old('vat_rate', 0) }}" step="0.01" min="0" max="100">
                    </div>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="fi-notes">Примечание</label>
                    <input type="text" id="fi-notes" name="notes" class="form-control" value="{{ old('notes') }}" maxlength="2000">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Создать черновик</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
