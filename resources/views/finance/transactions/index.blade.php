@extends('layouts.tabler')

@section('title', 'Финансы — Доходы и расходы')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Финансы</div>
            <h2 class="page-title mt-1">Доходы и расходы</h2>
            <div class="text-secondary mt-1">Журнал операций · {{ $month }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('finance.accounts.index') }}"><i class="ti ti-cash-banknote me-1"></i>Счета</a>
            @can('checkBoxPermission', 'finance.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newTransaction"><i class="ti ti-plus me-1"></i>Новая операция</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #0b9b72;"><i class="ti ti-arrow-down-left"></i>Доходы</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $metrics['income'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ $month }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #e5697a;"><i class="ti ti-arrow-up-right"></i>Расходы</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $metrics['expense'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ $month }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #8ba9ff;"><i class="ti ti-scale"></i>Прибыль</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $metrics['profit'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">доходы минус расходы</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #4299e1;"><i class="ti ti-cash-banknote"></i>Баланс</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $metrics['balance'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">все счета</div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <input type="month" name="month" class="form-control" value="{{ $month }}" aria-label="Месяц" onchange="this.form.submit()">
    <select name="direction" class="form-select" aria-label="Направление" onchange="this.form.submit()">
        <option value="">Все направления</option>
        @foreach ($directions as $directionOption)
            <option value="{{ $directionOption->value }}" @selected($direction === $directionOption->value)>{{ $crm_label('fin_direction', $directionOption->value) }}</option>
        @endforeach
    </select>
    <select name="account" class="form-select" aria-label="Счёт" onchange="this.form.submit()">
        <option value="">Все счета</option>
        @foreach ($accounts as $account)
            <option value="{{ $account->id }}" @selected((string) $accountFilter === (string) $account->id)>{{ $account->name }}</option>
        @endforeach
    </select>
    <button class="btn btn-ghost-secondary"><i class="ti ti-filter me-1"></i>Показать</button>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th>Дата</th>
                    <th>Операция</th>
                    <th>Статья</th>
                    <th>Направление</th>
                    <th class="text-end">Сумма</th>
                    <th>Источник</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transactions as $transaction)
                    <tr>
                        <td class="text-secondary">{{ $transaction->occurred_at?->format('d.m.Y') }}</td>
                        <td>
                            <div class="crm-table__main">{{ $transaction->description ?: ($transaction->direction->value === 'income' ? 'Доход' : 'Расход') }}</div>
                            <div class="crm-table__sub">{{ $transaction->account?->name ?? '—' }}@if ($transaction->project) · проект «{{ $transaction->project->name }}»@endif</div>
                        </td>
                        <td class="text-secondary">{{ $transaction->category?->name ?? '—' }}</td>
                        <td>
                            <span class="crm-chip {{ $transaction->direction->value === 'income' ? 'crm-chip--green' : 'crm-chip--red' }}">
                                {{ $crm_label('fin_direction', $transaction->direction->value) }}
                            </span>
                        </td>
                        <td class="text-end fw-semibold {{ $transaction->direction->value === 'income' ? 'text-success' : 'text-danger' }}">
                            {{ $transaction->direction->sign() > 0 ? '+' : '−' }}{{ number_format((float) $transaction->amount, 2, ',', ' ') }}
                        </td>
                        <td class="text-secondary small">{{ $crm_label('fin_transaction_source', $transaction->source->value) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">@include('partials.empty-state', ['icon' => 'receipt', 'title' => 'Операций за месяц нет', 'text' => 'Запишите приход или расход — он сразу попадёт в баланс счёта и бюджеты.'])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($transactions->hasPages())
        <div class="card-footer">{{ $transactions->links('pagination::tabler') }}</div>
    @endif
</div>

@can('checkBoxPermission', 'finance.manage')
<div class="modal modal-blur fade" id="newTransaction" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('finance.transactions.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новая операция</h5></div>
            <div class="modal-body">
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="ft-direction">Направление<span class="req">*</span></label>
                        <select id="ft-direction" name="direction" class="form-select" required>
                            @foreach ($directions as $directionOption)
                                <option value="{{ $directionOption->value }}" @selected(old('direction', 'expense') === $directionOption->value)>{{ $crm_label('fin_direction', $directionOption->value) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ft-amount">Сумма<span class="req">*</span></label>
                        <input type="number" id="ft-amount" name="amount" class="form-control" value="{{ old('amount') }}" step="0.01" min="0.01" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ft-date">Дата<span class="req">*</span></label>
                        <input type="date" id="ft-date" name="occurred_at" class="form-control" value="{{ old('occurred_at', now()->toDateString()) }}" required>
                    </div>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="ft-account">Счёт<span class="req">*</span></label>
                        <select id="ft-account" name="account_id" class="form-select" required>
                            <option value="">— выберите —</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}" @selected((string) old('account_id') === (string) $account->id)>{{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ft-category">Статья</label>
                        <select id="ft-category" name="category_id" class="form-select">
                            <option value="">— без статьи —</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }} ({{ $crm_label('fin_direction', $category->direction->value) }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="ft-project">Проект (для бюджета)</label>
                    <select id="ft-project" name="project_id" class="form-select">
                        <option value="">— без проекта —</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}" @selected((string) old('project_id') === (string) $project->id)>{{ $project->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="ft-description">Описание</label>
                    <input type="text" id="ft-description" name="description" class="form-control" value="{{ old('description') }}" maxlength="255" placeholder="Оплата аренды за ноябрь">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Записать</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
