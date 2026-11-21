@extends('layouts.tabler')
@section('title', $lesson->title)
@section('content')
<div class="container-xl py-4 lms-page">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <a href="{{ route('lms.courses.show', $course) }}" class="btn btn-sm btn-ghost-secondary">
            <i class="ti ti-arrow-left me-1"></i>{{ __('К курсу') }}
        </a>
        <span class="text-secondary">{{ $course->title }} / {{ $lesson->title }}</span>
    </div>

    <x-page-header :eyebrow="__('Урок')" :title="$lesson->title" :subtitle="$lesson->summary ?: __('Материал урока и практика')">
        <span class="crm-chip crm-chip--{{ $done ? 'green' : 'cyan' }}">
            <i class="ti ti-{{ $done ? 'circle-check' : 'player-play' }} me-1"></i>
            {{ $done ? __('Пройден') : __('В работе') }}
        </span>
        @if (! $done)
            <form method="post" action="{{ route('lms.lessons.progress', $lesson) }}">
                @csrf
                <button class="btn btn-primary"><i class="ti ti-check me-1"></i>{{ __('Отметить пройденным') }}</button>
            </form>
        @endif
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-8">
            <section class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="card-title mb-0"><i class="ti ti-book me-2"></i>{{ __('Материал') }}</h2>
                    <span class="text-secondary">
                        <i class="ti ti-clock me-1"></i>{{ $lesson->duration_min }} {{ __('мин') }}
                    </span>
                </div>
                <div class="card-body">
                    @if (trim((string) $lesson->content) !== '')
                        <div class="lms-lesson-content">{!! nl2br(e($lesson->content)) !!}</div>
                    @else
                        @include('partials.empty-state', [
                            'icon' => 'notes',
                            'title' => __('Материал ещё готовится'),
                            'text' => __('Наставник добавит конспект — пока можно разобрать тему на общей доске'),
                        ])
                    @endif
                </div>
            </section>

            <div class="row g-3">
                <div class="col-md-6">
                    <a class="lms-action-card lms-action-card--board" href="{{ $board ? route('lms.whiteboards.show', $board) : '#' }}"
                       @if (! $board) aria-disabled="true" @endif>
                        <span class="lms-action-card__icon"><i class="ti ti-pencil"></i></span>
                        <span>
                            <strong>{{ __('Доска урока') }}</strong>
                            <small>{{ __('Схемы, заметки и курсоры команды') }}</small>
                        </span>
                        <i class="ti ti-arrow-right ms-auto"></i>
                    </a>
                </div>
                <div class="col-md-6">
                    @if ($meeting)
                        <a class="lms-action-card lms-action-card--meeting" href="{{ route('lms.meetings.show', $meeting) }}">
                            <span class="lms-action-card__icon"><i class="ti ti-video"></i></span>
                            <span>
                                <strong>{{ __('Видеозвонок') }}</strong>
                                <small>{{ $meeting->title }}</small>
                            </span>
                            <i class="ti ti-arrow-right ms-auto"></i>
                        </a>
                    @else
                        <div class="lms-action-card is-muted">
                            <span class="lms-action-card__icon"><i class="ti ti-video"></i></span>
                            <span>
                                <strong>{{ __('Видеозвонок') }}</strong>
                                <small>{{ __('Созвон по теме урока появится здесь') }}</small>
                            </span>
                        </div>
                    @endif
                </div>
            </div>

            @if ($assignments->isNotEmpty())
                <section class="card mt-3">
                    <div class="card-header">
                        <h2 class="card-title mb-0"><i class="ti ti-list-check me-2"></i>{{ __('Задания урока') }}</h2>
                    </div>
                    <div class="list-group list-group-flush">
                        @foreach ($assignments as $assignment)
                            <div class="list-group-item d-flex align-items-center gap-3">
                                <span class="lms-lesson-row__num"><i class="ti ti-clipboard-list"></i></span>
                                <span class="flex-fill">
                                    <strong>{{ $assignment->title }}</strong>
                                    <small class="d-block text-secondary">{{ $assignment->description ?: __('Практическое задание') }}</small>
                                </span>
                                <a href="{{ route('lms.assignments.index', ['course_id' => $course->id]) }}" class="btn btn-sm btn-outline-primary">
                                    {{ __('Сдать') }} <i class="ti ti-arrow-right ms-1"></i>
                                </a>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>

        <div class="col-lg-4">
            @include('lms.partials.mentor', ['tips' => $tips])

            <div class="card lms-side-card mt-3">
                <div class="card-header">
                    <h2 class="card-title mb-0"><i class="ti ti-route me-2"></i>{{ __('Маршрут') }}</h2>
                </div>
                <div class="card-body">
                    <div class="lms-ring lms-ring--small mx-auto" style="--lms-progress: {{ $enrollment?->progress_percent ?? 0 }}%">
                        <span>{{ $enrollment?->progress_percent ?? 0 }}%</span>
                    </div>
                    <div class="text-secondary text-center mt-3">
                        {{ $enrollment ? $crm_label('lms_enrollment_status', $enrollment->status?->value) : __('Вы ещё не записаны на курс') }}
                    </div>
                    @if (! $enrollment)
                        <form method="post" action="{{ route('lms.enroll', $course) }}" class="mt-3">
                            @csrf
                            <button class="btn btn-primary w-100"><i class="ti ti-user-plus me-1"></i>{{ __('Записаться на курс') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
