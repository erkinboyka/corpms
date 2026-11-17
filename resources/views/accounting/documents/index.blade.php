@extends('layouts.tabler')

@section('title', 'Бухгалтерия — Первичные документы')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Бухгалтерия</div>
            <h2 class="page-title mt-1">Первичные документы</h2>
            <div class="text-secondary mt-1">Счета, акты, платёжные поручения · {{ $documents->total() }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('accounting.entries.index') }}"><i class="ti ti-book-2 me-1"></i>Проводки</a>
            @can('checkBoxPermission', 'accounting.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newDocument"><i class="ti ti-plus me-1"></i>Новый документ</button>
            @endcan
        </div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <div class="input-icon">
        <input type="search" name="q" class="form-control crm-search-input" value="{{ $search }}" placeholder="Номер или контрагент…" aria-label="Поиск документов">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <select name="kind" class="form-select" aria-label="Вид" onchange="this.form.submit()">
        <option value="">Все виды</option>
        @foreach ($kinds as $kindOption)
            <option value="{{ $kindOption->value }}" @selected($kind === $kindOption->value)>{{ $crm_label('acc_document_kind', $kindOption->value) }}</option>
        @endforeach
    </select>
    <button class="btn btn-ghost-secondary"><i class="ti ti-search me-1"></i>Найти</button>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th>Номер</th>
                    <th>Вид</th>
                    <th>Дата</th>
                    <th>Контрагент</th>
                    <th class="text-end">Сумма</th>
                    <th>Проводка</th>
                    <th class="text-end">Действия</th>
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
                                  onsubmit="return confirm('Удалить документ {{ $document->number }}? Проводка останется.')">
                                @csrf
                                @method('delete')
                                <button class="btn btn-sm btn-ghost-danger" title="Удалить"><i class="ti ti-trash"></i></button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">@include('partials.empty-state', ['icon' => 'files', 'title' => 'Документов нет', 'text' => 'Добавьте первичный документ: счёт, акт или платёжное поручение.'])</td></tr>
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
            <div class="modal-header"><h5 class="modal-title">Новый первичный документ</h5></div>
            <div class="modal-body">
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="ad-number">Номер<span class="req">*</span></label>
                        <input type="text" id="ad-number" name="number" class="form-control" value="{{ old('number') }}" maxlength="32" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ad-kind">Вид<span class="req">*</span></label>
                        <select id="ad-kind" name="kind" class="form-select" required>
                            @foreach ($kinds as $kindOption)
                                <option value="{{ $kindOption->value }}" @selected(old('kind', 'invoice') === $kindOption->value)>{{ $crm_label('acc_document_kind', $kindOption->value) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ad-date">Дата<span class="req">*</span></label>
                        <input type="date" id="ad-date" name="document_date" class="form-control" value="{{ old('document_date', now()->toDateString()) }}" required>
                    </div>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="ad-amount">Сумма</label>
                        <input type="number" id="ad-amount" name="amount" class="form-control" value="{{ old('amount', 0) }}" step="0.01" min="0">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ad-counterparty">Контрагент</label>
                        <input type="text" id="ad-counterparty" name="counterparty" class="form-control" value="{{ old('counterparty') }}" maxlength="255">
                    </div>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="ad-entry">Основание (проводка)</label>
                    <select id="ad-entry" name="entry_id" class="form-select">
                        <option value="">— не связан —</option>
                        @foreach ($entries as $entry)
                            <option value="{{ $entry->id }}" @selected((string) old('entry_id') === (string) $entry->id)>№{{ $entry->id }} · {{ $entry->entry_date?->format('d.m.Y') }} · {{ $entry->description }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="ad-notes">Примечание</label>
                    <input type="text" id="ad-notes" name="notes" class="form-control" value="{{ old('notes') }}" maxlength="2000">
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
