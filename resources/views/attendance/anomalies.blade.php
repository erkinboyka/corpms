@extends('layouts.tabler')
@section('workspace_layout', 'stream')

@section('title', __('Аномалии'))

@php
    $typeMeta = [
        'missing_checkout' => ['icon' => 'logout', 'tone' => 'orange'],
        'no_show' => ['icon' => 'user-x', 'tone' => 'red'],
        'duplicate' => ['icon' => 'copy', 'tone' => 'orange'],
        'direction_mismatch' => ['icon' => 'arrows-left-right', 'tone' => 'orange'],
        'unrecognized' => ['icon' => 'face-dashed', 'tone' => 'red'],
    ];
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Посещаемость') }}</div>
            <h1 class="page-title mt-1">{{ __('Аномалии') }}</h1>
            <div class="text-secondary mt-1">{{ __('Очередь обнаруженных проблем — приоритет и обработка') }}</div>
        </div>
    </div>
</div>

<div class="crm-toolbar">
    <span class="crm-toolbar__count"><i class="ti ti-filter me-1"></i>{{ __('Фильтр статуса') }}</span>
    <a class="btn btn-sm {{ !$statusFilter ? 'btn-primary' : 'btn-ghost-secondary' }}" href="{{ route('attendance.anomalies') }}">{{ __('Все') }}</a>
    <a class="btn btn-sm {{ $statusFilter === 'open' ? 'btn-primary' : 'btn-ghost-secondary' }}" href="{{ route('attendance.anomalies', ['status' => 'open']) }}">{{ __('Открытые') }}</a>
    <a class="btn btn-sm {{ $statusFilter === 'resolved' ? 'btn-primary' : 'btn-ghost-secondary' }}" href="{{ route('attendance.anomalies', ['status' => 'resolved']) }}">{{ __('Разобранные') }}</a>
    <div class="crm-toolbar__spacer"></div>
    <span class="crm-chip {{ ($statusFilter ?? 'open') === 'open' ? 'crm-chip--orange' : 'crm-chip--grey' }}">{{ $anomalies->total() }} {{ __('в выборке') }}</span>
</div>

<div class="crm-queue">
    @forelse ($anomalies as $anomaly)
        @php
            $meta = $typeMeta[$anomaly->type->value] ?? ['icon' => 'alert-triangle', 'tone' => 'grey'];
            $isOpen = $anomaly->status->value === 'open';
            $toneColor = match ($meta['tone']) {
                'red' => 'var(--crm-danger)',
                'orange' => 'var(--crm-warning)',
                'cyan' => 'var(--crm-info)',
                default => 'var(--crm-text-secondary)',
            };
        @endphp
        <div class="crm-queue__item" style="--c: {{ $toneColor }};">
            <span class="crm-entity__logo" style="width:38px;height:38px;font-size:15px;border-radius:11px;background:{{ $toneColor }}1f;color:{{ $toneColor }};">
                <i class="ti ti-{{ $meta['icon'] }}"></i>
            </span>
            <div class="crm-queue__body">
                <div class="crm-queue__title">
                    {{ $crm_label('anomaly_type', $anomaly->type->value) }}
                    <span class="crm-chip crm-chip--grey ms-1">{{ $anomaly->date->format('d.m.Y') }}</span>
                </div>
                <div class="crm-queue__sub">
                    {{ $anomaly->employee?->last_name ?? '—' }} {{ $anomaly->employee?->first_name ?? '' }}
                    @if ($anomaly->employee?->employee_no)· {{ $anomaly->employee->employee_no }}@endif
                    · {{ \Illuminate\Support\Str::limit($anomaly->description, 110) }}
                </div>
            </div>
            <span class="crm-chip {{ $isOpen ? 'crm-chip--orange' : 'crm-chip--green' }} d-none d-md-inline-flex">
                {{ $crm_label('anomaly_status', $anomaly->status->value) }}
            </span>
            @if ($canManage && $isOpen)
                <form method="post" action="{{ route('attendance.anomalies.resolve', $anomaly) }}" class="d-flex gap-1 align-items-center" data-submit-guard>
                    @csrf @method('PUT')
                    <input type="text" name="resolution" class="form-control form-control-sm" placeholder="{{ __("Что сделано") }}" required aria-label="{{ __("Решение по аномалии") }}">
                    <button class="btn btn-sm btn-primary" title="{{ __("Закрыть аномалию") }}"><i class="ti ti-check"></i></button>
                </form>
            @endif
        </div>
    @empty
        <div class="crm-queue__item" style="--c: var(--crm-success);">
            <span class="crm-entity__logo" style="width:38px;height:38px;font-size:15px;border-radius:11px;"><i class="ti ti-circle-check"></i></span>
            <div class="crm-queue__body">
                <div class="crm-queue__title">{{ __('Аномалий нет') }}</div>
                <div class="crm-queue__sub">{{ __('По выбранному фильтру проблем не обнаружено') }}</div>
            </div>
        </div>
    @endforelse
</div>
<div class="mt-3">{{ $anomalies->links() }}</div>
@endsection
