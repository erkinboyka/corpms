@extends('layouts.tabler')
@section('workspace_layout', 'ledger')

@section('title', __('Склад — Остатки'))

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Склад') }}</div>
            <h1 class="page-title mt-1">{{ __('Остатки') }}</h1>
            <div class="text-secondary mt-1">{{ __('Остаток = сумма журнала движений · товары с минимумом подсвечены') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('inventory.movements') }}"><i class="ti ti-arrows-exchange me-1"></i>{{ __('Движения') }}</a>
            <a class="btn btn-ghost-secondary me-1" href="{{ route('purchases.index') }}"><i class="ti ti-shopping-cart me-1"></i>{{ __('Закупки') }}</a>
            @can('checkBoxPermission', 'inventory.move')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newMovement"><i class="ti ti-plus me-1"></i>{{ __('Операция со складом') }}</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-box"></i>{{ __('Товаров') }}</div>
        <div class="crm-metric__value">{{ $metrics['products'] }}</div>
        <div class="crm-metric__hint">{{ __('активной номенклатуры') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-building-warehouse"></i>{{ __('Складов') }}</div>
        <div class="crm-metric__value">{{ $metrics['warehouses'] }}</div>
        <div class="crm-metric__hint">{{ __('в компании') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-alert-triangle"></i>{{ __('Ниже минимума') }}</div>
        <div class="crm-metric__value">{{ $metrics['low'] }}</div>
        <div class="crm-metric__hint">{{ __('товаров требуют закупки') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-purple);"><i class="ti ti-coins"></i>{{ __('Стоимость остатков') }}</div>
        <div class="crm-metric__value" style="font-size: 1.1rem; padding-top: 6px;">{{ number_format($metrics['stockValue'], 2, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ __('по цене закупки') }}</div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <div>
        <label class="form-label" for="inv-warehouse">{{ __('Склад') }}</label>
        <select id="inv-warehouse" name="warehouse" class="form-select" onchange="this.form.submit()">
            @forelse ($warehouses as $w)
                <option value="{{ $w->id }}" @selected($warehouse !== null && $warehouse->id === $w->id)>{{ $w->name }} ({{ $w->code }})</option>
            @empty
                <option value="">{{ __('нет складов') }}</option>
            @endforelse
        </select>
    </div>
    <div class="crm-toolbar__spacer"></div>
    @can('checkBoxPermission', 'inventory.manage')
    <a class="btn btn-ghost-secondary" href="{{ route('inventory.warehouses') }}"><i class="ti ti-building-warehouse me-1"></i>{{ __('Справочник складов') }}</a>
    @endcan
</form>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('Товар') }}</th>
                    <th scope="col" class="text-end">{{ __('Остаток') }}</th>
                    <th scope="col" class="text-end">{{ __('Минимум') }}</th>
                    <th scope="col" class="text-end">{{ __('Цена') }}</th>
                    <th scope="col">{{ __('Статус') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    @php
                        $qty = (float) ($stock[$product->id] ?? 0);
                        $isLow = $product->min_stock > 0 && $qty < (float) $product->min_stock;
                    @endphp
                    <tr>
                        <td>
                            <div class="crm-table__main">{{ $product->name }}</div>
                            <div class="crm-table__sub">{{ __('арт') }} {{ $product->sku }} · {{ $product->unit }}</div>
                        </td>
                        <td class="text-end">
                            <span class="crm-chip {{ $isLow ? 'crm-chip--red' : '' }}">{{ rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') }}</span>
                        </td>
                        <td class="text-end text-secondary">{{ $product->min_stock > 0 ? rtrim(rtrim(number_format((float) $product->min_stock, 3, '.', ''), '0'), '.') : '—' }}</td>
                        <td class="text-end">{{ number_format((float) $product->price, 2, ',', ' ') }}</td>
                        <td>
                            @if ($isLow)
                                <span class="crm-chip crm-chip--red">{{ __('ниже минимума') }}</span>
                            @elseif ($qty > 0)
                                <span class="crm-chip crm-chip--green">{{ __('в наличии') }}</span>
                            @else
                                <span class="crm-chip crm-chip--grey">{{ __('нет остатка') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'box', 'title' => __('Товаров пока нет'), 'text' => __('Добавьте номенклатуру во вкладке «Товары», затем запишите приход.'), 'actionUrl' => route('inventory.products'), 'actionLabel' => __('К товарам')])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('checkBoxPermission', 'inventory.move')
<div class="modal modal-blur fade" id="newMovement" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('inventory.movements.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Операция со складом') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Операция') }}</h3>
                    <div class="crm-form__desc">{{ __('Тип операции задаёт знак движения — приход, расход, корректировка или списание') }}</div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="mv-type">{{ __('Тип операции') }}<span class="req">*</span></label>
                        <select id="mv-type" name="type" class="form-select" required>
                            <option value="in" @selected(old('type') === 'in')>{{ __('Приход') }}</option>
                            <option value="out" @selected(old('type') === 'out')>{{ __('Расход') }}</option>
                            <option value="adjustment" @selected(old('type') === 'adjustment')>{{ __('Корректировка (со знаком)') }}</option>
                            <option value="write_off" @selected(old('type') === 'write_off')>{{ __('Списание') }}</option>
                        </select>
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>{{ __('Где и что') }}</h3>
                    <div class="crm-form__desc">{{ __('Склад и товар рядом — что и куда движется') }}</div>
                    <div class="crm-form__row mb-0">
                        <div class="crm-form__field">
                            <label class="form-label" for="mv-warehouse">{{ __('Склад') }}<span class="req">*</span></label>
                            <select id="mv-warehouse" name="warehouse_id" class="form-select" required>
                                @foreach ($warehouses as $w)
                                    <option value="{{ $w->id }}" @selected((string) old('warehouse_id') === (string) $w->id)>{{ $w->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="mv-product">{{ __('Товар') }}<span class="req">*</span></label>
                            <select id="mv-product" name="product_id" class="form-select" required>
                                @foreach ($products as $p)
                                    <option value="{{ $p->id }}" @selected((string) old('product_id') === (string) $p->id)>{{ $p->name }} ({{ $p->sku }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Количество и стоимость') }}</h3>
                    <div class="crm-form__desc">{{ __('Для корректировки укажите знак: приход «+», списание «−') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="mv-quantity">{{ __('Количество') }}<span class="req">*</span></label>
                            <input type="number" id="mv-quantity" name="quantity" class="form-control" value="{{ old('quantity') }}" step="0.001" min="0.001" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="mv-price">{{ __('Цена единицы') }}</label>
                            <input type="number" id="mv-price" name="unit_price" class="form-control" value="{{ old('unit_price', '0') }}" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="mv-note">{{ __('Комментарий') }}</label>
                        <input type="text" id="mv-note" name="note" class="form-control" value="{{ old('note') }}" maxlength="1000" placeholder="{{ __("Основание операции") }}">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Записать движение') }}</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
