@extends('layouts.tabler')

@section('title', 'Зарплата — Оклады и компоненты')

@php
    $salaryPct = $totals['employees'] > 0 ? (int) round($totals['withSalary'] / $totals['employees'] * 100) : 0;
    $currencies = ['TJS', 'RUB', 'USD', 'EUR', 'KZS', 'UAH', 'BYN', 'UZS', 'KGS', 'AMD'];
@endphp

@section('content')
<div class="pay-flow" id="pay-flow">

{{-- ===== Шапка: загольчок + одна контекстная строка ===== --}}
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Зарплата</div>
            <h2 class="page-title mt-1">Оклады и компоненты</h2>
            <div class="text-secondary mt-1" id="pay-lede">Базовая сумма каждого сотрудника.</div>
        </div>
    </div>
</div>

{{-- ===== Переключатель экранов ===== --}}
<nav class="pay-stepper mb-3" aria-label="Экраны раздела">
    <ol class="pay-stepper__list">
        <li class="pay-step">
            <button type="button" class="pay-stepper__btn is-active" data-goto="1" aria-current="step" aria-controls="pay-screen-1">
                <span class="pay-stepper__dot">
                    <span class="pay-stepper__num">1</span>
                    <i class="ti ti-check pay-stepper__check" aria-hidden="true"></i>
                </span>
                <span class="pay-stepper__label">Оклады</span>
            </button>
        </li>
        <li class="pay-step">
            <button type="button" class="pay-stepper__btn" data-goto="2" aria-controls="pay-screen-2">
                <span class="pay-stepper__dot">
                    <span class="pay-stepper__num">2</span>
                    <i class="ti ti-check pay-stepper__check" aria-hidden="true"></i>
                </span>
                <span class="pay-stepper__label">Правила расчёта</span>
            </button>
        </li>
        <li class="pay-step">
            <button type="button" class="pay-stepper__btn" data-goto="3" aria-controls="pay-screen-3">
                <span class="pay-stepper__dot">
                    <span class="pay-stepper__num">3</span>
                    <i class="ti ti-check pay-stepper__check" aria-hidden="true"></i>
                </span>
                <span class="pay-stepper__label">Расчёт ведомости</span>
            </button>
        </li>
    </ol>
</nav>

{{-- ===== Экран 1: Оклады ===== --}}
<section class="pay-screen" id="pay-screen-1" data-step="1" data-lede="Базовая сумма каждого сотрудника." aria-label="Оклады">
    @if ($canManage)
        <div class="pay-screen__bar">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newSalary"><i class="ti ti-plus me-1"></i>Назначить оклад</button>
        </div>
    @endif

    <div class="crm-metrics">
        <div class="crm-metric">
            <div class="crm-metric__label"><i class="ti ti-users"></i>С окладом</div>
            <div class="crm-metric__value">{{ $totals['withSalary'] }}<small> / {{ $totals['employees'] }}</small></div>
            <div class="progress progress-sm mt-2" role="progressbar" aria-valuenow="{{ $salaryPct }}" aria-valuemin="0" aria-valuemax="100" aria-label="Покрытие окладами">
                <div class="progress-bar" style="width: {{ $salaryPct }}%"></div>
            </div>
        </div>
        <div class="crm-metric">
            <div class="crm-metric__label" style="color: #0b9b72;"><i class="ti ti-coins"></i>Средний оклад</div>
            <div class="crm-metric__value">{{ number_format($totals['avg'], 2, ',', ' ') }}</div>
        </div>
        <div class="crm-metric">
            <div class="crm-metric__label"><i class="ti ti-components"></i>Правил</div>
            <div class="crm-metric__value">{{ $totals['components'] }}</div>
        </div>
        <div class="crm-metric">
            <div class="crm-metric__label" style="color: #2fb344;"><i class="ti ti-toggle-right"></i>Активных</div>
            <div class="crm-metric__value">{{ $totals['active'] }}</div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <strong>Оклады сотрудников</strong>
            <span class="crm-chip ms-auto" id="salaries-count">{{ $salaries->count() }} назначено</span>
        </div>
        <div class="card-body pb-0 d-print-none">
            <div class="input-icon">
                <input type="search" class="form-control crm-search-input" placeholder="Поиск: сотрудник, отдел, должность…"
                       aria-label="Поиск по окладам" data-crm-filter="#salaries-tbody tr" data-crm-filter-count="#salaries-count">
                <span class="input-icon-addon"><i class="ti ti-search"></i></span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-vcenter card-table mb-0">
                <thead>
                    <tr>
                        <th>Сотрудник</th><th>Отдел / должность</th>
                        <th class="text-end">Базовая сумма</th><th>Валюта</th><th>С какой даты</th>
                    </tr>
                </thead>
                <tbody id="salaries-tbody">
                    @forelse ($salaries as $salary)
                        <tr>
                            <td>
                                <div class="crm-table__main">{{ $salary->employee?->last_name }} {{ $salary->employee?->first_name }}</div>
                                <div class="crm-table__sub">{{ $salary->employee?->employee_no ?? '' }}</div>
                            </td>
                            <td class="text-secondary small">
                                {{ $salary->employee?->department?->name ?? '—' }} · {{ $salary->employee?->position?->name ?? '—' }}
                            </td>
                            <td class="text-end crm-table-num fw-semibold">{{ number_format((float) $salary->base_amount, 2, ',', ' ') }}</td>
                            <td><span class="crm-chip crm-chip--grey">{{ $salary->currency }}</span></td>
                            <td class="crm-table-num text-secondary">{{ $salary->effective_from->format('d.m.Y') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                @include('partials.empty-state', array_merge([
                                    'icon' => 'coins',
                                    'title' => 'Оклады не назначены',
                                    'text' => 'Без оклада сотрудник не попадёт в расчёт ведомости.',
                                ], $canManage ? ['actionUrl' => '#newSalary', 'actionLabel' => 'Добавить оклад'] : []))
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="pay-nav pay-nav--end">
        <button type="button" class="btn btn-secondary" data-goto="2">Далее: правила расчёта<i class="ti ti-arrow-right ms-1"></i></button>
    </div>
</section>

{{-- ===== Экран 2: Правила расчёта ===== --}}
<section class="pay-screen" id="pay-screen-2" data-step="2" data-lede="Начисления и удержания — участвуют, когда включены." aria-label="Правила расчёта" hidden>
    @if ($canManage)
        <div class="pay-screen__bar">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newComponent"><i class="ti ti-plus me-1"></i>Добавить правило</button>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-header">
            <strong>Общие правила расчёта</strong>
            <span class="crm-chip ms-auto" id="components-count">{{ $components->count() }} правил</span>
        </div>
        <div class="card-body pb-0 d-print-none">
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <div class="input-icon flex-fill" style="max-width: 24rem;">
                    <input type="search" class="form-control crm-search-input" placeholder="Поиск: код, название…"
                           aria-label="Поиск по компонентам" data-crm-filter="#components-tbody tr" data-crm-filter-count="#components-count">
                    <span class="input-icon-addon"><i class="ti ti-search"></i></span>
                </div>
                <span class="crm-chip crm-chip--green"><i class="ti ti-plus me-1"></i>Начисление</span>
                <span class="crm-chip crm-chip--red"><i class="ti ti-minus me-1"></i>Удержание</span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-vcenter card-table mb-0">
                <thead>
                    <tr>
                        <th>Код</th><th>Правило</th><th>Влияние</th><th>Как считается</th>
                        <th class="text-end">Значение</th><th>В расчёте</th>
                        @if ($canManage)<th class="text-end">Действие</th>@endif
                    </tr>
                </thead>
                <tbody id="components-tbody">
                    @forelse ($components as $component)
                        <tr>
                            <td class="crm-table-num text-secondary">{{ $component->code }}</td>
                            <td class="fw-semibold">{{ $component->name }}</td>
                            <td>
                                <span class="crm-chip {{ $component->kind->value === 'deduction' ? 'crm-chip--red' : 'crm-chip--green' }}">
                                    {{ $crm_label('payroll_component_kind', $component->kind->value) }}
                                </span>
                            </td>
                            <td class="text-secondary small">{{ $crm_label('payroll_calculation', $component->calculation->value) }}</td>
                            <td class="text-end crm-table-num">{{ number_format((float) $component->rate, 2, ',', ' ') }}</td>
                            <td>
                                <span class="crm-chip {{ $component->is_active ? 'crm-chip--green' : 'crm-chip--grey' }}">
                                    {{ $component->is_active ? 'Активен' : 'Выключен' }}
                                </span>
                            </td>
                            @if ($canManage)
                                <td class="text-end">
                                    <form method="post" action="{{ route('payroll.components.toggle', $component) }}" class="d-inline" data-submit-guard>
                                        @csrf
                                        <button class="btn btn-sm btn-ghost-secondary" title="{{ $component->is_active ? 'Выключить' : 'Включить' }}">
                                            <i class="ti ti-{{ $component->is_active ? 'toggle-left' : 'toggle-right' }}"></i>
                                        </button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canManage ? 7 : 6 }}">
                                @include('partials.empty-state', array_merge([
                                    'icon' => 'components',
                                    'title' => 'Компонентов нет',
                                    'text' => 'Добавьте правила начислений — оклад, авансы, налоги, премии.',
                                ], $canManage ? ['actionUrl' => '#newComponent', 'actionLabel' => 'Новый компонент'] : []))
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="pay-nav">
        <button type="button" class="btn btn-ghost-secondary" data-goto="1"><i class="ti ti-arrow-left me-1"></i>Назад</button>
        <button type="button" class="btn btn-secondary" data-goto="3">Далее: расчёт ведомости<i class="ti ti-arrow-right ms-1"></i></button>
    </div>
</section>

{{-- ===== Экран 3: Расчёт ведомости ===== --}}
<section class="pay-screen" id="pay-screen-3" data-step="3" data-lede="Когда оклады и правила готовы — считаем ведомость." aria-label="Расчёт ведомости" hidden>
    <div class="crm-metrics">
        <div class="crm-metric">
            <div class="crm-metric__label"><i class="ti ti-users"></i>Окладов назначено</div>
            <div class="crm-metric__value">{{ $totals['withSalary'] }}<small> / {{ $totals['employees'] }}</small></div>
        </div>
        <div class="crm-metric">
            <div class="crm-metric__label" style="color: #2fb344;"><i class="ti ti-toggle-right"></i>Правил активно</div>
            <div class="crm-metric__value">{{ $totals['active'] }}<small> / {{ $totals['components'] }}</small></div>
        </div>
    </div>

    <a class="pay-cta mb-3" href="{{ route('payroll.index') }}">
        <span class="pay-cta__icon"><i class="ti ti-calculator"></i></span>
        <span class="pay-cta__body">
            <span class="pay-cta__title">Перейти к расчёту ведомости</span>
            <span class="pay-cta__text">Оклады и включённые правила участвуют в расчёте автоматически.</span>
        </span>
        <i class="ti ti-arrow-right pay-cta__arrow" aria-hidden="true"></i>
    </a>

    <div class="pay-nav">
        <button type="button" class="btn btn-ghost-secondary" data-goto="2"><i class="ti ti-arrow-left me-1"></i>Назад</button>
        <a class="btn btn-primary" href="{{ route('payroll.index') }}">К расчёту ведомости<i class="ti ti-arrow-right ms-1"></i></a>
    </div>
</section>

</div>{{-- /.pay-flow --}}

{{-- модалка оклада --}}
<div class="modal modal-blur fade" id="newSalary" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('payroll.salaries.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Назначить оклад</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>Сотрудник</h3>
                    <div class="crm-form__desc">Предыдущий действующий оклад закроется днём раньше новой даты — история сохраняется.</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ns-employee">Сотрудник<span class="req">*</span></label>
                        <select id="ns-employee" name="employee_id" class="form-select" required>
                            <option value="">Начните вводить фамилию…</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->last_name }} {{ $employee->first_name }} · {{ $employee->employee_no }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>Условия</h3>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ns-amount">Оклад<span class="req">*</span></label>
                            <input type="number" id="ns-amount" name="base_amount" class="form-control" required min="0" step="0.01" placeholder="5000.00" inputmode="decimal">
                            <div class="form-text">Только число, без пробелов.</div>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ns-currency">Валюта<span class="req">*</span></label>
                            <select id="ns-currency" name="currency" class="form-select" required>
                                @foreach ($currencies as $code)
                                    <option value="{{ $code }}" @selected($code === 'TJS')>{{ $code }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ns-from">Действует с<span class="req">*</span></label>
                            <input type="date" id="ns-from" name="effective_from" class="form-control" required value="{{ now()->toDateString() }}">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary"><i class="ti ti-check me-1"></i>Сохранить</button>
            </div>
        </form>
    </div>
</div>

{{-- модалка компонента --}}
<div class="modal modal-blur fade" id="newComponent" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('payroll.components.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новый компонент</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>Название и тип</h3>
                    <div class="crm-form__desc">Что это и в пользу сотрудника или удержание.</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nc-name">Название<span class="req">*</span></label>
                        <input type="text" id="nc-name" name="name" class="form-control" required maxlength="120" placeholder="Сверхурочные">
                    </div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-code">Код<span class="req">*</span></label>
                            <input type="text" id="nc-code" name="code" class="form-control" required maxlength="32" placeholder="OVERTIME" pattern="[A-Za-z0-9_.\-]+">
                            <div class="form-text">Латиница, цифры, «_ - .»</div>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-kind">Тип<span class="req">*</span></label>
                            <select id="nc-kind" name="kind" class="form-select" required>
                                <option value="earning">Начисление (+ в пользу)</option>
                                <option value="deduction">Удержание (− вычитается)</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>Как считается</h3>
                    <div class="crm-form__desc">Алгоритм применяется при расчёте ведомости.</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-calc">Алгоритм<span class="req">*</span></label>
                            <select id="nc-calc" name="calculation" class="form-select" required>
                                <option value="fixed">Фиксированная сумма</option>
                                <option value="hourly">Почасовая: часы × ставка</option>
                                <option value="overtime">Сверхурочные: часы × ставка</option>
                                <option value="percent">Процент от оклада</option>
                                <option value="manual">Ручная сумма из ставки</option>
                            </select>
                            <div class="form-text" id="nc-calc-hint">Ставка используется как есть.</div>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-rate">Ставка<span class="req">*</span></label>
                            <input type="number" id="nc-rate" name="rate" class="form-control" required min="0" step="0.0001" value="0" inputmode="decimal">
                            <div class="form-text" id="nc-rate-hint">Сумма или процент — зависит от алгоритма.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary"><i class="ti ti-plus me-1"></i>Добавить</button>
            </div>
        </form>
    </div>
</div>

@push('styles')
<style>
    /* ===== Экранный поток «Оклады и компоненты» (scoped) ===== */

    /* --- Стикер-переключатель --- */
    .pay-stepper {
        display: flex;
        align-items: center;
        gap: .35rem;
        flex-wrap: wrap;
        padding: .45rem .55rem;
        border: 1px solid var(--crm-border);
        border-radius: 14px;
        background: var(--crm-surface);
    }
    .pay-stepper__list {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: .15rem;
        list-style: none;
        margin: 0;
        padding: 0;
    }
    .pay-step { display: inline-flex; align-items: center; }
    .pay-step + .pay-step::before {
        content: '›';
        margin: 0 .35rem;
        color: var(--crm-text-secondary);
        opacity: .55;
        font-size: 1.15rem;
        line-height: 1;
    }
    .pay-stepper__btn {
        display: inline-flex;
        align-items: center;
        gap: .5rem;
        padding: .35rem .6rem;
        border: 1px solid transparent;
        border-radius: 10px;
        background: transparent;
        color: var(--crm-text-secondary);
        font: inherit;
        font-size: .875rem;
        font-weight: 600;
        line-height: 1.2;
        cursor: pointer;
        transition: background-color .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease;
    }
    .pay-stepper__btn:hover { background: rgba(var(--crm-accent-rgb), .10); color: var(--crm-text); }
    .pay-stepper__btn:focus-visible { outline: 2px solid rgba(var(--crm-accent-rgb), .65); outline-offset: 2px; }
    .pay-stepper__dot {
        width: 1.6rem;
        height: 1.6rem;
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: rgba(var(--crm-accent-rgb), .13);
        color: var(--crm-accent-strong);
        box-shadow: inset 0 0 0 1px rgba(var(--crm-accent-rgb), .28);
        font-size: .8rem;
        font-weight: 700;
        transition: background-color .18s ease, color .18s ease, box-shadow .18s ease;
    }
    .pay-stepper__check { display: none; font-size: .95rem; }

    /* пройденный шаг: галочка, accent-soft */
    .pay-stepper__btn.is-done .pay-stepper__dot {
        background: rgba(var(--crm-accent-rgb), .18);
        box-shadow: inset 0 0 0 1px rgba(var(--crm-accent-rgb), .45);
    }
    .pay-stepper__btn.is-done .pay-stepper__num { display: none; }
    .pay-stepper__btn.is-done .pay-stepper__check { display: inline-block; }
    .pay-stepper__btn.is-done .pay-stepper__label { color: var(--crm-text); }

    /* активный шаг: акцент */
    .pay-stepper__btn.is-active {
        background: rgba(var(--crm-accent-rgb), .14);
        border-color: rgba(var(--crm-accent-rgb), .45);
        color: var(--crm-text);
    }
    .pay-stepper__btn.is-active .pay-stepper__dot {
        background: var(--crm-accent-strong);
        color: #fff;
        box-shadow: 0 0 0 4px rgba(var(--crm-accent-rgb), .2);
    }
    .pay-stepper__btn.is-active .pay-stepper__label { font-weight: 700; }

    /* --- Экраны --- */
    .pay-screen { animation: pay-screen-in .22s var(--crm-ease, ease); }
    @keyframes pay-screen-in {
        from { opacity: 0; transform: translateY(6px); }
        to { opacity: 1; transform: none; }
    }
    .pay-screen__bar {
        display: flex;
        justify-content: flex-end;
        gap: .5rem;
        flex-wrap: wrap;
        margin-bottom: .75rem;
    }

    /* --- Навигация «Назад / Далее» --- */
    .pay-nav {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        flex-wrap: wrap;
        margin-top: 1rem;
    }
    .pay-nav--end { justify-content: flex-end; }

    /* --- CTA-карточка расчёта --- */
    .pay-cta {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1.1rem 1.25rem;
        border: 1px solid rgba(var(--crm-accent-rgb), .45);
        border-radius: var(--crm-radius-lg);
        background: linear-gradient(135deg, rgba(var(--crm-accent-rgb), .16), rgba(var(--crm-accent-rgb), .05));
        color: inherit;
        text-decoration: none;
        transition: transform .18s var(--crm-ease, ease), border-color .18s ease, box-shadow .18s ease;
    }
    .pay-cta:hover {
        color: inherit;
        text-decoration: none;
        border-color: rgba(var(--crm-accent-rgb), .75);
        transform: translateY(-2px);
        box-shadow: 0 14px 30px -22px rgba(var(--crm-accent-rgb), .9);
    }
    .pay-cta__icon {
        width: 46px;
        height: 46px;
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        background: var(--crm-accent-gradient);
        color: #fff;
        font-size: 1.4rem;
        box-shadow: 0 6px 16px -10px rgba(var(--crm-accent-rgb), .9);
    }
    .pay-cta__body {
        display: flex;
        flex-direction: column;
        gap: .15rem;
        min-width: 0;
        flex: 1 1 auto;
    }
    .pay-cta__title { font-weight: 700; font-size: 1.0625rem; }
    .pay-cta__text { font-size: .875rem; color: var(--crm-text-secondary); }
    .pay-cta__arrow { font-size: 1.5rem; color: var(--crm-accent-strong); flex: 0 0 auto; }

    /* --- Адаптив --- */
    @media (max-width: 575.98px) {
        .pay-stepper { justify-content: center; }
        .pay-step + .pay-step::before { display: none; }
        .pay-stepper__label { display: none; }
        .pay-stepper__btn.is-active .pay-stepper__label { display: inline; }
        .pay-nav .btn { flex: 1 1 auto; }
        .pay-cta { padding: .9rem 1rem; gap: .75rem; }
    }
    @media (prefers-reduced-motion: reduce) {
        .pay-screen { animation: none; }
        .pay-cta:hover { transform: none; }
    }
</style>
@endpush

@push('scripts')
<script>
    (function () {
        var flow = document.getElementById('pay-flow');
        if (!flow) { return; }

        var screens = Array.prototype.slice.call(flow.querySelectorAll('.pay-screen'));
        var stepBtns = Array.prototype.slice.call(flow.querySelectorAll('.pay-stepper__btn'));
        var lede = document.getElementById('pay-lede');
        var total = screens.length;
        if (!total || !stepBtns.length) { return; }

        var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function stepFromHash() {
            var m = /^#step-([1-9][0-9]*)$/.exec(window.location.hash || '');
            if (!m) { return 0; }
            var n = parseInt(m[1], 10);
            return (n >= 1 && n <= total) ? n : 0;
        }

        function show(n, opts) {
            opts = opts || {};
            n = Math.min(Math.max(n, 1), total);

            screens.forEach(function (screen) {
                screen.hidden = parseInt(screen.getAttribute('data-step'), 10) !== n;
            });

            stepBtns.forEach(function (btn) {
                var i = parseInt(btn.getAttribute('data-goto'), 10);
                var active = i === n;
                btn.classList.toggle('is-active', active);
                btn.classList.toggle('is-done', i < n);
                if (active) {
                    btn.setAttribute('aria-current', 'step');
                } else {
                    btn.removeAttribute('aria-current');
                }
            });

            if (lede && screens[n - 1]) {
                var text = screens[n - 1].getAttribute('data-lede');
                if (text) { lede.textContent = text; }
            }

            if (opts.hash && window.location.hash !== '#step-' + n) {
                window.history.replaceState(null, '', '#step-' + n);
            }

            if (opts.user) {
                var activeBtn = stepBtns[n - 1];
                if (activeBtn && document.activeElement !== activeBtn && activeBtn.focus) {
                    activeBtn.focus({ preventScroll: true });
                }
                var stepper = flow.querySelector('.pay-stepper');
                if (stepper && stepper.scrollIntoView) {
                    stepper.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
                }
            }
        }

        /* клики по стикеру и кнопкам «Назад / Далее» */
        flow.addEventListener('click', function (event) {
            var target = event.target;
            var btn = target && target.closest ? target.closest('[data-goto]') : null;
            if (!btn || !flow.contains(btn)) { return; }
            var n = parseInt(btn.getAttribute('data-goto'), 10);
            if (n < 1 || n > total) { return; }
            event.preventDefault();
            show(n, { user: true, hash: true });
        });

        /* ручное изменение hash / back-forward */
        window.addEventListener('hashchange', function () {
            var n = stepFromHash();
            if (n) { show(n, { hash: false }); }
        });

        /* ссылки-пустые состояния «Добавить оклад / Новый компонент» открывают модалку */
        document.addEventListener('click', function (event) {
            var target = event.target;
            var link = target && target.closest ? target.closest('a[href="#newSalary"], a[href="#newComponent"]') : null;
            if (!link) { return; }
            var modalEl = document.querySelector(link.getAttribute('href'));
            if (!modalEl || !window.bootstrap || !bootstrap.Modal) { return; }
            event.preventDefault();
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        });

        /* старт: hash #step-N или первый экран */
        show(stepFromHash() || 1, { hash: false });
    })();
</script>
<script>
    (function () {
        var calc = document.getElementById('nc-calc');
        if (!calc) { return; }
        var calcHint = document.getElementById('nc-calc-hint');
        var rateHint = document.getElementById('nc-rate-hint');
        var texts = {
            fixed: ['Ставка — фиксированная сумма компонента.', 'Например: 5000 — премия 5000.'],
            hourly: ['Ставка — цена одного часа.', 'Например: 15 — за каждый отработанный час.'],
            overtime: ['Ставка — цена часа сверхурочно.', 'Например: 22.5 — множится на часы переработки.'],
            percent: ['Ставка — процент от оклада.', 'Например: 10 — премия 10% оклада.'],
            manual: ['Сумма вводится вручную при расчёте.', 'Ставка — значение по умолчанию.']
        };
        function sync() {
            var t = texts[calc.value] || texts.fixed;
            calcHint.textContent = t[0];
            rateHint.textContent = t[1];
        }
        calc.addEventListener('change', sync);
        sync();
    })();
</script>
@endpush
@endsection
