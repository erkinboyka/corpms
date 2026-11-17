@extends('layouts.tabler')

@section('title', 'Мой график')

@php
    $kindTones = [
        'public' => '#d63939',
        'operating' => '#4299e1',
        'shortened' => '#f76707',
    ];
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Планирование</div>
            <h2 class="page-title mt-1">Мой график</h2>
            <div class="text-secondary mt-1">Рабочее время, история назначений и производственный календарь</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary" href="{{ route('schedules.index') }}"><i class="ti ti-clock me-1"></i>Графики работы</a>
        </div>
    </div>
</div>

{{-- ===== Текущий график ===== --}}
@if ($schedule !== null)
<div class="crm-profile-hero card mb-3">
    <div class="card-body">
        <div class="d-flex flex-column flex-md-row align-items-md-start gap-3">
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="crm-chip crm-chip--cyan">{{ $crm_label('schedule_type', $schedule->type->value) }}</span>
                    <span class="crm-chip crm-chip--grey">допуск {{ $schedule->grace_minutes ?? 10 }} мин</span>
                    <span class="crm-chip crm-chip--grey">отдых ≥ {{ $schedule->min_rest_hours ?? 8 }} ч</span>
                </div>
                <div class="crm-eyebrow mt-2">Текущий график</div>
                <h2 class="page-title mt-1 mb-1">{{ $schedule->name }}</h2>
                <div class="d-flex align-items-baseline gap-2 flex-wrap" style="font-size: 1.6rem; font-weight: 700; letter-spacing: -.02em; font-variant-numeric: tabular-nums;">
                    {{ \Carbon\Carbon::parse($schedule->start_time)->format('H:i') }}
                    <span class="text-secondary fw-normal" style="font-size: 1.1rem;">–</span>
                    {{ \Carbon\Carbon::parse($schedule->end_time)->format('H:i') }}
                </div>
                <div class="text-secondary mt-1">
                    @if ($schedule->break_start !== null)
                        <i class="ti ti-coffee me-1"></i>обед {{ \Carbon\Carbon::parse($schedule->break_start)->format('H:i') }}–{{ \Carbon\Carbon::parse($schedule->break_end)->format('H:i') }}
                    @else
                        <i class="ti ti-coffee me-1"></i>без обеденного перерыва
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@else
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
        <i class="ti ti-alert-triangle"></i>
        <div>График не назначен — обратитесь к HR.</div>
    </div>
@endif

<div class="row g-3">
    {{-- ===== История назначений ===== --}}
    <div class="col-lg-7">
        <div class="crm-form__section">
            <h3>История назначений <span class="text-secondary fw-normal">({{ $assignments->count() }})</span></h3>
            <div class="crm-form__desc">Какие графики действовали и когда.</div>
            <div class="crm-timeline">
                @forelse ($assignments as $assignment)
                    <div class="crm-timeline__item">
                        <div class="crm-timeline__time">
                            {{ $assignment->effective_from->format('d.m.Y') }} — {{ $assignment->effective_to?->format('d.m.Y') ?? 'действует' }}
                        </div>
                        <div class="crm-timeline__title">
                            <strong>{{ $assignment->workSchedule?->name ?? '—' }}</strong>
                            @if ($assignment->effective_to === null)
                                <span class="crm-chip crm-chip--green ms-1">текущий</span>
                            @endif
                        </div>
                        @if ($assignment->workSchedule)
                            <div class="crm-timeline__text">
                                {{ \Carbon\Carbon::parse($assignment->workSchedule->start_time)->format('H:i') }}
                                – {{ \Carbon\Carbon::parse($assignment->workSchedule->end_time)->format('H:i') }}
                                · {{ $crm_label('schedule_type', $assignment->workSchedule->type->value) }}
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="crm-timeline__text">Назначений пока нет.</div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ===== Праздники ===== --}}
    <div class="col-lg-5">
        <div class="crm-form__section">
            <h3>Праздники {{ now()->year }}</h3>
            <div class="crm-form__desc">Производственный календарь на текущий год.</div>
            <div class="crm-queue">
                @forelse ($holidays as $holiday)
                    <div class="crm-queue__item" style="--c: {{ $kindTones[$holiday->kind->value] ?? '#6c7a91' }};">
                        <div class="crm-queue__body">
                            <div class="crm-queue__title">{{ $holiday->name }}</div>
                            <div class="crm-queue__sub">{{ $holiday->date->format('d.m.Y') }} · {{ $holiday->date->translatedFormat('l') }}</div>
                        </div>
                        <span class="crm-chip {{ [
                            'public' => 'crm-chip--red',
                            'operating' => 'crm-chip--cyan',
                            'shortened' => 'crm-chip--orange',
                        ][$holiday->kind->value] ?? 'crm-chip--grey' }}">{{ $crm_label('holiday_kind', $holiday->kind->value) }}</span>
                    </div>
                @empty
                    <div class="crm-queue__item" style="--c: #6c7a91;">
                        <div class="crm-queue__body">
                            <div class="crm-queue__title">Праздников нет</div>
                            <div class="crm-queue__sub">Производственный календарь на год пуст.</div>
                        </div>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
