@extends('layouts.tabler')
@section('workspace_layout', 'ledger')

@section('title', __('Склад — Движения'))

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Склад') }}</div>
            <h1 class="page-title mt-1">{{ __('Движения товаров') }}</h1>
            <div class="text-secondary mt-1">{{ __('Неизменяемый журнал: приход, расход, корректировка, списание') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('inventory.index') }}"><i class="ti ti-package me-1"></i>{{ __('Остатки') }}</a>
            @can('checkBoxPermission', 'inventory.move')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newMovement"><i class="ti ti-plus me-1"></i>{{ __('Операция со складом') }}</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-toolbar">
    <div class="input-icon">
        <input type="search" class="form-control crm-search-input" placeholder="{{ __("Поиск: товар, склад, комментарий…") }}" aria-label="{{ __("Поиск движений") }}"
               data-crm-filter="#movements-table tbody tr" data-crm-filter-count="#movements-count">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <div class="crm-toolbar__spacer"></div>
    <span class="crm-toolbar__count" id="movements-count">{{ __('Показано') }} {{ $movements->count() }}</span>
</div>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="1" class="table table-hover table-vcenter card-table" id="movements-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('Дата') }}</th>
                    <th scope="col">{{ __('Товар') }}</th>
                    <th scope="col">{{ __('Склад') }}</th>
                    <th scope="col">{{ __('Тип') }}</th>
                    <th scope="col" class="text-end">{{ __('Количество') }}</th>
                    <th scope="col">{{ __('Основание') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($movements as $movement)
                    @php
                        $isPositive = (float) $movement->quantity > 0;
                        $chipClass = match ($movement->type->value) {
                            'in' => 'crm-chip--green',
                            'out' => '',
                            'write_off' => 'crm-chip--red',
                            default => 'crm-chip--grey',
                        };
                    @endphp
                    <tr>
                        <td class="text-secondary text-nowrap">{{ $movement->moved_at?->format('d.m.Y H:i') ?: $movement->created_at?->format('d.m.Y H:i') }}</td>
                        <td>
                            <div class="crm-table__main">{{ $movement->product?->name ?? '—' }}</div>
                            <div class="crm-table__sub">{{ __('арт') }} {{ $movement->product?->sku ?? '—' }}</div>
                        </td>
                        <td>{{ $movement->warehouse?->name ?? '—' }}</td>
                        <td><span class="crm-chip {{ $chipClass }}">{{ $crm_label('stock_movement_type', $movement->type->value) }}</span></td>
                        <td class="text-end">
                            <strong class="{{ $isPositive ? 'text-success' : 'text-danger' }}">
                                {{ $isPositive ? '+' : '' }}{{ rtrim(rtrim(number_format((float) $movement->quantity, 3, '.', ''), '0'), '.') }}
                            </strong>
                        </td>
                        <td>
                            <div class="crm-table__main">{{ $movement->reference ?: '—' }}</div>
                            @if ($movement->note)
                                <div class="crm-table__sub">{{ \Illuminate\Support\Str::limit($movement->note, 60) }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">@include('partials.empty-state', ['icon' => 'arrows-exchange', 'title' => __('Движений пока нет'), 'text' => __('Запишите первый приход — остатки посчитаются автоматически.')])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($movements->hasPages())
        <div class="card-footer">{{ $movements->links() }}</div>
    @endif
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
                    <div class="crm-form__desc">{{ __('Списание не уйдёт в минус: сервис проверит остаток') }}</div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="mv2-type">{{ __('Тип операции') }}<span class="req">*</span></label>
                        <select id="mv2-type" name="type" class="form-select" required>
                            @foreach ($types as $type)
                                <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ $crm_label('stock_movement_type', $type->value) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>{{ __('Где и что') }}</h3>
                    <div class="crm-form__desc">{{ __('Склад и товар рядом — что и куда движется') }}</div>
                    <div class="crm-form__row mb-0">
                        <div class="crm-form__field">
                            <label class="form-label" for="mv2-warehouse">{{ __('Склад') }}<span class="req">*</span></label>
                            <select id="mv2-warehouse" name="warehouse_id" class="form-select" required>
                                @foreach ($warehouses as $w)
                                    <option value="{{ $w->id }}" @selected((string) old('warehouse_id') === (string) $w->id)>{{ $w->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="mv2-product">{{ __('Товар') }}<span class="req">*</span></label>
                            <select id="mv2-product" name="product_id" class="form-select" required>
                                @foreach ($products as $p)
                                    <option value="{{ $p->id }}" @selected((string) old('product_id') === (string) $p->id)>{{ $p->name }} ({{ $p->sku }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Количество и стоимость') }}</h3>
                    <div class="crm-form__desc">{{ __('Сколько единиц и по какой цене') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="mv2-quantity">{{ __('Количество') }}<span class="req">*</span></label>
                            <input type="number" id="mv2-quantity" name="quantity" class="form-control" value="{{ old('quantity') }}" step="0.001" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="mv2-price">{{ __('Цена единицы') }}</label>
                            <input type="number" id="mv2-price" name="unit_price" class="form-control" value="{{ old('unit_price', '0') }}" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="mv2-note">{{ __('Комментарий') }}</label>
                        <input type="text" id="mv2-note" name="note" class="form-control" value="{{ old('note') }}" maxlength="1000" placeholder="{{ __("Основание операции") }}">
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
