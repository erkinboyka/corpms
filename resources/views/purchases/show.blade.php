@extends('layouts.tabler')

@section('title', 'Закупка '.$order->number)

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Склад · Закупки</div>
            <h2 class="page-title mt-1">Заказ {{ $order->number }}</h2>
            <div class="text-secondary mt-1">
                {{ $order->supplier?->name ?? 'поставщик не указан' }} · на склад «{{ $order->warehouse?->name ?? '—' }}»
            </div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('purchases.index') }}"><i class="ti ti-arrow-left me-1"></i>К списку</a>
            @can('checkBoxPermission', 'purchases.manage')
                @if ($order->status->value === 'draft')
                    <button class="btn btn-ghost-secondary me-1" data-bs-toggle="modal" data-bs-target="#addItem"><i class="ti ti-plus me-1"></i>Позиция</button>
                    <form method="post" action="{{ route('purchases.order', $order) }}" class="d-inline">@csrf
                        <button class="btn btn-ghost-secondary me-1"><i class="ti ti-send me-1"></i>Разместить</button>
                    </form>
                @endif
                @if (in_array($order->status->value, ['draft', 'ordered'], true))
                    <form method="post" action="{{ route('purchases.receive', $order) }}" class="d-inline"
                          onsubmit="return confirm('Принять заказ {{ $order->number }} на склад? Будут созданы движения прихода.')">@csrf
                        <button class="btn btn-primary me-1"><i class="ti ti-package-import me-1"></i>Принять на склад</button>
                    </form>
                    <form method="post" action="{{ route('purchases.cancel', $order) }}" class="d-inline"
                          onsubmit="return confirm('Отменить заказ {{ $order->number }}?')">@csrf
                        <button class="btn btn-ghost-danger"><i class="ti ti-x me-1"></i>Отменить</button>
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
        <div class="crm-metric__label" style="color: #4299e1;"><i class="ti ti-clipboard"></i>Статус</div>
        <div class="crm-metric__value" style="font-size: 1.1rem; padding-top: 6px;">
            <span class="crm-chip {{ $statusChip }}">{{ $crm_label('purchase_order_status', $order->status->value) }}</span>
        </div>
        <div class="crm-metric__hint">текущее состояние</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #0b9b72;"><i class="ti ti-coins"></i>Сумма</div>
        <div class="crm-metric__value" style="font-size: 1.1rem; padding-top: 6px;">{{ number_format((float) $order->total, 2, ',', ' ') }}</div>
        <div class="crm-metric__hint">по позициям заказа</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #f76707;"><i class="ti ti-calendar"></i>Заказ</div>
        <div class="crm-metric__value" style="font-size: 1.1rem; padding-top: 6px;">{{ $order->order_date?->format('d.m.Y') ?? '—' }}</div>
        <div class="crm-metric__hint">ожидание: {{ $order->expected_at?->format('d.m.Y') ?? '—' }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #ae3ec9;"><i class="ti ti-package-import"></i>Принят</div>
        <div class="crm-metric__value" style="font-size: 1.1rem; padding-top: 6px;">{{ $order->received_at?->format('d.m.Y') ?? '—' }}</div>
        <div class="crm-metric__hint">дата оприходования</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">Позиции заказа</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th>Товар</th>
                    <th class="text-end">Количество</th>
                    <th class="text-end">Цена</th>
                    <th class="text-end">Сумма</th>
                    @if ($canManage && $order->status->value === 'draft')
                        <th class="text-end">Действия</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($order->items as $item)
                    <tr>
                        <td>
                            <div class="crm-table__main">{{ $item->product?->name ?? '—' }}</div>
                            <div class="crm-table__sub">арт. {{ $item->product?->sku ?? '—' }}</div>
                        </td>
                        <td class="text-end">{{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }} {{ $item->product?->unit ?? '' }}</td>
                        <td class="text-end">{{ number_format((float) $item->unit_price, 2, ',', ' ') }}</td>
                        <td class="text-end"><strong>{{ number_format((float) $item->quantity * (float) $item->unit_price, 2, ',', ' ') }}</strong></td>
                        @if ($canManage && $order->status->value === 'draft')
                            <td class="text-end">
                                <form method="post" action="{{ route('purchases.items.remove', [$order, $item->id]) }}" class="d-inline"
                                      onsubmit="return confirm('Убрать позицию «{{ $item->product?->name ?? '' }}» из заказа?')">@csrf
                                    <button class="btn btn-sm btn-ghost-danger" title="Убрать"><i class="ti ti-trash"></i></button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'list', 'title' => 'Позиций нет', 'text' => 'Вернитесь к списку и добавьте позиции в черновик.'])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($order->notes)
    <div class="card">
        <div class="card-header"><div class="card-title">Примечание</div></div>
        <div class="card-body text-secondary">{{ $order->notes }}</div>
    </div>
@endif

@can('checkBoxPermission', 'purchases.manage')
@if ($order->status->value === 'draft')
<div class="modal modal-blur fade" id="addItem" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('purchases.items.store', $order) }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Позиция в заказ {{ $order->number }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__field">
                    <label class="form-label" for="ai-product">Товар или услуга<span class="req">*</span></label>
                    <select id="ai-product" name="product_id" class="form-select" required>
                        <option value="">— выберите —</option>
                        @foreach ($products as $p)
                            <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->sku }}) — {{ $crm_label('product_kind', $p->kind->value) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="ai-quantity">Количество<span class="req">*</span></label>
                        <input type="number" id="ai-quantity" name="quantity" class="form-control" value="1" step="0.001" min="0.001" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ai-price">Цена</label>
                        <input type="number" id="ai-price" name="unit_price" class="form-control" value="0" step="0.01" min="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Добавить позицию</button>
            </div>
        </form>
    </div>
</div>
@endif
@endcan
@endsection
