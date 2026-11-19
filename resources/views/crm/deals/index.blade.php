@extends('layouts.tabler')
@section('workspace_layout', 'planning')

@section('title', 'CRM — Сделки')
@section('body_class', 'bm-crm-pipeline')

@php
    $stageIcons = [
        'new' => 'sparkles',
        'qualification' => 'user-check',
        'proposal' => 'file-text',
        'negotiation' => 'messages',
        'won' => 'trophy',
        'lost' => 'circle-off',
    ];
@endphp

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">CRM</div>
            <h1 class="page-title mt-1">Воронка сделок</h1>
            <div class="text-secondary mt-1">
                В работе {{ $metrics['deals_open'] }} на {{ number_format((float) $metrics['deals_amount'], 0, ',', ' ') }}
                · выиграно за месяц {{ number_format((float) $metrics['won_month'], 0, ',', ' ') }}
            </div>
        </div>
        <div class="col-auto d-print-none">


            @can('checkBoxPermission', 'crm.deals.manage@company')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newDeal"><i class="ti ti-plus me-1"></i>Новая сделка</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-chart-funnel"></i>В работе</div>
        <div class="crm-metric__value">{{ $metrics['deals_open'] }}</div>
        <div class="crm-metric__hint">сделок</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-currency-dollar"></i>Потенциал</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $metrics['deals_amount'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">сумма открытых</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-purple);"><i class="ti ti-trophy"></i>Выиграно</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $metrics['won_month'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">за текущий месяц</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-calendar-event"></i>Активности</div>
        <div class="crm-metric__value">{{ $metrics['activities_overdue'] }}</div>
        <div class="crm-metric__hint">просрочено из {{ $metrics['activities_pending'] }}</div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <div class="input-icon">
        <input type="search" name="q" class="form-control crm-search-input" value="{{ $search }}" placeholder="Сделка или клиент…" aria-label="Поиск сделок">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <select name="owner" class="form-select" aria-label="Ответственный" onchange="this.form.submit()">
        <option value="">Все ответственные</option>
        @foreach ($employees as $employee)
            <option value="{{ $employee->id }}" @selected((string) $ownerFilter === (string) $employee->id)>{{ $employee->last_name }} {{ $employee->first_name }}</option>
        @endforeach
    </select>
    <button class="btn btn-ghost-secondary"><i class="ti ti-search me-1"></i>Найти</button>
</form>

<div class="crm-kanban" data-deal-board>
    @foreach ($stages as $stage)
        @php $columnDeals = $board[$stage->value] ?? collect(); @endphp
        <div class="crm-kanban__col" data-deal-stage="{{ $stage->value }}">
            <div class="crm-kanban__head">
                <span class="crm-kanban__title"><i class="ti ti-{{ $stageIcons[$stage->value] ?? 'circle' }}"></i>{{ $crm_label('crm_deal_stage', $stage->value) }}</span>
                <span class="crm-kanban__count">{{ $columnDeals->count() }}</span>
            </div>
            <div class="text-secondary small mb-2">{{ number_format((float) $columnDeals->sum('amount'), 0, ',', ' ') }}</div>

            @forelse ($columnDeals as $deal)
                <div class="crm-kanban__card">
                    <div class="d-flex align-items-start">
                        @can('checkBoxPermission', 'crm.deals.manage@company')
                        <span class="bm-deal-grip" data-deal-grip draggable="true" title="Перетащить в другую стадию" aria-hidden="true"><i class="ti ti-grip-vertical"></i></span>
                        @endcan
                        <div class="flex-fill">
                            <div class="fw-semibold">{{ $deal->title }}</div>
                            <div class="crm-table__sub">
                                <a class="text-decoration-none" href="{{ route('crm.clients.show', $deal->client) }}">{{ $deal->client->name }}</a>
                            </div>
                        </div>
                        @can('checkBoxPermission', 'crm.deals.manage@company')
                        <button class="btn btn-sm btn-ghost-secondary" data-bs-toggle="modal" data-bs-target="#editDeal{{ $deal->id }}" title="Изменить"><i class="ti ti-pencil"></i></button>
                        @endcan
                    </div>
                    <div class="d-flex align-items-center justify-content-between mt-2">
                        <span class="fw-semibold">{{ number_format((float) $deal->amount, 0, ',', ' ') }}</span>
                        <span class="text-secondary small">{{ $deal->probability }}%</span>
                    </div>
                    <div class="k-meta mt-1">
                        <span class="text-secondary small">
                            <i class="ti ti-user me-1"></i>{{ $deal->owner ? $deal->owner->last_name : '—' }}
                        </span>
                        <span class="text-secondary small">
                            <i class="ti ti-calendar-event me-1"></i>{{ $deal->expected_close_date?->format('d.m') ?? '—' }}
                        </span>
                    </div>
                    @can('checkBoxPermission', 'crm.deals.manage@company')
                    <form method="post" action="{{ route('crm.deals.move', $deal) }}" class="mt-2">
                        @csrf
                        <select name="stage" class="form-select form-select-sm" aria-label="Стадия сделки «{{ $deal->title }}»" onchange="this.form.submit()">
                            @foreach ($stages as $option)
                                <option value="{{ $option->value }}" @selected($deal->stage === $option)>{{ $crm_label('crm_deal_stage', $option->value) }}</option>
                            @endforeach
                        </select>
                    </form>
                    @endcan
                </div>
            @empty
                <div class="crm-kanban__empty">Пусто</div>
            @endforelse
        </div>
    @endforeach
</div>
<div class="text-secondary small mt-2"><i class="ti ti-info-circle me-1"></i>Стадия меняется в карточке сделки; «Выиграна»/«Проиграна» закрывают сделку и фиксируют дату.</div>

@can('checkBoxPermission', 'crm.deals.manage@company')
<div class="modal modal-blur fade" id="newDeal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('crm.deals.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новая сделка</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>Сделка</h3>
                    <div class="crm-form__desc">Что продаём и кому — название и клиент видны в воронке.</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ndeal-title">Название<span class="req">*</span></label>
                            <input type="text" id="ndeal-title" name="title" class="form-control" value="{{ old('title') }}" placeholder="Договор на обслуживание" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ndeal-client">Клиент<span class="req">*</span></label>
                            <select id="ndeal-client" name="client_id" class="form-select" required>
                                <option value="">— выберите —</option>
                                @foreach ($clients as $client)
                                    <option value="{{ $client->id }}" @selected((string) old('client_id') === (string) $client->id)>{{ $client->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>Воронка</h3>
                    <div class="crm-form__desc">Сумма и стадия рядом — видно, на каком этапе сделка и чего стоит.</div>
                    <div class="crm-form__row crm-form__row--3">
                        <div class="crm-form__field">
                            <label class="form-label" for="ndeal-amount">Сумма</label>
                            <input type="number" id="ndeal-amount" name="amount" class="form-control" value="{{ old('amount', 0) }}" step="0.01" min="0">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ndeal-stage">Стадия</label>
                            <select id="ndeal-stage" name="stage" class="form-select">
                                @foreach ($stages as $stage)
                                    <option value="{{ $stage->value }}" @selected(old('stage', 'new') === $stage->value)>{{ $crm_label('crm_deal_stage', $stage->value) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ndeal-probability">Вероятность, %</label>
                            <input type="number" id="ndeal-probability" name="probability" class="form-control" value="{{ old('probability', 10) }}" min="0" max="100">
                        </div>
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>Сроки и ответственные</h3>
                    <div class="crm-form__desc">Ожидаемое закрытие и ответственный в одной строке — обе даты и зоны видны сразу.</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ndeal-expected">Ожидаемое закрытие</label>
                            <input type="date" id="ndeal-expected" name="expected_close_date" class="form-control" value="{{ old('expected_close_date') }}">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ndeal-owner">Ответственный</label>
                            <select id="ndeal-owner" name="owner_id" class="form-select">
                                <option value="">— не назначен —</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}" @selected((string) old('owner_id') === (string) $employee->id)>{{ $employee->last_name }} {{ $employee->first_name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>Примечание</h3>
                    <div class="crm-form__desc">Детали договорённостей — то, что важно помнить через месяц.</div>
                    <div class="crm-form__field mb-0">
                        <textarea data-crm-rich id="ndeal-notes" name="notes" class="form-control" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Создать сделку</button>
            </div>
        </form>
    </div>
</div>

@foreach ($stages as $stage)
    @foreach (($board[$stage->value] ?? collect()) as $deal)
    <div class="modal modal-blur fade" id="editDeal{{ $deal->id }}" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <form class="modal-content" method="post" action="{{ route('crm.deals.update', $deal) }}" data-submit-guard>
                @csrf
                @method('patch')
                <div class="modal-header"><h5 class="modal-title">Изменить сделку</h5></div>
                <div class="modal-body">
                    <div class="crm-form__section">
                        <h3>Сделка</h3>
                        <div class="crm-form__desc">Название и клиент в одной строке — как в форме создания.</div>
                        <div class="crm-form__row">
                            <div class="crm-form__field">
                                <label class="form-label" for="ed-title-{{ $deal->id }}">Название<span class="req">*</span></label>
                                <input type="text" id="ed-title-{{ $deal->id }}" name="title" class="form-control" value="{{ $deal->title }}" required>
                            </div>
                            <div class="crm-form__field">
                                <label class="form-label" for="ed-client-{{ $deal->id }}">Клиент<span class="req">*</span></label>
                                <select id="ed-client-{{ $deal->id }}" name="client_id" class="form-select" required>
                                    @foreach ($clients as $client)
                                        <option value="{{ $client->id }}" @selected((int) $deal->client_id === (int) $client->id)>{{ $client->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="crm-form__section">
                        <h3>Воронка</h3>
                        <div class="crm-form__desc">Сумма и вероятность рядом — пересчёт ожидаемого дохода на глаз.</div>
                        <div class="crm-form__row">
                            <div class="crm-form__field">
                                <label class="form-label" for="ed-amount-{{ $deal->id }}">Сумма</label>
                                <input type="number" id="ed-amount-{{ $deal->id }}" name="amount" class="form-control" value="{{ (float) $deal->amount }}" step="0.01" min="0">
                            </div>
                            <div class="crm-form__field">
                                <label class="form-label" for="ed-probability-{{ $deal->id }}">Вероятность, %</label>
                                <input type="number" id="ed-probability-{{ $deal->id }}" name="probability" class="form-control" value="{{ $deal->probability }}" min="0" max="100">
                            </div>
                        </div>
                    </div>
                    <div class="crm-form__section">
                        <h3>Сроки и ответственные</h3>
                        <div class="crm-form__desc">Срок закрытия и ответственный в одной строке — выровнено с формой создания.</div>
                        <div class="crm-form__row">
                            <div class="crm-form__field">
                                <label class="form-label" for="ed-expected-{{ $deal->id }}">Ожидаемое закрытие</label>
                                <input type="date" id="ed-expected-{{ $deal->id }}" name="expected_close_date" class="form-control" value="{{ $deal->expected_close_date?->toDateString() }}">
                            </div>
                            <div class="crm-form__field">
                                <label class="form-label" for="ed-owner-{{ $deal->id }}">Ответственный</label>
                                <select id="ed-owner-{{ $deal->id }}" name="owner_id" class="form-select">
                                    <option value="">— не назначен —</option>
                                    @foreach ($employees as $employee)
                                        <option value="{{ $employee->id }}" @selected((int) $deal->owner_id === (int) $employee->id)>{{ $employee->last_name }} {{ $employee->first_name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="crm-form__section mb-0">
                        <h3>Примечание</h3>
                        <div class="crm-form__desc">Детали договорённостей и изменения по сделке.</div>
                        <div class="crm-form__field mb-0">
                            <textarea data-crm-rich id="ed-notes-{{ $deal->id }}" name="notes" class="form-control" rows="2" maxlength="2000">{{ $deal->notes }}</textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">Сохранить</button>
                </div>
            </form>
        </div>
    </div>
    @endforeach
@endforeach
@endcan
@endsection
