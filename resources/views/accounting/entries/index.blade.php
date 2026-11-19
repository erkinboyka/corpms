@extends('layouts.tabler')
@section('workspace_layout', 'ledger')

@section('title', 'Бухгалтерия — Проводки')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Бухгалтерия</div>
            <h1 class="page-title mt-1">Журнал проводок</h1>
            <div class="text-secondary mt-1">Период {{ $month }} · всего {{ $entries->total() }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('accounting.chart.index') }}"><i class="ti ti-list-numbers me-1"></i>План счетов</a>
            <a class="btn btn-ghost-secondary me-1" href="{{ route('accounting.reports.index', ['month' => $month]) }}"><i class="ti ti-report-analytics me-1"></i>Отчёты</a>
            @can('checkBoxPermission', 'accounting.manage')
            <form method="post" action="{{ route('accounting.sync') }}" class="d-inline me-1">
                @csrf
                <input type="hidden" name="month" value="{{ $month }}">
                <button class="btn btn-ghost-secondary" title="Создать проводки для операций финансов без проводок">
                    <i class="ti ti-refresh me-1"></i>Из финансов ({{ $withoutEntry }})
                </button>
            </form>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newEntry"><i class="ti ti-plus me-1"></i>Новая проводка</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-book-2"></i>Проводок</div>
        <div class="crm-metric__value">{{ $entries->total() }}</div>
        <div class="crm-metric__hint">за {{ $month }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-refresh"></i>Из финансов</div>
        <div class="crm-metric__value">{{ $financePosted }}</div>
        <div class="crm-metric__hint">автоматические</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-clock"></i>Без проводок</div>
        <div class="crm-metric__value">{{ $withoutEntry }}</div>
        <div class="crm-metric__hint">операций финансов за месяц</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-purple);"><i class="ti ti-scale"></i>Оборот</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $turnover, 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">дебет за месяц</div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <input type="month" name="month" class="form-control" value="{{ $month }}" aria-label="Месяц" onchange="this.form.submit()">
    <button class="btn btn-ghost-secondary"><i class="ti ti-calendar me-1"></i>Показать месяц</button>
</form>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="2" class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th scope="col">№</th>
                    <th scope="col">Дата</th>
                    <th scope="col">Проводка</th>
                    <th scope="col">Источник</th>
                    <th scope="col" class="text-center">Строк</th>
                    <th scope="col" class="text-end">Сумма</th>
                    <th scope="col" class="text-end">Действия</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $entry)
                    <tr>
                        <td class="text-secondary">{{ $entry->id }}</td>
                        <td class="text-secondary">{{ $entry->entry_date?->format('d.m.Y') }}</td>
                        <td>
                            <a class="crm-table__main text-decoration-none" href="{{ route('accounting.entries.show', $entry) }}">{{ $entry->description ?: 'Проводка №'.$entry->id }}</a>
                            <div class="crm-table__sub">
                                @foreach ($entry->lines->take(2) as $line)
                                    {{ $line->account?->code }} {{ $line->debit > 0 ? 'Дт' : 'Кт' }}{{ number_format($line->amount(), 0, ',', ' ') }}@if (! $loop->last) · @endif
                                @endforeach
                            </div>
                        </td>
                        <td><span class="crm-chip {{ $entry->source->value === 'finance' ? 'crm-chip--cyan' : 'crm-chip--grey' }}">{{ $crm_label('acc_entry_source', $entry->source->value) }}</span></td>
                        <td class="text-center">{{ $entry->lines_count }}</td>
                        <td class="text-end fw-semibold">{{ number_format((float) $entry->lines->sum('debit'), 2, ',', ' ') }}</td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-ghost-secondary" href="{{ route('accounting.entries.show', $entry) }}" title="Открыть"><i class="ti ti-eye"></i></a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">@include('partials.empty-state', ['icon' => 'book-2', 'title' => 'Проводок нет', 'text' => 'Создайте проводку вручную или перенесите операции финансов кнопкой «Из финансов».'])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($entries->hasPages())
        <div class="card-footer">{{ $entries->links('pagination::tabler') }}</div>
    @endif
</div>

@can('checkBoxPermission', 'accounting.manage')
@php($advancedEntry = old('entry_mode') === 'advanced')
<div class="modal modal-blur fade" id="newEntry" tabindex="-1" aria-labelledby="newEntryTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" method="post" action="{{ route('accounting.entries.store') }}" data-submit-guard data-entry-form>
            @csrf
            <input type="hidden" name="entry_mode" value="{{ $advancedEntry ? 'advanced' : 'simple' }}">
            <div class="modal-header">
                <h2 class="modal-title" id="newEntryTitle">Новая проводка</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>
            <div class="modal-body d-grid gap-3">
                @if ($accounts->isEmpty())
                    <p class="alert alert-info mb-0">Сначала добавьте счета в <a href="{{ route('accounting.chart.index') }}">плане счетов</a>.</p>
                @endif
                <fieldset id="ae-simple" class="m-0" @disabled($advancedEntry) @if ($advancedEntry) hidden @endif>
                    <div class="row g-3">
                        @foreach (['debit_account_id' => 'Дебет', 'credit_account_id' => 'Кредит'] as $name => $label)
                            <div class="col-sm-6">
                                <label class="form-label" for="ae-{{ $name }}">{{ $label }}</label>
                                <select id="ae-{{ $name }}" name="{{ $name }}" class="form-select" required>
                                    <option value="">Выберите счёт</option>
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account->id }}" @selected((string) old($name) === (string) $account->id)>{{ $account->code }} · {{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                        <div class="col-sm-6">
                            <label class="form-label" for="ae-amount">Сумма</label>
                            <input id="ae-amount" name="amount" type="number" inputmode="decimal" step="0.01" min="0.01"
                                   max="999999999999" class="form-control" value="{{ old('amount') }}" placeholder="0,00" required>
                        </div>
                    </div>
                </fieldset>
                <fieldset id="ae-advanced" class="m-0" @disabled(! $advancedEntry) @if (! $advancedEntry) hidden @endif>
                    <legend class="h4 mb-1">Строки проводки</legend>
                    <p class="text-secondary small mb-3">Укажите дебет или кредит в каждой строке. Итоговые суммы должны совпасть.</p>
                    @for ($i = 0; $i < 4; $i++)
                        <div class="row g-2 mb-2">
                            <div class="col-12 col-sm-6">
                                <label class="form-label" for="ae-line-{{ $i }}">Счёт {{ $i + 1 }}</label>
                                <select id="ae-line-{{ $i }}" name="lines[{{ $i }}][account_id]" class="form-select">
                                    <option value="">Выберите счёт</option>
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account->id }}" @selected((string) old("lines.$i.account_id") === (string) $account->id)>{{ $account->code }} · {{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @foreach (['debit' => 'Дебет', 'credit' => 'Кредит'] as $side => $label)
                                <div class="col-6 col-sm-3">
                                    <label class="form-label" for="ae-{{ $side }}-{{ $i }}">{{ $label }}</label>
                                    <input id="ae-{{ $side }}-{{ $i }}" type="number" inputmode="decimal" name="lines[{{ $i }}][{{ $side }}]"
                                           class="form-control" step="0.01" min="0" value="{{ old("lines.$i.$side") }}" placeholder="0,00">
                                </div>
                            @endforeach
                        </div>
                    @endfor
                </fieldset>
                <div class="row g-3 border-top pt-1">
                    <div class="col-sm-4">
                        <label class="form-label" for="ae-date">Дата</label>
                        <input type="date" id="ae-date" name="entry_date" class="form-control" value="{{ old('entry_date', now()->toDateString()) }}" required>
                    </div>
                    <div class="col-sm-8">
                        <label class="form-label" for="ae-description">Комментарий <span class="text-secondary fw-normal">· необязательно</span></label>
                        <input type="text" id="ae-description" name="description" class="form-control" value="{{ old('description') }}" maxlength="255" placeholder="Например, поступление выручки">
                    </div>
                </div>
                <div>
                    <button type="button" class="btn btn-sm btn-ghost-secondary" data-entry-mode aria-controls="ae-advanced"
                            aria-expanded="{{ $advancedEntry ? 'true' : 'false' }}" hidden>{{ $advancedEntry ? 'Обычная проводка' : 'Нужно больше строк?' }}</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary" @disabled($accounts->isEmpty())>Провести</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection

@can('checkBoxPermission', 'accounting.manage')
    @push('scripts')
        <script src="/assets/js/accounting-entry.js?v1" defer></script>
    @endpush
@endcan
