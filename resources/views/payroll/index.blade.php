@extends('layouts.tabler')
@section('workspace_layout', 'ledger')

@section('title', 'Зарплата — Ведомости')

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Зарплата</div>
            <h1 class="page-title mt-1">Расчётные ведомости</h1>
            <div class="text-secondary mt-1">Детерминированный расчёт из табеля и окладов: черновик → расчёт → согласование → выплата</div>
        </div>
        <div class="col-auto d-print-none">
            @if ($canManage)
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newPeriod"><i class="ti ti-plus me-1"></i>Новый период</button>
            @endif
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-wallet"></i>Начислено всего</div>
        <div class="crm-metric__value">{{ $showAmounts ? number_format($totals['gross'], 2, ',', ' ') : '—' }}</div>
        <div class="crm-metric__hint">{{ $showAmounts ? 'по всем ведомостям' : 'суммы скрыты вашим доступом' }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-cash-banknote"></i>К выплате</div>
        <div class="crm-metric__value">{{ $showAmounts ? number_format($totals['net'], 2, ',', ' ') : '—' }}</div>
        <div class="crm-metric__hint">{{ $showAmounts ? 'после удержаний' : 'только ваш отдел' }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-calendar-stats"></i>Периодов</div>
        <div class="crm-metric__value">{{ $totals['periods'] }}</div>
        <div class="crm-metric__hint">создано ведомостей</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-coins"></i>Окладов оформлено</div>
        <div class="crm-metric__value">{{ $totals['salaries'] }}<small> / {{ $totals['components'] }}</small></div>
        <div class="crm-metric__hint">окладов / компонентов</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title bm-section-title">Ведомости</h2>
        <span class="crm-chip ms-auto">{{ $periods->count() }} всего</span>
    </div>
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table mb-0">
            <thead>
                <tr>
                    <th scope="col">Период</th><th scope="col">Статус</th><th scope="col" class="text-end">Сотрудников</th>
                    <th scope="col" class="text-end">Начислено</th><th scope="col" class="text-end">Удержано</th><th scope="col" class="text-end">К выплате</th>
                    <th scope="col" class="text-end">Обновлено</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($periods as $period)
                    @php
                        $statusChip = match ($period->status->value) {
                            'draft' => 'crm-chip--grey',
                            'calculated' => 'crm-chip--cyan',
                            'approved' => 'crm-chip--blue',
                            default => 'crm-chip--green',
                        };
                    @endphp
                    <tr>
                        <td>
                            <a class="crm-table__main text-reset fw-semibold" href="{{ route('payroll.show', $period) }}">{{ $period->label() }}</a>
                            <div class="crm-table__sub">#{{ $period->id }}</div>
                        </td>
                        <td><span class="crm-chip {{ $statusChip }}">{{ $crm_label('payroll_status', $period->status->value) }}</span></td>
                        <td class="text-end crm-table-num">{{ $period->employees_count }}</td>
                        <td class="text-end crm-table-num">{{ $showAmounts ? number_format((float) $period->gross_amount, 2, ',', ' ') : '—' }}</td>
                        <td class="text-end crm-table-num">{{ $showAmounts ? number_format((float) $period->deduction_amount, 2, ',', ' ') : '—' }}</td>
                        <td class="text-end crm-table-num fw-semibold">{{ $showAmounts ? number_format((float) $period->net_amount, 2, ',', ' ') : '—' }}</td>
                        <td class="text-end text-secondary small">
                            {{ ($period->paid_at ?? $period->approved_at ?? $period->calculated_at ?? $period->updated_at)?->format('d.m.Y H:i') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            @include('partials.empty-state', [
                                'icon' => 'wallet',
                                'title' => 'Ведомостей нет',
                                'text' => 'Создайте расчётный период — ведомость соберётся из табеля и окладов сотрудников.',
                                'actionUrl' => $canManage ? '#newPeriod' : route('payroll.salaries'),
                                'actionLabel' => $canManage ? 'Новый период' : 'Оклады и компоненты',
                            ])
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- модалка нового периода --}}
<div class="modal modal-blur fade" id="newPeriod" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('payroll.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новый расчётный период</h5></div>
            <div class="modal-body">
                <div class="crm-form__section mb-0">
                    <h3>Месяц расчёта</h3>
                    <div class="crm-form__desc">Период создаётся на весь месяц: с первого по последнее число. Повторное создание того же месяца блокируется.</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="np-month">Месяц<span class="req">*</span></label>
                        <input type="month" id="np-month" name="month" class="form-control" required value="{{ now()->format('Y-m') }}">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary"><i class="ti ti-plus me-1"></i>Создать</button>
            </div>
        </form>
    </div>
</div>
@endsection
