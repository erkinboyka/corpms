@extends('layouts.tabler')

@section('title', 'Бухгалтерия — Отчёты')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Бухгалтерия</div>
            <h2 class="page-title mt-1">Отчёты за {{ $month }}</h2>
            <div class="text-secondary mt-1">Период {{ \Illuminate\Support\Carbon::parse($from)->format('d.m.Y') }} — {{ \Illuminate\Support\Carbon::parse($to)->format('d.m.Y') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('accounting.entries.index', ['month' => $month]) }}"><i class="ti ti-book-2 me-1"></i>Проводки</a>
            <a class="btn btn-ghost-secondary" href="{{ route('accounting.chart.index') }}"><i class="ti ti-list-numbers me-1"></i>План счетов</a>
        </div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <input type="month" name="month" class="form-control" value="{{ $month }}" aria-label="Месяц" onchange="this.form.submit()">
    <button class="btn btn-ghost-secondary"><i class="ti ti-calendar me-1"></i>Показать месяц</button>
</form>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><div class="card-title">Отчёт о прибылях и убытках</div></div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Счёт</th>
                            <th class="text-end">Оборот</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($profitAndLoss['rows'] as $row)
                            <tr>
                                <td>{{ $row->code }} {{ $row->name }}</td>
                                <td class="text-end">
                                    {{ number_format(abs((float) $row->balance), 2, ',', ' ') }}
                                    <span class="crm-chip crm-chip--grey ms-1">{{ $crm_label('acc_account_kind', $row->kind->value) }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="text-secondary">Нет доходных и расходных проводок за период.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Доходы</th>
                            <th class="text-end text-success">{{ number_format((float) $profitAndLoss['income'], 2, ',', ' ') }}</th>
                        </tr>
                        <tr>
                            <th>Расходы</th>
                            <th class="text-end text-danger">{{ number_format((float) $profitAndLoss['expense'], 2, ',', ' ') }}</th>
                        </tr>
                        <tr>
                            <th>Финансовый результат</th>
                            <th class="text-end {{ $profitAndLoss['profit'] >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format((float) $profitAndLoss['profit'], 2, ',', ' ') }}</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">
                <div class="card-title">Баланс</div>
                <div class="card-actions">
                    <span class="crm-chip {{ $balanceSheet['balanced'] ? 'crm-chip--green' : 'crm-chip--red' }}">
                        {{ $balanceSheet['balanced'] ? 'сходится' : 'расхождение' }}
                    </span>
                </div>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between py-1"><span class="text-secondary">Активы</span><strong>{{ number_format((float) $balanceSheet['assets'], 2, ',', ' ') }}</strong></div>
                <div class="d-flex justify-content-between py-1"><span class="text-secondary">Пассивы</span><strong>{{ number_format((float) $balanceSheet['liabilities'], 2, ',', ' ') }}</strong></div>
                <div class="d-flex justify-content-between py-1"><span class="text-secondary">Капитал</span><strong>{{ number_format((float) $balanceSheet['equity'], 2, ',', ' ') }}</strong></div>
                <div class="d-flex justify-content-between py-1"><span class="text-secondary">Финансовый результат</span><strong>{{ number_format((float) $balanceSheet['profit'], 2, ',', ' ') }}</strong></div>
                <hr class="my-2">
                <div class="d-flex justify-content-between py-1">
                    <span class="text-secondary">Пассивы + капитал + результат</span>
                    <strong>{{ number_format((float) $balanceSheet['liabilities'] + (float) $balanceSheet['equity'] + (float) $balanceSheet['profit'], 2, ',', ' ') }}</strong>
                </div>
                <div class="form-text">Упрощённый баланс: активы = пассивы + капитал + финансовый результат.</div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><div class="card-title">Оборотно-сальдовая ведомость</div></div>
            <div class="table-responsive">
                <table class="table table-hover table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Счёт</th>
                            <th>Вид</th>
                            <th class="text-end">Дебет</th>
                            <th class="text-end">Кредит</th>
                            <th class="text-end">Сальдо</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($trialBalance as $row)
                            <tr>
                                <td>
                                    <div class="crm-table__main">{{ $row->code }} {{ $row->name }}</div>
                                </td>
                                <td><span class="crm-chip crm-chip--grey">{{ $crm_label('acc_account_kind', $row->kind->value) }}</span></td>
                                <td class="text-end">{{ number_format((float) $row->debit_turnover, 2, ',', ' ') }}</td>
                                <td class="text-end">{{ number_format((float) $row->credit_turnover, 2, ',', ' ') }}</td>
                                <td class="text-end fw-semibold {{ (float) $row->balance >= 0 ? '' : 'text-danger' }}">{{ number_format((float) $row->balance, 2, ',', ' ') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-secondary">План счетов пуст — заполните его на странице «План счетов».</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="2">Итого</th>
                            <th class="text-end">{{ number_format((float) $trialTotals['debit'], 2, ',', ' ') }}</th>
                            <th class="text-end">{{ number_format((float) $trialTotals['credit'], 2, ',', ' ') }}</th>
                            <th class="text-end">{{ number_format((float) $trialTotals['balance'], 2, ',', ' ') }}</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
