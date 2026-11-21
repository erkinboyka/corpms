@extends('layouts.tabler')
@section('workspace_layout', 'ledger')

@section('title', __('Ведомость') . ' ' . $period->label())

@php
    $statusChip = match ($period->status->value) {
        'draft' => 'crm-chip--grey',
        'calculated' => 'crm-chip--cyan',
        'approved' => 'crm-chip--blue',
        default => 'crm-chip--green',
    };
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Зарплата · ведомость') }}</div>
            <h1 class="page-title mt-1">{{ $period->label() }} <span class="crm-chip {{ $statusChip }} ms-2">{{ $crm_label('payroll_status', $period->status->value) }}</span></h1>
            <div class="text-secondary mt-1">{{ __('Период #') }}{{ $period->id }} · {{ $period->company?->name ?? '' }}</div>
        </div>
        <div class="col-auto d-print-none">
            <div class="d-flex gap-1 flex-wrap justify-content-end">
                <a class="btn btn-ghost-secondary" href="{{ route('payroll.index') }}"><i class="ti ti-arrow-left me-1"></i>{{ __('К списку') }}</a>
                <a class="btn btn-ghost-secondary" href="{{ route('payroll.export', $period) }}"><i class="ti ti-download me-1"></i>CSV</a>
                @if ($canManage && in_array($period->status->value, ['draft', 'calculated'], true))
                    <form method="post" action="{{ route('payroll.calculate', $period) }}" class="d-inline" data-submit-guard>
                        @csrf
                        <button class="btn btn-primary"><i class="ti ti-calculator me-1"></i>{{ $period->status->value === 'draft' ? __('Рассчитать') : __('Пересчитать') }}</button>
                    </form>
                @endif
                @if ($canApprove && $period->status->value === 'calculated')
                    <form method="post" action="{{ route('payroll.approve', $period) }}" class="d-inline" data-submit-guard
                          onsubmit="return confirm('{{ __('Согласовать ведомость                       ? После согласования будет доступна выплата') }}.')">
                        @csrf
                        <button class="btn btn-primary"><i class="ti ti-check me-1"></i>{{ __('Согласовать') }}</button>
                    </form>
                @endif
                @if ($canApprove && $period->status->value === 'approved')
                    <form method="post" action="{{ route('payroll.pay', $period) }}" class="d-inline" data-submit-guard
                          onsubmit="return confirm('{{ __('Выплатить ведомость                        на сумму                                                 ? Действие необратимо') }}.')">
                        @csrf
                        <button class="btn btn-primary"><i class="ti ti-cash me-1"></i>{{ __('Выплатить') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-plus-circle"></i>{{ __('Начислено') }}</div>
        <div class="crm-metric__value">{{ number_format($totals['gross'], 2, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ __('все компоненты начислений') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-danger);"><i class="ti ti-minus-circle"></i>{{ __('Удержано') }}</div>
        <div class="crm-metric__value">{{ number_format($totals['deductions'], 2, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ __('авансы, налоги, прочее') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-cash-banknote"></i>{{ __('К выплате') }}</div>
        <div class="crm-metric__value">{{ number_format($totals['net'], 2, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ __('начислено минус удержания') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-users"></i>{{ __('Сотрудников') }}</div>
        <div class="crm-metric__value">{{ $totals['employees'] }}</div>
        <div class="crm-metric__hint">{{ __('в расчёте · строк') }} {{ $lines->count() }}</div>
    </div>
</div>

@if ($period->status->value === 'draft' && $lines->isEmpty())
    <div class="card">
        <div class="crm-empty">
            <span class="crm-empty__icon"><i class="ti ti-calculator"></i></span>
            <div class="crm-empty__title">{{ __('Ведомость не рассчитана') }}</div>
            <div class="crm-empty__text">{{ __('Расчёт соберёт строки из табеля (отработанные часы и переработки) и окладов сотрудников по активным компонентам компании') }}</div>
            @if ($canManage)
                <form method="post" action="{{ route('payroll.calculate', $period) }}" data-submit-guard>
                    @csrf
                    <button class="btn btn-primary btn-sm"><i class="ti ti-calculator me-1"></i>{{ __('Рассчитать') }}</button>
                </form>
            @endif
        </div>
    </div>
@else
    <div class="card mb-3">
        <div class="card-header">
            <h2 class="card-title bm-section-title">{{ __('Сводка по сотрудникам') }}</h2>
            <span class="crm-chip ms-auto">{{ $summary->count() }} {{ __('чел') }}</span>
        </div>
        <div class="table-responsive">
            <table data-record-key="0" class="table table-hover table-vcenter card-table mb-0">
                <thead>
                    <tr>
                        <th scope="col">{{ __('Сотрудник') }}</th><th scope="col" class="text-end">{{ __('Начислено') }}</th><th scope="col" class="text-end">{{ __('Удержано') }}</th>
                        <th scope="col" class="text-end">{{ __('К выплате') }}</th><th scope="col" class="text-end">{{ __('Строк') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($summary as $row)
                        <tr>
                            <td>
                                <div class="crm-table__main">{{ $row['employee']?->last_name }} {{ $row['employee']?->first_name }}</div>
                                <div class="crm-table__sub">{{ $row['employee']?->employee_no ?? '' }}</div>
                            </td>
                            <td class="text-end crm-table-num">{{ number_format($row['gross'], 2, ',', ' ') }}</td>
                            <td class="text-end crm-table-num">{{ number_format($row['deductions'], 2, ',', ' ') }}</td>
                            <td class="text-end crm-table-num fw-semibold">{{ number_format($row['net'], 2, ',', ' ') }}</td>
                            <td class="text-end text-secondary">{{ $row['lines'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-secondary">{{ __('Строки расчёта отсутствуют в пределах вашего области доступа') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title bm-section-title">{{ __('Детализация начислений') }}</h2>
            <span class="crm-chip ms-auto">{{ $lines->count() }} {{ __('строк') }}</span>
        </div>
        <div class="table-responsive">
            <table data-record-key="0" class="table table-hover table-vcenter card-table mb-0">
                <thead>
                    <tr>
                        <th scope="col">{{ __('Сотрудник') }}</th><th scope="col">{{ __('Компонент') }}</th><th scope="col">{{ __('Тип') }}</th><th scope="col">{{ __('Расчёт') }}</th>
                        <th scope="col" class="text-end">{{ __('Кол-во') }}</th><th scope="col" class="text-end">{{ __('Ставка') }}</th><th scope="col" class="text-end">{{ __('Сумма') }}</th><th scope="col">{{ __('Источник') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($lines as $line)
                        <tr>
                            <td>
                                <div class="crm-table__main">{{ $line->employee?->last_name }} {{ $line->employee?->first_name }}</div>
                                <div class="crm-table__sub">{{ $line->employee?->employee_no ?? '' }}</div>
                            </td>
                            <td>{{ $line->component?->name }}</td>
                            <td>
                                <span class="crm-chip {{ $line->component?->kind?->value === 'deduction' ? 'crm-chip--red' : 'crm-chip--green' }}">
                                    {{ $crm_label('payroll_component_kind', $line->component?->kind?->value) }}
                                </span>
                            </td>
                            <td class="text-secondary small">{{ $crm_label('payroll_calculation', $line->component?->calculation?->value) }}</td>
                            <td class="text-end crm-table-num">{{ number_format((float) $line->quantity, 2, ',', ' ') }}</td>
                            <td class="text-end crm-table-num">{{ number_format((float) $line->rate, 2, ',', ' ') }}</td>
                            <td class="text-end crm-table-num fw-semibold">{{ number_format((float) $line->amount, 2, ',', ' ') }}</td>
                            <td><span class="crm-chip {{ $line->source->value === 'manual' ? 'crm-chip--orange' : 'crm-chip--grey' }}">{{ $crm_label('payroll_line_source', $line->source->value) }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                @include('partials.empty-state', [
                                    'icon' => 'calculator',
                                    'title' => __('Строк пока нет'),
                                    'text' => __('Нажмите «Рассчитать» — строки соберутся из табеля и окладов.'),
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($period->calculated_at || $period->approved_at || $period->paid_at)
        <div class="card mt-3">
            <div class="card-header"><h2 class="card-title bm-section-title">{{ __('История ведомости') }}</h2></div>
            <div class="card-body">
                <div class="crm-timeline">
                    @if ($period->calculated_at)
                        <div class="crm-timeline__item">
                            <div class="crm-timeline__time">{{ $period->calculated_at->format('d.m.Y H:i') }}</div>
                            <div class="crm-timeline__title"><strong>{{ __('Рассчитано') }}</strong></div>
                            <div class="crm-timeline__text">{{ $period->calculatedBy?->name ?? '—' }} {{ __('начислено') }} {{ number_format($totals['gross'], 2, ',', ' ') }}</div>
                        </div>
                    @endif
                    @if ($period->approved_at)
                        <div class="crm-timeline__item">
                            <div class="crm-timeline__time">{{ $period->approved_at->format('d.m.Y H:i') }}</div>
                            <div class="crm-timeline__title"><strong>{{ __('Согласовано') }}</strong></div>
                            <div class="crm-timeline__text">{{ $period->approvedBy?->name ?? '—' }}</div>
                        </div>
                    @endif
                    @if ($period->paid_at)
                        <div class="crm-timeline__item">
                            <div class="crm-timeline__time">{{ $period->paid_at->format('d.m.Y H:i') }}</div>
                            <div class="crm-timeline__title"><strong>{{ __('Выплачено') }}</strong></div>
                            <div class="crm-timeline__text">{{ $period->paidBy?->name ?? '—' }} · {{ number_format($totals['net'], 2, ',', ' ') }}</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
@endif
@endsection
