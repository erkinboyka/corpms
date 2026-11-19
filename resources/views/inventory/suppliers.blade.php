@extends('layouts.tabler')
@section('workspace_layout', 'directory')

@section('title', 'Склад — Поставщики')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Склад</div>
            <h1 class="page-title mt-1">Поставщики</h1>
            <div class="text-secondary mt-1">Контрагенты закупок · {{ $suppliers->count() }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('purchases.index') }}"><i class="ti ti-shopping-cart me-1"></i>Закупки</a>
            @can('checkBoxPermission', 'inventory.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newSupplier"><i class="ti ti-plus me-1"></i>Добавить поставщика</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-toolbar">
    <div class="input-icon">
        <input type="search" class="form-control crm-search-input" placeholder="Поиск: название…" aria-label="Поиск поставщиков"
               data-crm-filter="#suppliers-table tbody tr" data-crm-filter-count="#suppliers-count">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <div class="crm-toolbar__spacer"></div>
    <span class="crm-toolbar__count" id="suppliers-count">Показано {{ $suppliers->count() }}</span>
</div>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table" id="suppliers-table">
            <thead>
                <tr>
                    <th scope="col">Поставщик</th>
                    <th scope="col">Контакты</th>
                    <th scope="col">ИНН</th>
                    <th scope="col">Статус</th>
                    <th scope="col" class="text-end">Действия</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($suppliers as $supplier)
                    <tr>
                        <td>
                            <div class="crm-table__main"><i class="ti ti-truck-delivery me-1"></i>{{ $supplier->name }}</div>
                            @if ($supplier->notes)
                                <div class="crm-table__sub">{{ \Illuminate\Support\Str::limit($supplier->notes, 70) }}</div>
                            @endif
                        </td>
                        <td>
                            @if ($supplier->contact_person)
                                <div class="crm-table__main">{{ $supplier->contact_person }}</div>
                            @endif
                            <div class="crm-table__sub">
                                @if ($supplier->phone){{ $supplier->phone }}@endif
                                @if ($supplier->phone && $supplier->email) · @endif
                                @if ($supplier->email){{ $supplier->email }}@endif
                                @if (! $supplier->phone && ! $supplier->email) — @endif
                            </div>
                        </td>
                        <td class="text-secondary">{{ $supplier->inn ?: '—' }}</td>
                        <td>
                            @if ($supplier->is_active)
                                <span class="crm-chip crm-chip--green">активен</span>
                            @else
                                <span class="crm-chip crm-chip--grey">выключен</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @can('checkBoxPermission', 'inventory.manage')
                            <form method="post" action="{{ route('inventory.suppliers.toggle', $supplier) }}" class="d-inline"
                                  onsubmit="return confirm('{{ $supplier->is_active ? 'Выключить' : 'Включить' }} поставщика «{{ $supplier->name }}»?')">
                                @csrf
                                <button class="btn btn-sm btn-ghost-secondary" title="{{ $supplier->is_active ? 'Выключить' : 'Включить' }}">
                                    <i class="ti ti-{{ $supplier->is_active ? 'toggle-right' : 'toggle-left' }}"></i>
                                </button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'truck-delivery', 'title' => 'Поставщиков пока нет', 'text' => 'Добавьте поставщика, чтобы создавать закупочные заказы.'])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('checkBoxPermission', 'inventory.manage')
<div class="modal modal-blur fade" id="newSupplier" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('inventory.suppliers.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Добавить поставщика</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>Поставщик</h3>
                    <div class="crm-form__desc">Название и реквизиты — как в счетах и накладных.</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ns-name">Название<span class="req">*</span></label>
                        <input type="text" id="ns-name" name="name" class="form-control" value="{{ old('name') }}" placeholder='ООО «Поставка»' required>
                    </div>
                    <div class="crm-form__row mb-0">
                        <div class="crm-form__field">
                            <label class="form-label" for="ns-inn">ИНН</label>
                            <input type="text" id="ns-inn" name="inn" class="form-control" value="{{ old('inn') }}" maxlength="32">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ns-contact">Контактное лицо</label>
                            <input type="text" id="ns-contact" name="contact_person" class="form-control" value="{{ old('contact_person') }}">
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>Контакты</h3>
                    <div class="crm-form__desc">Телефон, почта и адрес — для заказов и сверок.</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ns-phone">Телефон</label>
                            <input type="text" id="ns-phone" name="phone" class="form-control" value="{{ old('phone') }}" maxlength="32">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ns-email">Email</label>
                            <input type="email" id="ns-email" name="email" class="form-control" value="{{ old('email') }}">
                        </div>
                    </div>
                    <div class="crm-form__row mb-0">
                        <div class="crm-form__field">
                            <label class="form-label" for="ns-address">Адрес</label>
                            <input type="text" id="ns-address" name="address" class="form-control" value="{{ old('address') }}" maxlength="1000">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ns-notes">Примечание</label>
                            <input type="text" id="ns-notes" name="notes" class="form-control" value="{{ old('notes') }}" maxlength="2000">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Добавить</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
