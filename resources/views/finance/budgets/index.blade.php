@extends('layouts.tabler')

@section('title', 'Финансы — Бюджеты')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Финансы</div>
            <h2 class="page-title mt-1">Бюджеты</h2>
            <div class="text-secondary mt-1">План на {{ $month }} · факт считается по журналу операций</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('finance.transactions.index', ['month' => $month]) }}"><i class="ti ti-receipt me-1"></i>Операции</a>
            @can('checkBoxPermission', 'finance.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newBudget"><i class="ti ti-plus me-1"></i>Задать бюджет</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #4299e1;"><i class="ti ti-target"></i>План</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $metrics['budget_planned'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">сумма бюджетов месяца</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #0b9b72;"><i class="ti ti-arrow-down-left"></i>Доходы</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $metrics['income'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">факт за месяц</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #e5697a;"><i class="ti ti-arrow-up-right"></i>Расходы</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $metrics['expense'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">факт за месяц</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #8ba9ff;"><i class="ti ti-scale"></i>Прибыль</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $metrics['profit'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">доходы минус расходы</div>
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
                    <th>Направление</th>
                    <th>Статья</th>
                    <th class="text-end">План</th>
                    <th class="text-end">Факт</th>
                    <th style="width: 220px;">Исполнение</th>
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
                            <div class="crm-table__main">{{ $budget->category?->name ?? 'Все статьи' }}</div>
                            @if ($budget->notes)<div class="crm-table__sub">{{ $budget->notes }}</div>@endif
                        </td>
                        <td class="text-end">{{ number_format((float) $budget->planned_amount, 2, ',', ' ') }}</td>
                        <td class="text-end fw-semibold">{{ number_format((float) $budget->actual, 2, ',', ' ') }}</td>
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
                    <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'target', 'title' => 'Бюджет не задан', 'text' => 'Задайте план по статье или направлению — факт посчитается автоматически по операциям месяца.'])</td></tr>
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
            <div class="modal-header"><h5 class="modal-title">Бюджет на месяц</h5></div>
            <div class="modal-body">
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="fb-month">Месяц<span class="req">*</span></label>
                        <input type="month" id="fb-month" name="month" class="form-control" value="{{ old('month', $month) }}" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="fb-direction">Направление<span class="req">*</span></label>
                        <select id="fb-direction" name="direction" class="form-select" required>
                            @foreach ($directions as $directionOption)
                                <option value="{{ $directionOption->value }}" @selected(old('direction', 'expense') === $directionOption->value)>{{ $crm_label('fin_direction', $directionOption->value) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="fb-category">Статья</label>
                    <select id="fb-category" name="category_id" class="form-select">
                        <option value="">Все статьи направления</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }} ({{ $crm_label('fin_direction', $category->direction->value) }})</option>
                        @endforeach
                    </select>
                    <div class="form-text">Повторное сохранение той же статьи обновит план, а не создаст дубль.</div>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="fb-amount">План, сумма<span class="req">*</span></label>
                    <input type="number" id="fb-amount" name="planned_amount" class="form-control" value="{{ old('planned_amount') }}" step="0.01" min="0" required>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="fb-notes">Примечание</label>
                    <input type="text" id="fb-notes" name="notes" class="form-control" value="{{ old('notes') }}" maxlength="2000">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
