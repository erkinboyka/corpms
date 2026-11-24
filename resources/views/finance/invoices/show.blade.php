@extends('layouts.tabler')
@section('workspace_layout', 'record')

@section('title', __('Финансы — счёт ') . $invoice->number)

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Финансы · Счёт') }}</div>
            <h1 class="page-title mt-1">{{ $invoice->number }}</h1>
            <div class="text-secondary mt-1 d-flex align-items-center gap-2 flex-wrap">
                <span class="crm-chip crm-chip--grey">{{ $crm_label('fin_invoice_kind', $invoice->kind->value) }}</span>
                <span class="crm-chip {{ $invoice->status->value === 'paid' ? 'crm-chip--green' : ($invoice->isOverdue() ? 'crm-chip--red' : '') }}">{{ $crm_label('fin_invoice_status', $invoice->status->value) }}</span>
                <span>{{ $invoice->client?->name ?? $invoice->counterparty_name ?? __('контрагент не указан') }}</span>
                @if ($invoice->isOverdue())<span class="text-danger">{{ __('просрочен') }}</span>@endif
            </div>
        </div>
        <div class="col-auto d-print-none">

            @can('checkBoxPermission', 'finance.invoices.manage')
                @if ($invoice->status->value === 'draft')
                    <form method="post" action="{{ route('finance.invoices.issue', $invoice) }}" class="d-inline">@csrf
                        <button class="btn btn-ghost-secondary me-1"><i class="ti ti-send me-1"></i>{{ __('Выставить') }}</button>
                    </form>
                @endif
                @if ($invoice->status->value === 'issued' && $remaining > 0)
                    <button class="btn btn-primary me-1" data-bs-toggle="modal" data-bs-target="#payInvoice"><i class="ti ti-cash me-1"></i>{{ __('Принять оплату') }}</button>
                @endif
                @if ($invoice->status->isClosed() === false)
                    <form method="post" action="{{ route('finance.invoices.cancel', $invoice) }}" class="d-inline" data-confirm="{{ __('Отменить счёт') }} {{ $invoice->number }}?" data-confirm-danger="1">@csrf
                        <button class="btn btn-ghost-danger"><i class="ti ti-x me-1"></i>{{ __('Отменить') }}</button>
                    </form>
                @endif
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-file-invoice"></i>{{ __('Сумма') }}</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $invoice->amount, 2, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ __('НДС') }} {{ number_format((float) $invoice->vat_rate, 2, ',', ' ') }}%</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-cash"></i>{{ __('Оплачено') }}</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format($paid, 2, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ __('платежей') }} {{ $invoice->payments->count() }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-hourglass"></i>{{ __('Остаток') }}</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format($remaining, 2, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ __('к оплате') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-purple);"><i class="ti ti-calendar"></i>{{ __('Срок') }}</div>
        <div class="crm-metric__value" style="font-size: 1.1rem; padding-top: 8px;">{{ $invoice->due_date?->format('d.m.Y') ?? '—' }}</div>
        <div class="crm-metric__hint">{{ __('документ от') }} {{ $invoice->issue_date?->format('d.m.Y') }}</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><div class="card-title">{{ __('Платежи') }}</div></div>
            <div class="table-responsive">
                <table data-record-key="0" class="table table-hover table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Дата') }}</th>
                            <th scope="col">{{ __('Счёт') }}</th>
                            <th scope="col">{{ __('Способ') }}</th>
                            <th scope="col" class="text-end">{{ __('Сумма') }}</th>
                            <th scope="col">{{ __('Автор') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($invoice->payments as $payment)
                            <tr>
                                <td class="text-secondary">{{ $payment->paid_at?->format('d.m.Y') }}</td>
                                <td>{{ $payment->account?->name ?? '—' }}</td>
                                <td><span class="crm-chip crm-chip--grey">{{ $crm_label('fin_payment_method', $payment->method->value) }}</span></td>
                                <td class="text-end fw-semibold text-success">+{{ number_format((float) $payment->amount, 2, ',', ' ') }}</td>
                                <td class="text-secondary small">{{ $payment->creator?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'cash', 'title' => __('Платежей нет'), 'text' => __('Примите оплату — платёж попадёт в журнал доходов и уменьшит остаток.')])</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><div class="card-title">{{ __('Реквизиты') }}</div></div>
            <div class="card-body">
                <div class="text-secondary small">{{ __('Клиент CRM') }}</div>
                <div>{{ $invoice->client?->name ?? '—' }}</div>
                <div class="text-secondary small mt-2">{{ __('Плательщик') }}</div>
                <div>{{ $invoice->counterparty_name ?: ($invoice->client?->name ?? '—') }}</div>
                <div class="text-secondary small mt-2">{{ __('Примечание') }}</div>
                <div class="text-secondary">{{ $invoice->notes ?: '—' }}</div>
                <div class="text-secondary small mt-2">{{ __('Создал') }}</div>
                <div>{{ $invoice->creator?->name ?? '—' }}</div>
            </div>
        </div>
    </div>
</div>

@can('checkBoxPermission', 'finance.invoices.manage')
@if ($invoice->status->value === 'issued' && $remaining > 0)
<div class="modal modal-blur fade" id="payInvoice" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('finance.invoices.payments.store', $invoice) }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Оплата счёта') }} {{ $invoice->number }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Сумма и дата') }}</h3>
                    <div class="crm-form__desc">{{ __('Сколько принимаем и когда поступило') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="fp-amount">{{ __('Сумма') }}<span class="req">*</span></label>
                            <input type="number" id="fp-amount" name="amount" class="form-control" value="{{ old('amount', number_format($remaining, 2, '.', '')) }}" step="0.01" min="0.01" max="{{ number_format($remaining, 2, '.', '') }}" required>
                            <div class="form-text">{{ __('Остаток к оплате') }} {{ number_format($remaining, 2, ',', ' ') }}</div>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="fp-date">{{ __('Дата') }}<span class="req">*</span></label>
                            <input type="date" id="fp-date" name="paid_at" class="form-control" value="{{ old('paid_at', now()->toDateString()) }}" required>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>{{ __('Счёт и способ') }}</h3>
                    <div class="crm-form__desc">{{ __('На какой счёт и каким способом пришли деньги') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="fp-account">{{ __('На счёт') }}<span class="req">*</span></label>
                            <select id="fp-account" name="account_id" class="form-select" required>
                                <option value="">{{ __('выберите') }}</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}" @selected((string) old('account_id') === (string) $account->id)>{{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="fp-method">{{ __('Способ') }}<span class="req">*</span></label>
                            <select id="fp-method" name="method" class="form-select" required>
                                @foreach ($methods as $method)
                                    <option value="{{ $method->value }}" @selected(old('method', 'bank_transfer') === $method->value)>{{ $crm_label('fin_payment_method', $method->value) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Учёт') }}</h3>
                    <div class="crm-form__desc">{{ __('Статья дохода и реквизиты платежа для сверки') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="fp-category">{{ __('Статья дохода') }}</label>
                            <select id="fp-category" name="category_id" class="form-select">
                                <option value="">{{ __('без статьи') }}</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="fp-reference">{{ __('Номер платёжки') }}</label>
                            <input type="text" id="fp-reference" name="reference" class="form-control" value="{{ old('reference') }}" maxlength="255">
                        </div>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="fp-notes">{{ __('Примечание') }}</label>
                        <input type="text" id="fp-notes" name="notes" class="form-control" value="{{ old('notes') }}" maxlength="1000">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Принять оплату') }}</button>
            </div>
        </form>
    </div>
</div>
@endif
@endcan
@endsection
