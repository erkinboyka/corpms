@extends('layouts.tabler')
@section('title', __('Обучение'))
@section('content')
<div class="container-xl py-4 lms-page">
    <x-page-header :eyebrow="__('Офис')" :title="__('Обучение и знания')" :subtitle="__('Курсы, общая доска, практика и живой созвон в одном пространстве')">
        @if ($canManage)
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#lmsCourseModal">
                <i class="ti ti-plus me-1"></i>{{ __('Новый курс') }}
            </button>
        @endif
        <a href="{{ route('lms.pulse') }}" class="btn btn-outline-secondary">
            <i class="ti ti-pulse me-1"></i>{{ __('Пульс') }}
        </a>
    </x-page-header>

    <div class="crm-metrics mb-4">
        <div class="crm-metric">
            <div class="crm-metric__label"><i class="ti ti-school me-1"></i>{{ __('Курсы') }}</div>
            <div class="crm-metric__value">{{ $stats['courses'] }}</div>
        </div>
        <div class="crm-metric">
            <div class="crm-metric__label"><i class="ti ti-book me-1"></i>{{ __('Уроки') }}</div>
            <div class="crm-metric__value">{{ $stats['lessons'] }}</div>
        </div>
        <div class="crm-metric">
            <div class="crm-metric__label"><i class="ti ti-users me-1"></i>{{ __('Мои курсы') }}</div>
            <div class="crm-metric__value">{{ $stats['enrolled'] }}</div>
        </div>
        <div class="crm-metric">
            <div class="crm-metric__label"><i class="ti ti-certificate me-1"></i>{{ __('Завершено') }}</div>
            <div class="crm-metric__value">{{ $stats['completed'] }}</div>
        </div>
        <div class="crm-metric">
            <div class="crm-metric__label"><i class="ti ti-activity me-1"></i>{{ __('Средний прогресс') }}</div>
            <div class="crm-metric__value">{{ $stats['average'] }}%</div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h2 class="lms-section-title mb-0"><i class="ti ti-layout-grid me-2"></i>{{ __('Курсы') }}</h2>
                <a href="{{ route('lms.assignments.index') }}" class="btn btn-sm btn-ghost-secondary">
                    <i class="ti ti-list-check me-1"></i>{{ __('Доска практики') }}
                </a>
            </div>

            <div class="row g-3">
                @forelse ($courses as $course)
                    @php
                        $mine = $course->enrollments->first();
                    @endphp
                    <div class="col-md-6">
                        <a class="lms-course-card" href="{{ route('lms.courses.show', $course) }}" style="--lms-accent: {{ $course->cover_color }}">
                            <div class="lms-course-card__glow"></div>
                            @if ($course->photo_path)
                                @include('partials.entity-photo-display', [
                                    'photoPath' => $course->photo_path,
                                    'photoClass' => 'lms-course-card__photo',
                                    'photoAlt' => $course->title,
                                ])
                            @endif
                            <div class="lms-course-card__top">
                                <span class="lms-course-card__icon"><i class="ti ti-school"></i></span>
                                <span class="crm-chip crm-chip--{{ $course->status?->value === 'published' ? 'green' : 'cyan' }}">
                                    {{ $crm_label('lms_course_status', $course->status?->value) }}
                                </span>
                            </div>
                            <h3 class="lms-course-card__title">{{ $course->title }}</h3>
                            <p class="lms-course-card__text">{{ $course->summary ?: __('Описание курса появится в рабочей программе') }}</p>
                            <div class="lms-course-card__stats">
                                <span><i class="ti ti-book me-1"></i>{{ $course->lessons_count }} {{ __('уроков') }}</span>
                                <span><i class="ti ti-users me-1"></i>{{ $course->enrollments_count }}</span>
                            </div>
                            @if ($mine)
                                <div class="lms-progress"><span style="width: {{ $mine->progress_percent }}%"></span></div>
                                <div class="lms-course-card__foot">{{ __('Ваш прогресс') }}: {{ $mine->progress_percent }}%</div>
                            @else
                                <div class="lms-course-card__foot">{{ __('Открыть программу') }} <i class="ti ti-arrow-right"></i></div>
                            @endif
                        </a>
                    </div>
                @empty
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                @include('partials.empty-state', [
                                    'icon' => 'school',
                                    'title' => __('Курсов пока нет'),
                                    'text' => __('Создайте первый курс — и у команды появится общая точка роста'),
                                    'actionUrl' => $canManage ? '#lmsCourseModal' : null,
                                    'actionLabel' => __('Создать курс'),
                                ])
                            </div>
                        </div>
                    </div>
                @endforelse
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card lms-side-card mb-3">
                <div class="card-header">
                    <h2 class="card-title mb-0"><i class="ti ti-sparkles me-2"></i>{{ __('Наставник') }}</h2>
                </div>
                <div class="card-body p-0">
                    @include('lms.partials.mentor', ['tips' => $tips])
                </div>
            </div>

            <div class="card lms-side-card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="card-title mb-0"><i class="ti ti-pulse me-2"></i>{{ __('Пульс Бumi') }}</h2>
                    <span class="lms-live-badge"><span></span>{{ __('live') }}</span>
                </div>
                <div class="card-body p-0">
                    @include('lms.partials.pulse-feed', [
                        'activities' => $activities,
                        'pollUrl' => route('lms.pulse.events'),
                        'pollMs' => config('lms.poll_ms', 1600),
                    ])
                </div>
            </div>
        </div>
    </div>
</div>

@if ($canManage)
    <div class="modal modal-blur fade" id="lmsCourseModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <form method="post" action="{{ route('lms.courses.store') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title mb-0"><i class="ti ti-school me-2"></i>{{ __('Новый курс') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Закрыть') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="lms-course-title">{{ __('Название') }}</label>
                            <input id="lms-course-title" name="title" class="form-control" required maxlength="180" placeholder="{{ __('Например: Онбординг в BM Systems') }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="lms-course-summary">{{ __('Коротко о курсе') }}</label>
                            <input id="lms-course-summary" name="summary" class="form-control" maxlength="500">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="lms-course-desc">{{ __('Программа') }}</label>
                            <textarea data-crm-rich id="lms-course-desc" name="description" class="form-control" rows="4"></textarea>
                        </div>
                        @include('partials.entity-photo-field', ['id' => 'lms-course-photo', 'photoPath' => null])
                        <div class="row g-3 align-items-end">
                            <div class="col-auto">
                                <label class="form-label" for="lms-course-color">{{ __('Цвет курса') }}</label>
                                <input id="lms-course-color" type="color" name="cover_color" class="form-control form-control-color" value="#206bc4">
                            </div>
                            <div class="col">
                                <label class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="publish" value="1" checked>
                                    <span class="form-check-label">{{ __('Сразу опубликовать') }}</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                        <button class="btn btn-primary" type="submit"><i class="ti ti-plus me-1"></i>{{ __('Создать курс') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
@endsection
