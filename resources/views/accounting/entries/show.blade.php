@extends('layouts.tabler')
@section('workspace_layout', 'ledger')

@section('title', __('Бухгалтерия — проводка №').$entry->id)

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Бухгалтерия · Проводка') }}</div>
            <h1 class="page-title mt-1">{{ $entry->description ?: __('Проводка №').$entry->id }}</h1>
            <div class="text-secondary mt-1 d-flex align-items-center gap-2 flex-wrap">
                <span class="crm-chip crm-chip--grey">№{{ $entry->id }}</span>
                <span class="crm-chip {{ $entry->source->value === 'finance' ? 'crm-chip--cyan' : 'crm-chip--grey' }}">{{ $crm_label('acc_entry_source', $entry->source->value) }}</span>
                <span>{{ $entry->entry_date?->format('d.m.Y') }}</span>
                @if ($entry->financeTransaction)<span>{{ __('операция финансов №') }}{{ $entry->financeTransaction->id }}</span>@endif
            </div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary" href="{{ route('accounting.entries.index') }}"><i class="ti ti-arrow-left me-1"></i>{{ __('К журналу') }}</a>
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-arrow-down-left"></i>{{ __('Дебет') }}</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format($debitTotal, 2, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ __('оборот по дебету') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-arrow-up-right"></i>{{ __('Кредит') }}</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format($creditTotal, 2, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ __('оборот по кредиту') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-purple);"><i class="ti ti-scale"></i>{{ __('Баланс') }}</div>
        <div class="crm-metric__value" style="font-size: 1rem; padding-top: 10px;">
            @if (abs($debitTotal - $creditTotal) < 0.01)
                <span class="crm-chip crm-chip--green">{{ __('сбалансирована') }}</span>
            @else
                <span class="crm-chip crm-chip--red">{{ __('расхождение') }}</span>
            @endif
        </div>
        <div class="crm-metric__hint">{{ __('дебет = кредит') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-user"></i>{{ __('Автор') }}</div>
        <div class="crm-metric__value" style="font-size: 1rem; padding-top: 10px;">{{ $entry->creator?->name ?? '—' }}</div>
        <div class="crm-metric__hint">{{ __('создал проводку') }}</div>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-title">{{ __('Строки проводки') }}</div></div>
    <div class="table-responsive">
        <table data-record-key="0" class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('Счёт') }}</th>
                    <th scope="col" class="text-end">{{ __('Дебет') }}</th>
                    <th scope="col" class="text-end">{{ __('Кредит') }}</th>
                    <th scope="col">{{ __('Комментарий') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($entry->lines as $line)
                    <tr>
                        <td>
                            <div class="crm-table__main">{{ $line->account?->code }} {{ $line->account?->name }}</div>
                            <div class="crm-table__sub">{{ $line->account ? $crm_label('acc_account_kind', $line->account->kind->value) : '—' }}</div>
                        </td>
                        <td class="text-end {{ (float) $line->debit > 0 ? 'fw-semibold' : 'text-secondary' }}">
                            {{ (float) $line->debit > 0 ? number_format((float) $line->debit, 2, ',', ' ') : '—' }}
                        </td>
                        <td class="text-end {{ (float) $line->credit > 0 ? 'fw-semibold' : 'text-secondary' }}">
                            {{ (float) $line->credit > 0 ? number_format((float) $line->credit, 2, ',', ' ') : '—' }}
                        </td>
                        <td class="text-secondary">{{ $line->description ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th scope="col">{{ __('Итого') }}</th>
                    <th scope="col" class="text-end">{{ number_format($debitTotal, 2, ',', ' ') }}</th>
                    <th scope="col" class="text-end">{{ number_format($creditTotal, 2, ',', ' ') }}</th>
                    <th scope="col"></th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
@endsection
