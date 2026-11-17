@extends('layouts.tabler')

@section('title', 'Закупки')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Склад</div>
            <h2 class="page-title mt-1">Закупочные заказы</h2>
            <div class="text-secondary mt-1">Черновик → размещён → принят · приём оприходует товар на склад</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('inventory.suppliers') }}"><i class="ti ti-truck-delivery me-1"></i>Поставщики</a>
            @can('checkBoxPermission', 'purchases.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newOrder"><i class="ti ti-plus me-1"></i>Новый заказ</button>
            @endcan
        </div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <div class="input-icon">
        <input type="search" name="q" class="form-control crm-search-input" value="{{ $search }}" placeholder="Поиск: номер заказа…" aria-label="Поиск заказа">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <div>
        <label class="form-label" for="po-status">Статус</label>
        <select id="po-status" name="status" class="form-select" onchange="this.form.submit()">
            <option value="">— все —</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}" @selected($statusFilter === $status->value)>{{ $crm_label('purchase_order_status', $status->value) }}</option>
            @endforeach
        </select>
    </div>
    <div class="crm-toolbar__spacer"></div>
    <span class="crm-toolbar__count">Всего: {{ $orders->total() }}</span>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th>Номер</th>
                    <th>Поставщик</th>
                    <th>Склад</th>
                    <th>Статус</th>
                    <th class="text-end">Сумма</th>
                    <th>Дата</th>
                    <th class="text-end">Действия</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($orders as $order)
                    @php
                        $statusChip = match ($order->status->value) {
                            'draft' => 'crm-chip--grey',
                            'ordered' => '',
                            'received' => 'crm-chip--green',
                            default => 'crm-chip--red',
                        };
                    @endphp
                    <tr>
                        <td>
                            <a class="crm-table__main text-decoration-none" href="{{ route('purchases.show', $order) }}">{{ $order->number }}</a>
                            <div class="crm-table__sub">позиций: {{ $order->items_count ?? $order->items()->count() }}</div>
                        </td>
                        <td>{{ $order->supplier?->name ?? '—' }}</td>
                        <td>{{ $order->warehouse?->name ?? '—' }}</td>
                        <td><span class="crm-chip {{ $statusChip }}">{{ $crm_label('purchase_order_status', $order->status->value) }}</span></td>
                        <td class="text-end">{{ number_format((float) $order->total, 2, ',', ' ') }}</td>
                        <td class="text-secondary text-nowrap">{{ $order->order_date?->format('d.m.Y') ?? '—' }}</td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-ghost-secondary" href="{{ route('purchases.show', $order) }}" title="Открыть"><i class="ti ti-eye"></i></a>
                            @can('checkBoxPermission', 'purchases.manage')
                                @if ($order->status->value === 'draft')
                                    <form method="post" action="{{ route('purchases.order', $order) }}" class="d-inline">@csrf
                                        <button class="btn btn-sm btn-ghost-secondary" title="Разместить"><i class="ti ti-send"></i></button>
                                    </form>
                                @endif
                                @if (in_array($order->status->value, ['draft', 'ordered'], true))
                                    <form method="post" action="{{ route('purchases.cancel', $order) }}" class="d-inline"
                                          onsubmit="return confirm('Отменить заказ {{ $order->number }}?')">@csrf
                                        <button class="btn btn-sm btn-ghost-danger" title="Отменить"><i class="ti ti-x"></i></button>
                                    </form>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">@include('partials.empty-state', ['icon' => 'shopping-cart', 'title' => 'Закупок пока нет', 'text' => 'Создайте заказ поставщику: выберите позиции, разместите и примите на склад.'])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($orders->hasPages())
        <div class="card-footer">{{ $orders->links() }}</div>
    @endif
</div>

@can('checkBoxPermission', 'purchases.manage')
<div class="modal modal-blur fade" id="newOrder" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('purchases.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новый закупочный заказ</h5></div>
            <div class="modal-body">
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="po-number">Номер<span class="req">*</span></label>
                        <input type="text" id="po-number" name="number" class="form-control" value="{{ old('number', 'PO-'.now()->format('Ymd-His')) }}" maxlength="32" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="po-supplier">Поставщик<span class="req">*</span></label>
                        <select id="po-supplier" name="supplier_id" class="form-select" required>
                            <option value="">— выберите —</option>
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" @selected((string) old('supplier_id') === (string) $supplier->id)>{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="po-warehouse">Склад приёмки<span class="req">*</span></label>
                        <select id="po-warehouse" name="warehouse_id" class="form-select" required>
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected((string) old('warehouse_id') === (string) $warehouse->id)>{{ $warehouse->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="po-order-date">Дата заказа</label>
                        <input type="date" id="po-order-date" name="order_date" class="form-control" value="{{ old('order_date', now()->toDateString()) }}">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="po-expected">Ожидаемая поставка</label>
                        <input type="date" id="po-expected" name="expected_at" class="form-control" value="{{ old('expected_at') }}">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="po-notes">Примечание</label>
                        <input type="text" id="po-notes" name="notes" class="form-control" value="{{ old('notes') }}" maxlength="2000">
                    </div>
                </div>

                <div class="crm-form__section">
                    <h3>Позиции заказа</h3>
                    <div class="crm-form__desc">Цена по умолчанию — закупочная цена товара, её можно изменить.</div>
                    <div id="po-items">
                        <div class="crm-form__row po-item mb-2" data-po-item>
                            <div class="crm-form__field mb-0">
                                <label class="form-label">Товар<span class="req">*</span></label>
                                <select name="items[0][product_id]" class="form-select" required data-po-product>
                                    <option value="">— выберите —</option>
                                    @foreach ($products as $p)
                                        <option value="{{ $p->id }}" data-price="{{ $p->price }}">{{ $p->name }} ({{ $p->sku }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="crm-form__field mb-0">
                                <label class="form-label">Кол-во<span class="req">*</span></label>
                                <input type="number" name="items[0][quantity]" class="form-control" step="0.001" min="0.001" value="1" required data-po-quantity>
                            </div>
                            <div class="crm-form__field mb-0">
                                <label class="form-label">Цена</label>
                                <input type="number" name="items[0][unit_price]" class="form-control" step="0.01" min="0" value="0" data-po-price>
                            </div>
                            <div class="crm-form__field mb-0 d-flex align-items-end">
                                <button type="button" class="btn btn-ghost-danger" data-po-remove title="Убрать позицию"><i class="ti ti-trash"></i></button>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-ghost-secondary btn-sm mt-2" id="po-add-item"><i class="ti ti-plus me-1"></i>Добавить позицию</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Создать заказ</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var container = document.getElementById('po-items');
    var addBtn = document.getElementById('po-add-item');
    if (!container || !addBtn) return;

    function reindex() {
        container.querySelectorAll('[data-po-item]').forEach(function (row, i) {
            row.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace(/^items\[\d+\]/, 'items[' + i + ']');
            });
        });
    }

    addBtn.addEventListener('click', function () {
        var first = container.querySelector('[data-po-item]');
        if (!first) return;
        var clone = first.cloneNode(true);
        clone.querySelectorAll('input').forEach(function (input) { input.value = input.type === 'number' ? 1 : 0; });
        clone.querySelectorAll('select').forEach(function (select) { select.selectedIndex = 0; });
        container.appendChild(clone);
        reindex();
    });

    container.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-po-remove]');
        if (!btn) return;
        if (container.querySelectorAll('[data-po-item]').length === 1) return;
        btn.closest('[data-po-item]').remove();
        reindex();
    });

    container.addEventListener('change', function (event) {
        if (!event.target.matches('[data-po-product]')) return;
        var option = event.target.selectedOptions[0];
        var row = event.target.closest('[data-po-item]');
        var price = row && row.querySelector('[data-po-price]');
        if (option && price) price.value = option.getAttribute('data-price') || 0;
    });
});
</script>
@endcan
@endsection
