@extends('layouts.tabler')
@section('workspace_layout', 'directory')

@section('title', __('Склад — Товары и услуги'))

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Склад') }}</div>
            <h1 class="page-title mt-1">{{ __('Товары и услуги') }}</h1>
            <div class="text-secondary mt-1">{{ __('Номенклатура компании') }} {{ $products->count() }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('inventory.index') }}"><i class="ti ti-package me-1"></i>{{ __('Остатки') }}</a>
            @can('checkBoxPermission', 'inventory.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newProduct"><i class="ti ti-plus me-1"></i>{{ __('Добавить номенклатуру') }}</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-toolbar">
    <div class="input-icon">
        <input type="search" class="form-control crm-search-input" placeholder="{{ __("Поиск: название, артикул…") }}" aria-label="{{ __("Поиск товаров") }}"
               data-crm-filter="#products-table tbody tr" data-crm-filter-count="#products-count">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <div class="crm-toolbar__spacer"></div>
    <span class="crm-toolbar__count" id="products-count">{{ __('Показано') }} {{ $products->count() }}</span>
</div>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table" id="products-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('Номенклатура') }}</th>
                    <th scope="col">{{ __('Вид') }}</th>
                    <th scope="col">{{ __('Единица') }}</th>
                    <th scope="col" class="text-end">{{ __('Цена закупки') }}</th>
                    <th scope="col" class="text-end">{{ __('Минимум') }}</th>
                    <th scope="col" class="text-end">{{ __('Остаток') }}</th>
                    <th scope="col">{{ __('Статус') }}</th>
                    <th scope="col" class="text-end">{{ __('Действия') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    @php
                        $isService = $product->kind === \App\Shared\Enums\ProductKind::Service;
                        $stockTotal = (float) $product->getAttribute('stock_total');
                        $isLow = $isService === false && $product->min_stock > 0 && $stockTotal < (float) $product->min_stock;
                    @endphp
                    <tr>
                        <td>
                            <div class="crm-table__main">{{ $product->name }}</div>
                            <div class="crm-table__sub">{{ __('арт') }} {{ $product->sku }}@if ($product->description) · {{ \Illuminate\Support\Str::limit($product->description, 60) }}@endif</div>
                        </td>
                        <td>
                            <span class="crm-chip {{ $isService ? 'crm-chip--cyan' : 'crm-chip--grey' }}">
                                {{ $crm_label('product_kind', $product->kind->value) }}
                            </span>
                        </td>
                        <td><span class="crm-chip crm-chip--grey">{{ $product->unit }}</span></td>
                        <td class="text-end">{{ number_format((float) $product->price, 2, ',', ' ') }}</td>
                        <td class="text-end text-secondary">{{ $product->min_stock > 0 ? rtrim(rtrim(number_format((float) $product->min_stock, 3, '.', ''), '0'), '.') : '—' }}</td>
                        <td class="text-end">
                            @if ($isService)
                                <span class="text-secondary">—</span>
                            @else
                                <span class="crm-chip {{ $isLow ? 'crm-chip--red' : '' }}">{{ rtrim(rtrim(number_format($stockTotal, 3, '.', ''), '0'), '.') }}</span>
                                @if ($isLow)
                                    <div class="crm-table__sub">{{ __('ниже минимума') }}</div>
                                @endif
                            @endif
                        </td>
                        <td>
                            @if ($product->is_active)
                                <span class="crm-chip crm-chip--green">{{ __('активен') }}</span>
                            @else
                                <span class="crm-chip crm-chip--grey">{{ __('выключен') }}</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @can('checkBoxPermission', 'inventory.manage')
                            <button class="btn btn-sm btn-ghost-secondary" title="{{ __("Изменить") }}"
                                    data-bs-toggle="modal" data-bs-target="#editProduct{{ $product->id }}">
                                <i class="ti ti-pencil"></i>
                            </button>
                            <form method="post" action="{{ route('inventory.products.toggle', $product) }}" class="d-inline" data-confirm="{{ $product->is_active ? __('Выключить') : __('Включить') }} «{{ $product->name }}»? {{ __('История закупок и движений сохранится') }}." data-confirm-danger="1">
                                @csrf
                                <button class="btn btn-sm btn-ghost-secondary" title="{{ $product->is_active ? __('Выключить') : __('Включить') }}">
                                    <i class="ti ti-{{ $product->is_active ? 'toggle-right' : 'toggle-left' }}"></i>
                                </button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8">@include('partials.empty-state', ['icon' => 'box', 'title' => __('Номенклатуры пока нет'), 'text' => __('Добавьте товар или услугу: артикул, название, единицу измерения и цену.')])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('checkBoxPermission', 'inventory.manage')
@foreach ($products as $product)
<div class="modal modal-blur fade" id="editProduct{{ $product->id }}" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('inventory.products.update', $product) }}" data-submit-guard>
            @csrf
            @method('patch')
            <div class="modal-header"><h5 class="modal-title">{{ __('Изменить') }}{{ $product->name }}»</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Название') }}</h3>
                    <div class="crm-form__desc">{{ __('Артикул') }} ({{ $product->sku }}) {{ __('и вид номенклатуры не меняются: на них ссылаются движения и заказы') }}.</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ep-name-{{ $product->id }}">{{ __('Название') }}<span class="req">*</span></label>
                        <input type="text" id="ep-name-{{ $product->id }}" name="name" class="form-control" value="{{ $product->name }}" required>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="ep-description-{{ $product->id }}">{{ __('Описание') }}</label>
                        <input type="text" id="ep-description-{{ $product->id }}" name="description" class="form-control" value="{{ $product->description }}" maxlength="2000">
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Единицы и цены') }}</h3>
                    <div class="crm-form__desc">{{ __('Единица измерения, закупочная цена и минимальный остаток') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ep-unit-{{ $product->id }}">{{ __('Единица измерения') }}<span class="req">*</span></label>
                            <select id="ep-unit-{{ $product->id }}" name="unit" class="form-select" required>
                                @foreach ($units as $unit)
                                    <option value="{{ $unit }}" @selected($product->unit === $unit)>{{ $unit }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ep-price-{{ $product->id }}">{{ __('Цена закупки') }}<span class="req">*</span></label>
                            <input type="number" id="ep-price-{{ $product->id }}" name="price" class="form-control" value="{{ (float) $product->price }}" step="0.01" min="0" required>
                        </div>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="ep-min-{{ $product->id }}">{{ __('Минимальный остаток') }}</label>
                        <input type="number" id="ep-min-{{ $product->id }}" name="min_stock" class="form-control" value="{{ (float) $product->min_stock }}" step="0.001" min="0" @disabled($product->kind === \App\Shared\Enums\ProductKind::Service)>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Сохранить') }}</button>
            </div>
        </form>
    </div>
</div>
@endforeach

<div class="modal modal-blur fade" id="newProduct" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('inventory.products.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Добавить номенклатуру') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Номенклатура') }}</h3>
                    <div class="crm-form__desc">{{ __('Артикул и название — как товар находится в поиске и документах') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="np-sku">{{ __('Артикул') }}<span class="req">*</span></label>
                            <input type="text" id="np-sku" name="sku" class="form-control" value="{{ old('sku') }}" placeholder="SKU-0001" maxlength="64" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="np-name">{{ __('Название') }}<span class="req">*</span></label>
                            <input type="text" id="np-name" name="name" class="form-control" value="{{ old('name') }}" placeholder="{{ __("Бумага А4") }}" required>
                        </div>
                    </div>
                    <div class="crm-form__row mb-0">
                        <div class="crm-form__field">
                            <label class="form-label" for="np-kind">{{ __('Вид') }}<span class="req">*</span></label>
                            <select id="np-kind" name="kind" class="form-select" required>
                                @foreach ($kinds as $kind)
                                    <option value="{{ $kind->value }}" @selected(old('kind', 'goods') === $kind->value)>{{ $crm_label('product_kind', $kind->value) }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('Услуга не хранится на складе, но может входить в закупку') }}</div>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="np-description">{{ __('Описание') }}</label>
                            <input type="text" id="np-description" name="description" class="form-control" value="{{ old('description') }}" maxlength="2000" placeholder="{{ __("Характеристики, бренд…") }}">
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Единицы и цены') }}</h3>
                    <div class="crm-form__desc">{{ __('Единица измерения, закупка и минимум — сигнал к закупке ниже порога') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="np-unit">{{ __('Единица измерения') }}<span class="req">*</span></label>
                            <select id="np-unit" name="unit" class="form-select" required>
                                @foreach ($units as $unit)
                                    <option value="{{ $unit }}" @selected(old('unit', __('шт')) === $unit)>{{ $unit }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="np-price">{{ __('Цена закупки') }}<span class="req">*</span></label>
                            <input type="number" id="np-price" name="price" class="form-control" value="{{ old('price', '0') }}" step="0.01" min="0" required>
                        </div>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="np-min">{{ __('Минимальный остаток') }}</label>
                        <input type="number" id="np-min" name="min_stock" class="form-control" value="{{ old('min_stock', '0') }}" step="0.001" min="0">
                        <div class="form-text">{{ __('Ниже — сигнал к закупке') }}</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Добавить') }}</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
