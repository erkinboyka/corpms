@extends('layouts.tabler')

@section('title', 'Аномалии')

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
            <div class="crm-eyebrow">Посещаемость</div>
            <h2 class="page-title mt-1">Аномалии</h2>
            <div class="text-secondary mt-1">Очередь обнаруженных проблем — приоритет и обработка</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary" href="{{ route('attendance.index') }}"><i class="ti ti-arrow-left me-1"></i>Сегодня</a>
        </div>
    </div>
</div>

<div class="crm-toolbar">
    <span class="crm-toolbar__count"><i class="ti ti-filter me-1"></i>Фильтр статуса:</span>
    <a class="btn btn-sm {{ !$statusFilter ? 'btn-primary' : 'btn-ghost-secondary' }}" href="{{ route('attendance.anomalies') }}">Все</a>
    <a class="btn btn-sm {{ $statusFilter === 'open' ? 'btn-primary' : 'btn-ghost-secondary' }}" href="{{ route('attendance.anomalies', ['status' => 'open']) }}">Открытые</a>
    <a class="btn btn-sm {{ $statusFilter === 'resolved' ? 'btn-primary' : 'btn-ghost-secondary' }}" href="{{ route('attendance.anomalies', ['status' => 'resolved']) }}">Разобранные</a>
    <div class="crm-toolbar__spacer"></div>
    <span class="crm-chip {{ ($statusFilter ?? 'open') === 'open' ? 'crm-chip--orange' : 'crm-chip--grey' }}">{{ $anomalies->count() }} в выборке</span>
</div>

<div class="crm-queue">
    @forelse ($anomalies as $anomaly)
        @php
            $meta = $typeMeta[$anomaly->type->value] ?? ['icon' => 'alert-triangle', 'tone' => 'grey'];
            $isOpen = $anomaly->status->value === 'open';
            $toneColor = match ($meta['tone']) {
                'red' => '#d63939',
                'orange' => '#f76707',
                'cyan' => '#4299e1',
                default => '#6c7a91',
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
                    <input type="text" name="resolution" class="form-control form-control-sm" placeholder="Что сделано" required aria-label="Решение по аномалии">
                    <button class="btn btn-sm btn-primary" title="Закрыть аномалию"><i class="ti ti-check"></i></button>
                </form>
            @endif
        </div>
    @empty
        <div class="crm-queue__item" style="--c: #2fb344;">
            <span class="crm-entity__logo" style="width:38px;height:38px;font-size:15px;border-radius:11px;"><i class="ti ti-circle-check"></i></span>
            <div class="crm-queue__body">
                <div class="crm-queue__title">Аномалий нет</div>
                <div class="crm-queue__sub">По выбранному фильтру проблем не обнаружено.</div>
            </div>
        </div>
    @endforelse
</div>
@endsection
