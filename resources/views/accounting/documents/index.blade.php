@extends('layouts.tabler')
@section('workspace_layout', 'directory')

@section('title', __('Бухгалтерия — Первичные документы'))

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Бухгалтерия') }}</div>
            <h1 class="page-title mt-1">{{ __('Первичные документы') }}</h1>
            <div class="text-secondary mt-1">{{ __('Счета, акты, платёжные поручения') }} {{ $documents->total() }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('accounting.entries.index') }}"><i class="ti ti-book-2 me-1"></i>{{ __('Проводки') }}</a>
            @can('checkBoxPermission', 'accounting.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newDocument"><i class="ti ti-plus me-1"></i>{{ __('Новый документ') }}</button>
            @endcan
        </div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <div class="input-icon">
        <input type="search" name="q" class="form-control crm-search-input" value="{{ $search }}" placeholder="{{ __("Номер или контрагент…") }}" aria-label="{{ __("Поиск документов") }}">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <select name="kind" class="form-select" aria-label="{{ __("Вид") }}" onchange="this.form.submit()">
        <option value="">{{ __('Все виды') }}</option>
        @foreach ($kinds as $kindOption)
            <option value="{{ $kindOption->value }}" @selected($kind === $kindOption->value)>{{ $crm_label('acc_document_kind', $kindOption->value) }}</option>
        @endforeach
    </select>
    <button class="btn btn-ghost-secondary"><i class="ti ti-search me-1"></i>{{ __('Найти') }}</button>
</form>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('Номер') }}</th>
                    <th scope="col">{{ __('Вид') }}</th>
                    <th scope="col">{{ __('Дата') }}</th>
                    <th scope="col">{{ __('Контрагент') }}</th>
                    <th scope="col" class="text-end">{{ __('Сумма') }}</th>
                    <th scope="col">{{ __('Проводка') }}</th>
                    <th scope="col" class="text-end">{{ __('Действия') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($documents as $document)
                    <tr>
                        <td><div class="crm-table__main">{{ $document->number }}</div></td>
                        <td><span class="crm-chip crm-chip--grey">{{ $crm_label('acc_document_kind', $document->kind->value) }}</span></td>
                        <td class="text-secondary">{{ $document->document_date?->format('d.m.Y') }}</td>
                        <td class="text-secondary">{{ $document->counterparty ?: '—' }}</td>
                        <td class="text-end">{{ number_format((float) $document->amount, 2, ',', ' ') }}</td>
                        <td>
                            @if ($document->entry)
                                <a class="text-decoration-none" href="{{ route('accounting.entries.show', $document->entry) }}">№{{ $document->entry->id }}</a>
                            @else
                                <span class="text-secondary">—</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @can('checkBoxPermission', 'accounting.manage')
                            <form method="post" action="{{ route('accounting.documents.destroy', $document) }}"
                                  onsubmit="return confirm('{{ __('Удалить документ                        ? Проводка останется') }}.')">
                                @csrf
                                @method('delete')
                                <button class="btn btn-sm btn-ghost-danger" title="{{ __("Удалить") }}"><i class="ti ti-trash"></i></button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">@include('partials.empty-state', ['icon' => 'files', 'title' => __('Документов нет'), 'text' => __('Добавьте первичный документ: счёт, акт или платёжное поручение.')])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($documents->hasPages())
        <div class="card-footer">{{ $documents->links('pagination::tabler') }}</div>
    @endif
</div>

@can('checkBoxPermission', 'accounting.manage')
<div class="modal modal-blur fade" id="newDocument" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('accounting.documents.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Новый первичный документ') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Документ') }}</h3>
                    <div class="crm-form__desc">{{ __('Номер, вид и дата — реквизиты первички') }}</div>
                    <div class="crm-form__row crm-form__row--3">
                        <div class="crm-form__field">
                            <label class="form-label" for="ad-number">{{ __('Номер') }}<span class="req">*</span></label>
                            <input type="text" id="ad-number" name="number" class="form-control" value="{{ old('number') }}" maxlength="32" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ad-kind">{{ __('Вид') }}<span class="req">*</span></label>
                            <select id="ad-kind" name="kind" class="form-select" required>
                                @foreach ($kinds as $kindOption)
                                    <option value="{{ $kindOption->value }}" @selected(old('kind', 'invoice') === $kindOption->value)>{{ $crm_label('acc_document_kind', $kindOption->value) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ad-date">{{ __('Дата') }}<span class="req">*</span></label>
                            <input type="date" id="ad-date" name="document_date" class="form-control" value="{{ old('document_date', now()->toDateString()) }}" required>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>{{ __('Сумма и контрагент') }}</h3>
                    <div class="crm-form__desc">{{ __('О чём документ и между кем — сумма рядом с контрагентом') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ad-amount">{{ __('Сумма') }}</label>
                            <input type="number" id="ad-amount" name="amount" class="form-control" value="{{ old('amount', 0) }}" step="0.01" min="0">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ad-counterparty">{{ __('Контрагент') }}</label>
                            <input type="text" id="ad-counterparty" name="counterparty" class="form-control" value="{{ old('counterparty') }}" maxlength="255">
                        </div>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="ad-entry">{{ __('Основание (проводка)') }}</label>
                        <select id="ad-entry" name="entry_id" class="form-select">
                            <option value="">{{ __('не связан') }}</option>
                            @foreach ($entries as $entry)
                                <option value="{{ $entry->id }}" @selected((string) old('entry_id') === (string) $entry->id)>№{{ $entry->id }} · {{ $entry->entry_date?->format('d.m.Y') }} · {{ $entry->description }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <label class="h3" for="ad-notes">{{ __('Примечание') }}</label>
                    <div class="crm-form__desc">{{ __('Короткая пометка о назначении документа') }}</div>
                    <div class="crm-form__field mb-0">
                        <input type="text" id="ad-notes" name="notes" class="form-control" value="{{ old('notes') }}" maxlength="2000">
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
