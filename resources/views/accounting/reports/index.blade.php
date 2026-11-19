@extends('layouts.tabler')
@section('workspace_layout', 'ledger')

@section('title', 'Бухгалтерия — Отчёты')

@php
    $monthCarbon = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month . '-01');
    $monthLabel = $monthCarbon->locale('ru')->isoFormat('MMMM YYYY');
    $prevMonth = $monthCarbon->copy()->subMonthNoOverflow()->format('Y-m');
    $nextMonth = $monthCarbon->copy()->addMonthNoOverflow()->format('Y-m');
    $prevUrl = route('accounting.reports.index') . '?month=' . $prevMonth;
    $nextUrl = route('accounting.reports.index') . '?month=' . $nextMonth;

    $fmt = static fn ($v): string => number_format((float) $v, 2, ',', ' ');
    $entriesUrl = route('accounting.entries.index', ['month' => $month]);

    $hasRows = $trialBalance->count() > 0;
    $plRows = $profitAndLoss['rows'];
    $hasPlRows = $plRows->count() > 0;
    $profit = (float) $profitAndLoss['profit'];
    $income = (float) $profitAndLoss['income'];
    $expense = (float) $profitAndLoss['expense'];
@endphp

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Бухгалтерия</div>
            <h1 class="page-title mt-1">Отчёты за {{ $monthLabel }}</h1>
            <div class="text-secondary mt-1">
                Период {{ \Illuminate\Support\Carbon::parse($from)->format('d.m.Y') }} — {{ \Illuminate\Support\Carbon::parse($to)->format('d.m.Y') }}
            </div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ $entriesUrl }}"><i class="ti ti-book-2 me-1"></i>Проводки</a>
            <a class="btn btn-ghost-secondary" href="{{ route('accounting.chart.index') }}"><i class="ti ti-list-numbers me-1"></i>План счетов</a>
        </div>
    </div>
</div>

{{-- ===== Переключатель периода ===== --}}
<form method="get" class="crm-toolbar d-print-none">
    <a href="{{ $prevUrl }}" class="btn btn-icon btn-ghost-secondary" aria-label="Предыдущий месяц" title="Предыдущий месяц">
        <i class="ti ti-chevron-left"></i>
    </a>
    <input type="month" name="month" class="form-control" value="{{ $month }}" aria-label="Месяц" onchange="this.form.submit()">
    <button class="btn btn-ghost-secondary" type="submit"><i class="ti ti-calendar me-1"></i>Показать месяц</button>
    <a href="{{ $nextUrl }}" class="btn btn-icon btn-ghost-secondary" aria-label="Следующий месяц" title="Следующий месяц">
        <i class="ti ti-chevron-right"></i>
    </a>
</form>

{{-- ===== KPI-лента периода ===== --}}
<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-arrow-down-left"></i>Доходы</div>
        <div class="crm-metric__value">{{ $fmt($income) }}</div>
        <div class="crm-metric__hint">кредитовые обороты доходных счетов</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-danger);"><i class="ti ti-arrow-up-right"></i>Расходы</div>
        <div class="crm-metric__value">{{ $fmt($expense) }}</div>
        <div class="crm-metric__hint">дебетовые обороты расходных счетов</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: {{ $profit >= 0 ? 'var(--crm-success)' : 'var(--crm-danger)'; }}"><i class="ti ti-scale {{ $profit >= 0 ? '' : '-off' }}"></i>Финансовый результат</div>
        <div class="crm-metric__value">{{ $fmt($profit) }}</div>
        <div class="crm-metric__hint">{{ $profit >= 0 ? 'прибыль за период' : 'убыток за период' }} · {{ $monthLabel }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-building-bank"></i>Активы (баланс)</div>
        <div class="crm-metric__value">{{ $fmt($balanceSheet['assets']) }}</div>
        <div class="crm-metric__hint">
            <span class="crm-chip {{ $balanceSheet['balanced'] ? 'crm-chip--green' : 'crm-chip--red' }}">
                {{ $balanceSheet['balanced'] ? 'сходится' : 'расхождение' }}
            </span>
        </div>
    </div>
</div>

<div class="row g-3">
    {{-- ===== Отчёт о прибылях и убытках ===== --}}
    <div class="col-lg-7">
        <div class="card crm-panel h-100">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">Отчёт о прибылях и убытках</div>
                    <div class="crm-panel__sub">доходы и расходы за период · {{ $monthLabel }}</div>
                </div>
                <span class="crm-chip {{ $profit >= 0 ? 'crm-chip--green' : 'crm-chip--red' }}">
                    {{ $profit >= 0 ? 'прибыль' : 'убыток' }} {{ $fmt($profit) }}
                </span>
            </div>
            @if ($hasPlRows)
                <div class="table-responsive">
                    <table data-record-key="0" class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th scope="col">Счёт</th>
                                <th scope="col" class="text-end">Оборот</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($plRows as $row)
                                <tr>
                                    <td>
                                        <div class="crm-table__main">{{ $row->code }} {{ $row->name }}</div>
                                        <span class="crm-chip crm-chip--grey">{{ $crm_label('acc_account_kind', $row->kind->value) }}</span>
                                    </td>
                                    <td class="text-end fw-semibold {{ (float) $row->balance >= 0 ? '' : 'text-danger' }}">
                                        {{ $fmt(abs((float) $row->balance)) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="col">Доходы</th>
                                <th scope="col" class="text-end text-success">{{ $fmt($income) }}</th>
                            </tr>
                            <tr>
                                <th scope="col">Расходы</th>
                                <th scope="col" class="text-end text-danger">{{ $fmt($expense) }}</th>
                            </tr>
                            <tr>
                                <th scope="col">Финансовый результат</th>
                                <th scope="col" class="text-end {{ $profit >= 0 ? 'text-success' : 'text-danger' }}">{{ $fmt($profit) }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @else
                <div class="card-body d-flex flex-column">
                    @include('partials.empty-state', [
                        'icon' => 'receipt-off',
                        'title' => 'Проводок за период нет',
                        'text' => 'ОиУ строится из проводок по доходным и расходным счетам. Когда вы внесёте проводки за ' . $monthLabel . ', отчёт заполнится автоматически.',
                        'actionUrl' => $entriesUrl,
                        'actionLabel' => 'Перейти к проводкам',
                    ])
                </div>
            @endif
        </div>
    </div>

    {{-- ===== Баланс ===== --}}
    <div class="col-lg-5">
        <div class="card crm-panel h-100">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">Баланс</div>
                    <div class="crm-panel__sub">на {{ \Illuminate\Support\Carbon::parse($to)->format('d.m.Y') }}</div>
                </div>
                <span class="crm-chip {{ $balanceSheet['balanced'] ? 'crm-chip--green' : 'crm-chip--red' }}">
                    {{ $balanceSheet['balanced'] ? 'сходится' : 'расхождение' }}
                </span>
            </div>
            <div class="card-body d-flex flex-column">
                <div class="crm-kv">
                    <div class="crm-kv__row"><span>Активы</span><strong>{{ $fmt($balanceSheet['assets']) }}</strong></div>
                    <div class="crm-kv__row"><span>Пассивы</span><strong>{{ $fmt($balanceSheet['liabilities']) }}</strong></div>
                    <div class="crm-kv__row"><span>Капитал</span><strong>{{ $fmt($balanceSheet['equity']) }}</strong></div>
                    <div class="crm-kv__row"><span>Финансовый результат</span><strong class="{{ $profit >= 0 ? 'text-success' : 'text-danger' }}">{{ $fmt($balanceSheet['profit']) }}</strong></div>
                </div>
                <div class="crm-kv crm-kv--total mt-2">
                    <div class="crm-kv__row">
                        <span>Пассивы + капитал + результат</span>
                        <strong>{{ $fmt($balanceSheet['liabilities'] + (float) $balanceSheet['equity'] + (float) $balanceSheet['profit']) }}</strong>
                    </div>
                </div>
                <div class="form-text mt-auto pt-3">
                    Упрощённый баланс: активы = пассивы + капитал + финансовый результат.
                </div>
            </div>
        </div>
    </div>

    {{-- ===== Оборотно-сальдовая ведомость ===== --}}
    <div class="col-12">
        <div class="card crm-panel">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">Оборотно-сальдовая ведомость</div>
                    <div class="crm-panel__sub">дебет / кредит / сальдо по счетам за период</div>
                </div>
                @if ($hasRows)
                    <span class="crm-chip crm-chip--cyan">{{ $trialBalance->count() }} счетов</span>
                @endif
            </div>
            @if ($hasRows)
                <div class="table-responsive">
                    <table data-record-key="0" class="table table-hover table-vcenter card-table">
                        <thead>
                            <tr>
                                <th scope="col">Счёт</th>
                                <th scope="col">Вид</th>
                                <th scope="col" class="text-end">Дебет</th>
                                <th scope="col" class="text-end">Кредит</th>
                                <th scope="col" class="text-end">Сальдо</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($trialBalance as $row)
                                <tr>
                                    <td>
                                        <div class="crm-table__main">{{ $row->code }} {{ $row->name }}</div>
                                    </td>
                                    <td><span class="crm-chip crm-chip--grey">{{ $crm_label('acc_account_kind', $row->kind->value) }}</span></td>
                                    <td class="text-end">{{ $fmt($row->debit_turnover) }}</td>
                                    <td class="text-end">{{ $fmt($row->credit_turnover) }}</td>
                                    <td class="text-end fw-semibold {{ (float) $row->balance >= 0 ? '' : 'text-danger' }}">{{ $fmt($row->balance) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="col" colspan="2">Итого</th>
                                <th scope="col" class="text-end">{{ $fmt($trialTotals['debit']) }}</th>
                                <th scope="col" class="text-end">{{ $fmt($trialTotals['credit']) }}</th>
                                <th scope="col" class="text-end">{{ $fmt($trialTotals['balance']) }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @else
                <div class="card-body">
                    @include('partials.empty-state', [
                        'icon' => 'table-off',
                        'title' => 'Ведомость пуста за ' . $monthLabel,
                        'text' => 'Оборотка собирается из проводок по плану счетов. Внесите проводки за период или заполните план счетов — ведомость построится автоматически.',
                        'actionUrl' => $entriesUrl,
                        'actionLabel' => 'Перейти к проводкам',
                    ])
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
