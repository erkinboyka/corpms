@extends('layouts.tabler')
@section('workspace_layout', 'ledger')

@section('title', __('Финансы — Бюджеты'))

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Финансы') }}</div>
            <h1 class="page-title mt-1">{{ __('Бюджеты') }}</h1>
            <div class="text-secondary mt-1">{{ __('План на') }} {{ $month }} {{ __('факт считается по журналу операций') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('finance.transactions.index', ['month' => $month]) }}"><i class="ti ti-receipt me-1"></i>{{ __('Операции') }}</a>
            @can('checkBoxPermission', 'finance.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newBudget"><i class="ti ti-plus me-1"></i>{{ __('Задать бюджет') }}</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-target"></i>{{ __('План') }}</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">@include('finance.partials.metric', ['key' => 'budget_planned'])</div>
        <div class="crm-metric__hint">{{ __('сумма бюджетов месяца') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-arrow-down-left"></i>{{ __('Доходы') }}</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">@include('finance.partials.metric', ['key' => 'income'])</div>
        <div class="crm-metric__hint">{{ __('факт за месяц') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-danger);"><i class="ti ti-arrow-up-right"></i>{{ __('Расходы') }}</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">@include('finance.partials.metric', ['key' => 'expense'])</div>
        <div class="crm-metric__hint">{{ __('факт за месяц') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-accent);"><i class="ti ti-scale"></i>{{ __('Прибыль') }}</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">@include('finance.partials.metric', ['key' => 'profit'])</div>
        <div class="crm-metric__hint">{{ __('доходы минус расходы') }}</div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <input type="month" name="month" class="form-control" value="{{ $month }}" aria-label="{{ __("Месяц") }}" onchange="this.form.submit()">
    <button class="btn btn-ghost-secondary"><i class="ti ti-calendar me-1"></i>{{ __('Показать месяц') }}</button>
</form>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="1" class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('Направление') }}</th>
                    <th scope="col">{{ __('Статья') }}</th>
                    <th scope="col" class="text-end">{{ __('План') }}</th>
                    <th scope="col" class="text-end">{{ __('Факт') }}</th>
                    <th scope="col" style="width: 220px;">{{ __('Исполнение') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($budgets as $budget)
                    @php
                        $usage = $budget->usage_percent;
                        $barClass = $usage === null ? 'bg-secondary' : ($usage > 100 ? 'bg-danger' : ($usage >= 80 ? 'bg-warning' : 'bg-success'));
                    @endphp
                    <tr>
                        <td>
                            <span class="crm-chip {{ $budget->direction->value === 'income' ? 'crm-chip--green' : 'crm-chip--red' }}">
                                {{ $crm_label('fin_direction', $budget->direction->value) }}
                            </span>
                        </td>
                        <td>
                            <div class="crm-table__main">{{ $budget->category?->name ?? __('Все статьи') }}</div>
                            @if ($budget->notes)<div class="crm-table__sub">{{ $budget->notes }}</div>@endif
                        </td>
                        <td class="text-end">{{ number_format((float) $budget->planned_amount, 2, ',', ' ') }}</td>
                        <td class="text-end fw-semibold">
                            @if ($budget->actual === null)
                                @foreach ($budget->actual_by_currency as $currency => $amount)
                                    <div>{{ number_format($amount, 2, ',', ' ') }} {{ $currency }}</div>
                                @endforeach
                                <span class="text-secondary small">{{ __('Плану не назначена валюта') }}</span>
                            @else
                                {{ number_format((float) $budget->actual, 2, ',', ' ') }} {{ $metrics['currency'] ?? '' }}
                            @endif
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-fill" style="height: 6px;">
                                    <div class="progress-bar {{ $barClass }}" style="width: {{ min(100, (float) ($usage ?? 0)) }}%"></div>
                                </div>
                                <span class="text-secondary small">{{ $usage === null ? '—' : $usage.'%' }}</span>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'target', 'title' => __('Бюджет не задан'), 'text' => __('Задайте план по статье или направлению — факт посчитается автоматически по операциям месяца.')])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('checkBoxPermission', 'finance.manage')
<div class="modal modal-blur fade" id="newBudget" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('finance.budgets.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Бюджет на месяц') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Период и направление') }}</h3>
                    <div class="crm-form__desc">{{ __('Месяц и направление — бюджет считается помесячно') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="fb-month">{{ __('Месяц') }}<span class="req">*</span></label>
                            <input type="month" id="fb-month" name="month" class="form-control" value="{{ old('month', $month) }}" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="fb-direction">{{ __('Направление') }}<span class="req">*</span></label>
                            <select id="fb-direction" name="direction" class="form-select" required>
                                @foreach ($directions as $directionOption)
                                    <option value="{{ $directionOption->value }}" @selected(old('direction', 'expense') === $directionOption->value)>{{ $crm_label('fin_direction', $directionOption->value) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Статья и сумма') }}</h3>
                    <div class="crm-form__desc">{{ __('Повторное сохранение той же статьи обновит план, а не создаст дубль') }}</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="fb-category">{{ __('Статья') }}</label>
                        <select id="fb-category" name="category_id" class="form-select">
                            <option value="">{{ __('Все статьи направления') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }} ({{ $crm_label('fin_direction', $category->direction->value) }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__row mb-0">
                        <div class="crm-form__field">
                            <label class="form-label" for="fb-amount">{{ __('План, сумма') }}<span class="req">*</span></label>
                            <input type="number" id="fb-amount" name="planned_amount" class="form-control" value="{{ old('planned_amount') }}" step="0.01" min="0" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="fb-notes">{{ __('Примечание') }}</label>
                            <input type="text" id="fb-notes" name="notes" class="form-control" value="{{ old('notes') }}" maxlength="2000">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Сохранить') }}</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
