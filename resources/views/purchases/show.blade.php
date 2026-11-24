@extends('layouts.tabler')
@section('workspace_layout', 'record')

@section('title', __('Закупка') . ' ' . $order->number)

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Склад · Закупки') }}</div>
            <h1 class="page-title mt-1">{{ __('Заказ') }} {{ $order->number }}</h1>
            <div class="text-secondary mt-1">
                {{ $order->supplier?->name ?? __('поставщик не указан') }} {{ __('на склад') }}{{ $order->warehouse?->name ?? '—' }}»
            </div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('purchases.index') }}"><i class="ti ti-arrow-left me-1"></i>{{ __('К списку') }}</a>
            @can('checkBoxPermission', 'purchases.manage')
                @if ($order->status->value === 'draft')
                    <button class="btn btn-ghost-secondary me-1" data-bs-toggle="modal" data-bs-target="#addItem"><i class="ti ti-plus me-1"></i>{{ __('Позиция') }}</button>
                    <form method="post" action="{{ route('purchases.order', $order) }}" class="d-inline">@csrf
                        <button class="btn btn-ghost-secondary me-1"><i class="ti ti-send me-1"></i>{{ __('Разместить') }}</button>
                    </form>
                @endif
                @if (in_array($order->status->value, ['draft', 'ordered'], true))
                    <form method="post" action="{{ route('purchases.receive', $order) }}" class="d-inline" data-confirm="{{ __('Принять заказ                      на склад? Будут созданы движения прихода') }}." data-confirm-danger="1">@csrf
                        <button class="btn btn-primary me-1"><i class="ti ti-package-import me-1"></i>{{ __('Принять на склад') }}</button>
                    </form>
                    <form method="post" action="{{ route('purchases.cancel', $order) }}" class="d-inline" data-confirm="{{ __('Отменить заказ') }} {{ $order->number }}?" data-confirm-danger="1">@csrf
                        <button class="btn btn-ghost-danger"><i class="ti ti-x me-1"></i>{{ __('Отменить') }}</button>
                    </form>
                @endif
            @endcan
        </div>
    </div>
</div>

@php
    $statusChip = match ($order->status->value) {
        'draft' => 'crm-chip--grey',
        'ordered' => '',
        'received' => 'crm-chip--green',
        default => 'crm-chip--red',
    };
@endphp

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-clipboard"></i>{{ __('Статус') }}</div>
        <div class="crm-metric__value" style="font-size: 1.1rem; padding-top: 6px;">
            <span class="crm-chip {{ $statusChip }}">{{ $crm_label('purchase_order_status', $order->status->value) }}</span>
        </div>
        <div class="crm-metric__hint">{{ __('текущее состояние') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-coins"></i>{{ __('Сумма') }}</div>
        <div class="crm-metric__value" style="font-size: 1.1rem; padding-top: 6px;">{{ number_format((float) $order->total, 2, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ __('по позициям заказа') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-calendar"></i>{{ __('Заказ') }}</div>
        <div class="crm-metric__value" style="font-size: 1.1rem; padding-top: 6px;">{{ $order->order_date?->format('d.m.Y') ?? '—' }}</div>
        <div class="crm-metric__hint">{{ __('ожидание') }} {{ $order->expected_at?->format('d.m.Y') ?? '—' }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-purple);"><i class="ti ti-package-import"></i>{{ __('Принят') }}</div>
        <div class="crm-metric__value" style="font-size: 1.1rem; padding-top: 6px;">{{ $order->received_at?->format('d.m.Y') ?? '—' }}</div>
        <div class="crm-metric__hint">{{ __('дата оприходования') }}</div>
    </div>
</div>

@if ($obligation)
<div class="card">
    <div class="card-header">
        <div class="card-title">{{ __('Финансовое обязательство') }} {{ $obligation->number }}</div>
        <span class="crm-chip {{ $obligation->status->value === 'paid' ? 'crm-chip--green' : ($obligation->status->isPayable() ? '' : 'crm-chip--red') }}">
            {{ $crm_label('purchase_obligation_status', $obligation->status->value) }}
        </span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="crm-metric__label">{{ __('Сумма') }}</div>
                <div class="crm-metric__value">{{ number_format((float) $obligation->amount, 2, ',', ' ') }} {{ $obligation->currency }}</div>
            </div>
            <div class="col-md-3">
                <div class="crm-metric__label">{{ __('Оплачено') }}</div>
                <div class="crm-metric__value">{{ number_format($obligation->paidAmount(), 2, ',', ' ') }} {{ $obligation->currency }}</div>
            </div>
            <div class="col-md-3">
                <div class="crm-metric__label">{{ __('Остаток') }}</div>
                <div class="crm-metric__value">{{ number_format($obligation->remainingAmount(), 2, ',', ' ') }} {{ $obligation->currency }}</div>
            </div>
        </div>

        @forelse ($obligation->payments as $payment)
            @if ($loop->first)
                <div class="table-responsive mt-3">
                    <table class="table table-sm table-vcenter card-table">
                        <thead><tr>
                            <th>{{ __('Платёжка') }}</th>
                            <th>{{ __('Дата') }}</th>
                            <th>{{ __('Счёт') }}</th>
                            <th class="text-end">{{ __('Сумма') }}</th>
                        </tr></thead>
                        <tbody>
            @endif
                        <tr>
                            <td>{{ $payment->reference }}</td>
                            <td>{{ $payment->paid_at?->format('d.m.Y') ?? '—' }}</td>
                            <td>{{ $payment->account?->name ?? '—' }}</td>
                            <td class="text-end">{{ number_format((float) $payment->amount, 2, ',', ' ') }} {{ $obligation->currency }}</td>
                        </tr>
            @if ($loop->last)
                        </tbody>
                    </table>
                </div>
            @endif
        @empty
        @endforelse

        @if ($canPay && $obligation->status->isPayable() && $accounts->isNotEmpty())
            <form method="post" action="{{ route('purchases.obligations.payments.store', $order) }}" class="row g-3 mt-3" data-submit-guard>
                @csrf
                <div class="col-md-3">
                    <label class="form-label" for="obl-account">{{ __('Счёт') }}<span class="req">*</span></label>
                    <select id="obl-account" name="account_id" class="form-select" required>
                        <option value="">{{ __('Выберите счёт') }}</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected(old('account_id') == $account->id)>{{ $account->name }} · {{ $account->currency }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="obl-category">{{ __('Статья расхода') }}</label>
                    <select id="obl-category" name="category_id" class="form-select">
                        <option value="">{{ __('Без статьи') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected(old('category_id') == $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="obl-amount">{{ __('Сумма') }}<span class="req">*</span></label>
                    <input id="obl-amount" name="amount" type="number" step="0.01" min="0.01" max="{{ $obligation->remainingAmount() }}" class="form-control" value="{{ old('amount') }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="obl-paid-at">{{ __('Дата оплаты') }}<span class="req">*</span></label>
                    <input id="obl-paid-at" name="paid_at" type="date" class="form-control" value="{{ old('paid_at', now()->toDateString()) }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="obl-method">{{ __('Способ') }}<span class="req">*</span></label>
                    <select id="obl-method" name="method" class="form-select" required>
                        <option value="bank_transfer">{{ __('Банковский перевод') }}</option>
                        <option value="cash">{{ __('Наличные') }}</option>
                        <option value="card">{{ __('Карта') }}</option>
                        <option value="other">{{ __('Другое') }}</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="obl-reference">{{ __('Номер платёжки') }}<span class="req">*</span></label>
                    <input id="obl-reference" name="reference" type="text" maxlength="64" class="form-control" value="{{ old('reference') }}" placeholder="PAY-…" required>
                    <div class="form-text">{{ __('Повтор того же номера не создаст вторую оплату') }}</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="obl-notes">{{ __('Примечание') }}</label>
                    <input id="obl-notes" name="notes" type="text" maxlength="1000" class="form-control" value="{{ old('notes') }}">
                </div>
                <div class="col-12">
                    <button class="btn btn-primary"><i class="ti ti-cash me-1"></i>{{ __('Провести оплату') }}</button>
                </div>
            </form>
        @endif
    </div>
</div>
@endif

<div class="card">
    <div class="card-header">
        <div class="card-title">{{ __('Позиции заказа') }}</div>
    </div>
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('Товар') }}</th>
                    <th scope="col" class="text-end">{{ __('Количество') }}</th>
                    <th scope="col" class="text-end">{{ __('Цена') }}</th>
                    <th scope="col" class="text-end">{{ __('Сумма') }}</th>
                    @if ($canManage && $order->status->value === 'draft')
                        <th scope="col" class="text-end">{{ __('Действия') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($order->items as $item)
                    <tr>
                        <td>
                            <div class="crm-table__main">{{ $item->product?->name ?? '—' }}</div>
                            <div class="crm-table__sub">{{ __('арт') }} {{ $item->product?->sku ?? '—' }}</div>
                        </td>
                        <td class="text-end">{{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }} {{ $item->product?->unit ?? '' }}</td>
                        <td class="text-end">{{ number_format((float) $item->unit_price, 2, ',', ' ') }}</td>
                        <td class="text-end"><strong>{{ number_format((float) $item->quantity * (float) $item->unit_price, 2, ',', ' ') }}</strong></td>
                        @if ($canManage && $order->status->value === 'draft')
                            <td class="text-end">
                                <form method="post" action="{{ route('purchases.items.remove', [$order, $item->id]) }}" class="d-inline" data-confirm="{{ __('Убрать позицию') }} «{{ $item->product?->name ?? '' }}» {{ __('из заказа') }}?" data-confirm-danger="1">@csrf
                                    <button class="btn btn-sm btn-ghost-danger" title="{{ __("Убрать") }}"><i class="ti ti-trash"></i></button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'list', 'title' => __('Позиций нет'), 'text' => __('Вернитесь к списку и добавьте позиции в черновик.')])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($order->notes)
    <div class="card">
        <div class="card-header"><div class="card-title">{{ __('Примечание') }}</div></div>
        <div class="card-body text-secondary">{{ $order->notes }}</div>
    </div>
@endif

@can('checkBoxPermission', 'purchases.manage')
@if ($order->status->value === 'draft')
<div class="modal modal-blur fade" id="addItem" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('purchases.items.store', $order) }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Позиция в заказ') }} {{ $order->number }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__field">
                    <label class="form-label" for="ai-product">{{ __('Товар или услуга') }}<span class="req">*</span></label>
                    <select id="ai-product" name="product_id" class="form-select" required>
                        <option value="">{{ __('выберите') }}</option>
                        @foreach ($products as $p)
                            <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->sku }}) — {{ $crm_label('product_kind', $p->kind->value) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="ai-quantity">{{ __('Количество') }}<span class="req">*</span></label>
                        <input type="number" id="ai-quantity" name="quantity" class="form-control" value="1" step="0.001" min="0.001" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ai-price">{{ __('Цена') }}</label>
                        <input type="number" id="ai-price" name="unit_price" class="form-control" value="0" step="0.01" min="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Добавить позицию') }}</button>
            </div>
        </form>
    </div>
</div>
@endif
@endcan
@endsection
