@extends('layouts.tabler')

@section('title', 'BI — Срезы витрины')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">BI</div>
            <h2 class="page-title mt-1">Срезы витрины</h2>
            <div class="text-secondary mt-1">Метрики по дням · период {{ $month }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('bi.index', ['month' => $month]) }}"><i class="ti ti-chart-histogram me-1"></i>Дашборды</a>
            @can('checkBoxPermission', 'bi.manage')
            <form method="post" action="{{ route('bi.rebuild') }}" class="d-inline">
                @csrf
                <input type="hidden" name="month" value="{{ $month }}">
                <button class="btn btn-primary"><i class="ti ti-refresh me-1"></i>Пересобрать витрину</button>
            </form>
            @endcan
        </div>
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
                    <th>Метрика</th>
                    <th>Тип</th>
                    <th class="text-end">Итог</th>
                    <th class="text-end">Среднее/день</th>
                    <th class="text-end">Мин</th>
                    <th class="text-end">Макс</th>
                    <th class="text-center">Дней</th>
                    <th style="width: 180px;">Динамика</th>
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
                                {{ $row['meta']['kind'] === 'event' ? 'по событиям' : 'состояние' }}
                            </span>
                        </td>
                        <td class="text-end fw-semibold">{{ number_format((float) $row['summary']['sum'], 0, ',', ' ') }}</td>
                        <td class="text-end text-secondary">{{ number_format((float) $row['summary']['avg'], 0, ',', ' ') }}</td>
                        <td class="text-end text-secondary">{{ number_format((float) $row['summary']['min'], 0, ',', ' ') }}</td>
                        <td class="text-end text-secondary">{{ number_format((float) $row['summary']['max'], 0, ',', ' ') }}</td>
                        <td class="text-center">{{ $row['summary']['days'] }}</td>
                        <td>
                            @if ($row['points'] !== '')
                                <svg viewBox="0 0 240 48" width="160" height="40" preserveAspectRatio="none" role="img" aria-label="Динамика {{ $row['meta']['label'] }}">
                                    <polyline points="{{ $row['points'] }}" fill="none" stroke="var(--crm-accent, #8BA9FF)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></polyline>
                                </svg>
                            @else
                                <span class="text-secondary small">нет данных</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8">@include('partials.empty-state', ['icon' => 'chart-line', 'title' => 'Витрина пуста', 'text' => 'Нажмите «Пересобрать витрину», чтобы собрать метрики за месяц из финансов, CRM, задач и склада.'])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="text-secondary small mt-2">
    <i class="ti ti-info-circle me-1"></i>«По событиям» суммируется за период (доходы, сделки, задачи), «состояние» — последнее значение на конец периода (штат, проекты, запасы).
</div>
@endsection
