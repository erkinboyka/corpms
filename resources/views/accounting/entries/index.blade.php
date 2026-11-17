@extends('layouts.tabler')

@section('title', 'Бухгалтерия — Проводки')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Бухгалтерия</div>
            <h2 class="page-title mt-1">Журнал проводок</h2>
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
        <div class="crm-metric__label" style="color: #4299e1;"><i class="ti ti-book-2"></i>Проводок</div>
        <div class="crm-metric__value">{{ $entries->total() }}</div>
        <div class="crm-metric__hint">за {{ $month }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #0b9b72;"><i class="ti ti-refresh"></i>Из финансов</div>
        <div class="crm-metric__value">{{ $financePosted }}</div>
        <div class="crm-metric__hint">автоматические</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #f76707;"><i class="ti ti-clock"></i>Без проводок</div>
        <div class="crm-metric__value">{{ $withoutEntry }}</div>
        <div class="crm-metric__hint">операций финансов за месяц</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #ae3ec9;"><i class="ti ti-scale"></i>Оборот</div>
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
        <table class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th>№</th>
                    <th>Дата</th>
                    <th>Проводка</th>
                    <th>Источник</th>
                    <th class="text-center">Строк</th>
                    <th class="text-end">Сумма</th>
                    <th class="text-end">Действия</th>
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
<div class="modal modal-blur fade" id="newEntry" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <form class="modal-content" method="post" action="{{ route('accounting.entries.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новая проводка</h5></div>
            <div class="modal-body">
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="ae-date">Дата<span class="req">*</span></label>
                        <input type="date" id="ae-date" name="entry_date" class="form-control" value="{{ old('entry_date', now()->toDateString()) }}" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ae-description">Описание</label>
                        <input type="text" id="ae-description" name="description" class="form-control" value="{{ old('description') }}" maxlength="255" placeholder="Поступление выручки">
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>Строки проводки</h3>
                    <div class="crm-form__desc">В каждой строке заполняется только одна колонка: дебет или кредит. Суммы должны совпадать.</div>
                    @for ($i = 0; $i < 4; $i++)
                        <div class="crm-form__row mb-2">
                            <div class="crm-form__field mb-0">
                                <label class="form-label">Счёт</label>
                                <select name="lines[{{ $i }}][account_id]" class="form-select" aria-label="Счёт строки {{ $i + 1 }}">
                                    <option value="">— выберите —</option>
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="crm-form__field mb-0">
                                <label class="form-label">Дебет</label>
                                <input type="number" name="lines[{{ $i }}][debit]" class="form-control" step="0.01" min="0" value="0">
                            </div>
                            <div class="crm-form__field mb-0">
                                <label class="form-label">Кредит</label>
                                <input type="number" name="lines[{{ $i }}][credit]" class="form-control" step="0.01" min="0" value="0">
                            </div>
                        </div>
                    @endfor
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Провести</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
