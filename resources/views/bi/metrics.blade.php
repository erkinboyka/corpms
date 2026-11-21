@extends('layouts.tabler')
@section('workspace_layout', 'ledger')

@section('title', __('BI — Срезы витрины'))

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">BI</div>
            <h1 class="page-title mt-1">{{ __('Срезы витрины') }}</h1>
            <div class="text-secondary mt-1">{{ __('Метрики по дням · период') }} {{ $month }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('bi.index', ['month' => $month]) }}"><i class="ti ti-chart-histogram me-1"></i>{{ __('Дашборды') }}</a>
            @can('checkBoxPermission', 'bi.manage')
            <form method="post" action="{{ route('bi.rebuild') }}" class="d-inline">
                @csrf
                <input type="hidden" name="month" value="{{ $month }}">
                <button class="btn btn-primary"><i class="ti ti-refresh me-1"></i>{{ __('Пересобрать витрину') }}</button>
            </form>
            @endcan
        </div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <input type="month" name="month" class="form-control" value="{{ $month }}" aria-label="{{ __("Месяц") }}" onchange="this.form.submit()">
    <button class="btn btn-ghost-secondary"><i class="ti ti-calendar me-1"></i>{{ __('Показать месяц') }}</button>
</form>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('Метрика') }}</th>
                    <th scope="col">{{ __('Тип') }}</th>
                    <th scope="col" class="text-end">{{ __('Итог') }}</th>
                    <th scope="col" class="text-end">{{ __('Среднее/день') }}</th>
                    <th scope="col" class="text-end">{{ __('Мин') }}</th>
                    <th scope="col" class="text-end">{{ __('Макс') }}</th>
                    <th scope="col" class="text-center">{{ __('Дней') }}</th>
                    <th scope="col" style="width: 180px;">{{ __('Динамика') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>
                            <div class="crm-table__main">{{ $row['meta']['label'] }}</div>
                            <div class="crm-table__sub">{{ $row['code'] }}</div>
                        </td>
                        <td>
                            <span class="crm-chip {{ $row['meta']['kind'] === 'event' ? 'crm-chip--cyan' : 'crm-chip--grey' }}">
                                {{ $row['meta']['kind'] === 'event' ? __('по событиям') : __('состояние') }}
                            </span>
                        </td>
                        <td class="text-end fw-semibold">{{ number_format((float) $row['summary']['sum'], 0, ',', ' ') }}</td>
                        <td class="text-end text-secondary">{{ number_format((float) $row['summary']['avg'], 0, ',', ' ') }}</td>
                        <td class="text-end text-secondary">{{ number_format((float) $row['summary']['min'], 0, ',', ' ') }}</td>
                        <td class="text-end text-secondary">{{ number_format((float) $row['summary']['max'], 0, ',', ' ') }}</td>
                        <td class="text-center">{{ $row['summary']['days'] }}</td>
                        <td>
                            @if ($row['points'] !== '')
                                <svg viewBox="0 0 240 48" width="160" height="40" preserveAspectRatio="none" role="img" aria-label="{{ __('Динамика') }} {{ $row['meta']['label'] }}">
                                    <polyline points="{{ $row['points'] }}" fill="none" stroke="var(--crm-accent, var(--crm-accent))" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></polyline>
                                </svg>
                            @else
                                <span class="text-secondary small">{{ __('нет данных') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8">@include('partials.empty-state', ['icon' => 'chart-line', 'title' => __('Витрина пуста'), 'text' => __('Нажмите «Пересобрать витрину», чтобы собрать метрики за месяц из финансов, CRM, задач и склада.')])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="text-secondary small mt-2">
    <i class="ti ti-info-circle me-1"></i>{{ __('По событиям» суммируется за период (доходы, сделки, задачи), «состояние» — последнее значение на конец периода (штат, проекты, запасы)') }}
</div>
@endsection
