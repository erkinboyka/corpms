@extends('layouts.tabler')
@section('workspace_layout', 'directory')

@section('title', __('Закупки'))

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Склад') }}</div>
            <h1 class="page-title mt-1">{{ __('Закупочные заказы') }}</h1>
            <div class="text-secondary mt-1">{{ __('Черновик → размещён → принят · приём оприходует товар на склад') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('inventory.suppliers') }}"><i class="ti ti-truck-delivery me-1"></i>{{ __('Поставщики') }}</a>
            @can('checkBoxPermission', 'purchases.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newOrder"><i class="ti ti-plus me-1"></i>{{ __('Новый заказ') }}</button>
            @endcan
        </div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <div class="input-icon">
        <input type="search" name="q" class="form-control crm-search-input" value="{{ $search }}" placeholder="{{ __("Поиск: номер заказа…") }}" aria-label="{{ __("Поиск заказа") }}">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <div>
        <label class="form-label" for="po-status">{{ __('Статус') }}</label>
        <select id="po-status" name="status" class="form-select" onchange="this.form.submit()">
            <option value="">{{ __('все') }}</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}" @selected($statusFilter === $status->value)>{{ $crm_label('purchase_order_status', $status->value) }}</option>
            @endforeach
        </select>
    </div>
    <div class="crm-toolbar__spacer"></div>
    <span class="crm-toolbar__count">{{ __('Всего') }} {{ $orders->total() }}</span>
</form>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('Номер') }}</th>
                    <th scope="col">{{ __('Поставщик') }}</th>
                    <th scope="col">{{ __('Склад') }}</th>
                    <th scope="col">{{ __('Статус') }}</th>
                    <th scope="col" class="text-end">{{ __('Сумма') }}</th>
                    <th scope="col">{{ __('Дата') }}</th>
                    <th scope="col" class="text-end">{{ __('Действия') }}</th>
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
                            <div class="crm-table__sub">{{ __('позиций') }} {{ $order->items_count ?? $order->items()->count() }}</div>
                        </td>
                        <td>{{ $order->supplier?->name ?? '—' }}</td>
                        <td>{{ $order->warehouse?->name ?? '—' }}</td>
                        <td><span class="crm-chip {{ $statusChip }}">{{ $crm_label('purchase_order_status', $order->status->value) }}</span></td>
                        <td class="text-end">{{ number_format((float) $order->total, 2, ',', ' ') }}</td>
                        <td class="text-secondary text-nowrap">{{ $order->order_date?->format('d.m.Y') ?? '—' }}</td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-ghost-secondary" href="{{ route('purchases.show', $order) }}" title="{{ __("Открыть") }}"><i class="ti ti-eye"></i></a>
                            @can('checkBoxPermission', 'purchases.manage')
                                @if ($order->status->value === 'draft')
                                    <form method="post" action="{{ route('purchases.order', $order) }}" class="d-inline">@csrf
                                        <button class="btn btn-sm btn-ghost-secondary" title="{{ __("Разместить") }}"><i class="ti ti-send"></i></button>
                                    </form>
                                @endif
                                @if (in_array($order->status->value, ['draft', 'ordered'], true))
                                    <form method="post" action="{{ route('purchases.cancel', $order) }}" class="d-inline"
                                          onsubmit="return confirm('{{ __('Отменить заказ') }} {{ $order->number }}?')">@csrf
                                        <button class="btn btn-sm btn-ghost-danger" title="{{ __("Отменить") }}"><i class="ti ti-x"></i></button>
                                    </form>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">@include('partials.empty-state', ['icon' => 'shopping-cart', 'title' => __('Закупок пока нет'), 'text' => __('Создайте заказ поставщику: выберите позиции, разместите и примите на склад.')])</td></tr>
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
            <div class="modal-header"><h5 class="modal-title">{{ __('Новый закупочный заказ') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="po-number">{{ __('Номер') }}<span class="req">*</span></label>
                        <input type="text" id="po-number" name="number" class="form-control" value="{{ old('number', 'PO-'.now()->format('Ymd-His')) }}" maxlength="32" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="po-supplier">{{ __('Поставщик') }}<span class="req">*</span></label>
                        <select id="po-supplier" name="supplier_id" class="form-select" required>
                            <option value="">{{ __('выберите') }}</option>
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" @selected((string) old('supplier_id') === (string) $supplier->id)>{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="po-warehouse">{{ __('Склад приёмки') }}<span class="req">*</span></label>
                        <select id="po-warehouse" name="warehouse_id" class="form-select" required>
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected((string) old('warehouse_id') === (string) $warehouse->id)>{{ $warehouse->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="po-order-date">{{ __('Дата заказа') }}</label>
                        <input type="date" id="po-order-date" name="order_date" class="form-control" value="{{ old('order_date', now()->toDateString()) }}">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="po-expected">{{ __('Ожидаемая поставка') }}</label>
                        <input type="date" id="po-expected" name="expected_at" class="form-control" value="{{ old('expected_at') }}">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="po-notes">{{ __('Примечание') }}</label>
                        <input type="text" id="po-notes" name="notes" class="form-control" value="{{ old('notes') }}" maxlength="2000">
                    </div>
                </div>

                <div class="crm-form__section">
                    <h3>{{ __('Позиции заказа') }}</h3>
                    <div class="crm-form__desc">{{ __('Цена по умолчанию — закупочная цена товара, её можно изменить') }}</div>
                    <div id="po-items">
                        <div class="crm-form__row po-item mb-2" data-po-item>
                            <div class="crm-form__field mb-0">
                                <label class="form-label">{{ __('Товар') }}<span class="req">*</span></label>
                                <select name="items[0][product_id]" class="form-select" required data-po-product>
                                    <option value="">{{ __('выберите') }}</option>
                                    @foreach ($products as $p)
                                        <option value="{{ $p->id }}" data-price="{{ $p->price }}">{{ $p->name }} ({{ $p->sku }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="crm-form__field mb-0">
                                <label class="form-label">{{ __('Кол-во') }}<span class="req">*</span></label>
                                <input type="number" name="items[0][quantity]" class="form-control" step="0.001" min="0.001" value="1" required data-po-quantity>
                            </div>
                            <div class="crm-form__field mb-0">
                                <label class="form-label">{{ __('Цена') }}</label>
                                <input type="number" name="items[0][unit_price]" class="form-control" step="0.01" min="0" value="0" data-po-price>
                            </div>
                            <div class="crm-form__field mb-0 d-flex align-items-end">
                                <button type="button" class="btn btn-ghost-danger" data-po-remove title="{{ __("Убрать позицию") }}"><i class="ti ti-trash"></i></button>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-ghost-secondary btn-sm mt-2" id="po-add-item"><i class="ti ti-plus me-1"></i>{{ __('Добавить позицию') }}</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Создать заказ') }}</button>
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

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    addBtn.addEventListener('click', function () {
        var first = container.querySelector('[data-po-item]');
        if (!first) return;
        var clone = first.cloneNode(true);
        clone.querySelectorAll('input').forEach(function (input) { input.value = input.type === 'number' ? 1 : 0; });
        clone.querySelectorAll('select').forEach(function (select) { select.selectedIndex = 0; });
        if (!reduceMotion) {
            clone.classList.remove('is-leaving');
            clone.classList.add('bm-enter');
            clone.addEventListener('animationend', function () { clone.classList.remove('bm-enter'); }, { once: true });
        }
        container.appendChild(clone);
        reindex();
    });

    container.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-po-remove]');
        if (!btn) return;
        if (container.querySelectorAll('[data-po-item]:not(.is-leaving)').length === 1) return;
        var row = btn.closest('[data-po-item]');
        if (!row || row.classList.contains('is-leaving')) return;
        if (reduceMotion) {
            row.remove();
            reindex();
            return;
        }
        row.classList.add('is-leaving');
        var done = false;
        var finish = function () {
            if (done) return;
            done = true;
            row.remove();
            reindex();
        };
        row.addEventListener('transitionend', finish, { once: true });
        setTimeout(finish, 320);
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
