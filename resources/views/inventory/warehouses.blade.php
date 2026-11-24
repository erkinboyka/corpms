@extends('layouts.tabler')
@section('workspace_layout', 'directory')

@section('title', __('Склад — Склады'))

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Склад') }}</div>
            <h1 class="page-title mt-1">{{ __('Склады') }}</h1>
            <div class="text-secondary mt-1">{{ __('Места хранения компании') }} {{ $warehouses->count() }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('inventory.index') }}"><i class="ti ti-package me-1"></i>{{ __('Остатки') }}</a>
            @can('checkBoxPermission', 'inventory.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newWarehouse"><i class="ti ti-plus me-1"></i>{{ __('Добавить склад') }}</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-toolbar">
    <div class="input-icon">
        <input type="search" class="form-control crm-search-input" placeholder="{{ __("Поиск: название, код…") }}" aria-label="{{ __("Поиск складов") }}"
               data-crm-filter="#warehouses-table tbody tr" data-crm-filter-count="#warehouses-count">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <div class="crm-toolbar__spacer"></div>
    <span class="crm-toolbar__count" id="warehouses-count">{{ __('Показано') }} {{ $warehouses->count() }}</span>
</div>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table" id="warehouses-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('Склад') }}</th>
                    <th scope="col">{{ __('Адрес') }}</th>
                    <th scope="col" class="text-end">{{ __('Позиций с остатком') }}</th>
                    <th scope="col">{{ __('Статус') }}</th>
                    <th scope="col" class="text-end">{{ __('Действия') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($warehouses as $warehouse)
                    <tr>
                        <td>
                            <div class="crm-table__main"><i class="ti ti-building-warehouse me-1"></i>{{ $warehouse->name }}</div>
                            <div class="crm-table__sub">{{ __('код') }} {{ $warehouse->code }}</div>
                        </td>
                        <td class="text-secondary">{{ $warehouse->address ?: '—' }}</td>
                        <td class="text-end"><span class="crm-chip">{{ $stocks[$warehouse->id] ?? 0 }}</span></td>
                        <td>
                            @if ($warehouse->is_active)
                                <span class="crm-chip crm-chip--green">{{ __('активен') }}</span>
                            @else
                                <span class="crm-chip crm-chip--grey">{{ __('выключен') }}</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @can('checkBoxPermission', 'inventory.manage')
                            <form method="post" action="{{ route('inventory.warehouses.toggle', $warehouse) }}" class="d-inline" data-confirm="{{ $warehouse->is_active ? __('Выключить') : __('Включить') }} {{ __('склад') }} «{{ $warehouse->name }}»?" data-confirm-danger="1">
                                @csrf
                                <button class="btn btn-sm btn-ghost-secondary" title="{{ $warehouse->is_active ? __('Выключить') : __('Включить') }}">
                                    <i class="ti ti-{{ $warehouse->is_active ? 'toggle-right' : 'toggle-left' }}"></i>
                                </button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'building-warehouse', 'title' => __('Складов пока нет'), 'text' => __('Создайте первый склад — он нужен для приёма и списания товара.')])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('checkBoxPermission', 'inventory.manage')
<div class="modal modal-blur fade" id="newWarehouse" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('inventory.warehouses.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Добавить склад') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Склад') }}</h3>
                    <div class="crm-form__desc">{{ __('Код и название — как в накладных и остатках') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="nw-code">{{ __('Код') }}<span class="req">*</span></label>
                            <input type="text" id="nw-code" name="code" class="form-control" value="{{ old('code') }}" placeholder="MAIN" maxlength="32" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nw-name">{{ __('Название') }}<span class="req">*</span></label>
                            <input type="text" id="nw-name" name="name" class="form-control" value="{{ old('name') }}" placeholder="{{ __("Основной склад") }}" required>
                        </div>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="nw-address">{{ __('Адрес') }}</label>
                        <input type="text" id="nw-address" name="address" class="form-control" value="{{ old('address') }}" maxlength="1000" placeholder="{{ __("г. Москва, ул. Складская, 1") }}">
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
