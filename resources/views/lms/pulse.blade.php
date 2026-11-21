@extends('layouts.tabler')
@section('title', __('Пульс Бumi'))
@section('content')
@php
    $typeIcons = [
        'course' => 'school', 'lesson' => 'book', 'lesson_done' => 'circle-check',
        'enroll' => 'user-plus', 'board' => 'pencil', 'meeting' => 'video',
        'assignment' => 'clipboard-list', 'submit' => 'send', 'review' => 'star',
    ];
@endphp
<div class="container-xl py-4 lms-page lms-pulse-page"
     data-lms-pulse-page
     data-poll-ms="{{ $pollMs }}"
     data-url-events="{{ route('lms.pulse.events') }}">
    <x-page-header :eyebrow="__('Обучение')" :title="__('Пульс Бumi')" :subtitle="__('Живая карта активности команды и подсказки наставника — то, чего нет в обычных LMS')">
        <a href="{{ route('lms.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>{{ __('К курсам') }}</a>
        <span class="lms-live-badge"><span></span>{{ __('live') }}</span>
    </x-page-header>

    <div class="crm-metrics mb-4">
        <div class="crm-metric"><div class="crm-metric__label"><i class="ti ti-school me-1"></i>{{ __('Курсы') }}</div><div class="crm-metric__value">{{ $stats['courses'] }}</div></div>
        <div class="crm-metric"><div class="crm-metric__label"><i class="ti ti-users me-1"></i>{{ __('Мои курсы') }}</div><div class="crm-metric__value">{{ $stats['enrolled'] }}</div></div>
        <div class="crm-metric"><div class="crm-metric__label"><i class="ti ti-certificate me-1"></i>{{ __('Завершено') }}</div><div class="crm-metric__value">{{ $stats['completed'] }}</div></div>
        <div class="crm-metric"><div class="crm-metric__label"><i class="ti ti-activity me-1"></i>{{ __('Средний прогресс') }}</div><div class="crm-metric__value">{{ $stats['average'] }}%</div></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <section class="card lms-pulse-map-card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="card-title mb-0"><i class="ti ti-radar me-2"></i>{{ __('Карта активности') }}</h2>
                    <span class="text-secondary small">{{ __('Последние события команды') }}</span>
                </div>
                <div class="card-body">
                    <div class="lms-pulse-map" id="lmsPulseMap">
                        <div class="lms-pulse-map__grid" aria-hidden="true"></div>
                        <div class="lms-pulse-map__core"><i class="ti ti-sparkles"></i><span>{{ __('Бumi') }}</span></div>
                        @forelse ($activities as $activity)
                            @php
                                $angle = ($loop->index * 137.5) * (pi() / 180);
                                $radius = 20 + ($loop->index % 4) * 8;
                                $x = 50 + $radius * cos($angle);
                                $y = 50 + $radius * sin($angle);
                            @endphp
                            <div class="lms-pulse-node @if ($loop->first) is-hot @endif"
                                 style="--x: {{ round($x, 1) }}%; --y: {{ round($y, 1) }}%"
                                 title="{{ $activity['label'] }} — {{ $activity['author'] }}">
                                <span class="lms-pulse-node__dot"><i class="ti ti-{{ $typeIcons[$activity['type']] ?? 'activity' }}"></i></span>
                                <span class="lms-pulse-node__label">{{ $activity['author'] }}</span>
                            </div>
                        @empty
                            <div class="lms-pulse-map__empty">{{ __('Карта ждёт первых событий') }}</div>
                        @endforelse
                    </div>
                    <div class="lms-pulse-legend">
                        <span><i class="ti ti-circle-check text-green"></i>{{ __('Урок пройден') }}</span>
                        <span><i class="ti ti-pencil text-orange"></i>{{ __('Работа на доске') }}</span>
                        <span><i class="ti ti-video text-blue"></i>{{ __('Созвон') }}</span>
                        <span><i class="ti ti-send text-cyan"></i>{{ __('Задание сдано') }}</span>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="card-title mb-0"><i class="ti ti-activity me-2"></i>{{ __('Лента событий') }}</h2>
                    <span class="lms-live-badge"><span></span>{{ __('live') }}</span>
                </div>
                <div class="card-body p-0">
                    @include('lms.partials.pulse-feed', [
                        'activities' => $activities,
                        'pollUrl' => route('lms.pulse.events'),
                        'pollMs' => $pollMs,
                    ])
                </div>
            </section>
        </div>

        <div class="col-lg-5">
            @include('lms.partials.mentor', ['tips' => $tips])

            <div class="card lms-side-card mt-3">
                <div class="card-header">
                    <h2 class="card-title mb-0"><i class="ti ti-bulb me-2"></i>{{ __('Почему это отличается') }}</h2>
                </div>
                <div class="card-body">
                    <ul class="lms-feature-list">
                        <li><i class="ti ti-pulse"></i><span><strong>{{ __('Пульс вместо отчётов') }}</strong><small>{{ __('Наставник видит «тихие» сигналы: давность активности, просрочки, работу на доске.') }}</small></span></li>
                        <li><i class="ti ti-users"></i><span><strong>{{ __('Доска как живой документ') }}</strong><small>{{ __('Курсоры коллег и штрихи сохраняются прямо в CRM.') }}</small></span></li>
                        <li><i class="ti ti-video"></i><span><strong>{{ __('Созвон без внешнего сервиса') }}</strong><small>{{ __('WebRTC-сигнализация через базу — работает даже когда Reverb недоступен.') }}</small></span></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    window.LMS_I18N = Object.assign(window.LMS_I18N || {}, {
        pulseNew: @json(__('Новое событие'))
    });
</script>
@endpush
