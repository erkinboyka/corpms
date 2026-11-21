@extends('layouts.tabler')
@section('title', $course->title)
@section('content')
<div class="container-xl py-4 lms-page">
    <div class="mb-3">
        <a href="{{ route('lms.index') }}" class="btn btn-sm btn-ghost-secondary"><i class="ti ti-arrow-left me-1"></i>{{ __('К курсам') }}</a>
    </div>

    <x-page-header :eyebrow="__('Курс')" :title="$course->title" :subtitle="$course->summary ?: __('Программа курса')">
        <span class="crm-chip crm-chip--{{ $course->status?->value === 'published' ? 'green' : 'cyan' }}">
            {{ $crm_label('lms_course_status', $course->status?->value) }}
        </span>
        @if ($canTeach)
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#lmsLessonModal">
                <i class="ti ti-plus me-1"></i>{{ __('Урок') }}
            </button>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#lmsMeetingModal">
                <i class="ti ti-video me-1"></i>{{ __('Созвон') }}
            </button>
        @endif
    </x-page-header>

    <div class="card lms-course-hero mb-3" style="--lms-accent: {{ $course->cover_color }}">
        <div class="lms-course-hero__glow"></div>
        <div class="card-body d-flex flex-wrap align-items-center gap-4">
            @if ($course->photo_path)
                @include('partials.entity-photo-display', [
                    'photoPath' => $course->photo_path,
                    'photoClass' => 'lms-course-hero__photo',
                    'photoAlt' => $course->title,
                ])
            @endif
            <div class="flex-fill" style="min-width: 240px;">
                <div class="lms-course-hero__eyebrow"><i class="ti ti-school me-1"></i>{{ __('Учебное пространство') }}</div>
                <h2 class="lms-course-hero__title mb-2">{{ $course->title }}</h2>
                @if (filled($course->description))
                    @include('partials.rich-text', ['value' => $course->description, 'class' => 'text-secondary'])
                @else
                    <div class="text-secondary" style="max-width: 720px">{{ $course->summary ?: __('Здесь собраны уроки, доска для разбора задач, практика и видеозвонки команды.') }}</div>
                @endif
            </div>
            <div class="lms-course-hero__action">
                @if ($enrollment)
                    <div class="lms-ring" style="--lms-progress: {{ $enrollment->progress_percent }}%">
                        <span>{{ $enrollment->progress_percent }}%</span>
                    </div>
                    <div class="text-secondary mt-2">{{ $crm_label('lms_enrollment_status', $enrollment->status?->value) }}</div>
                @else
                    <form method="post" action="{{ route('lms.enroll', $course) }}">
                        @csrf
                        <button class="btn btn-primary btn-lg"><i class="ti ti-user-plus me-1"></i>{{ __('Записаться на курс') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="row g-3 align-items-start">
        <div class="col-lg-8">
            <section class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="card-title mb-0"><i class="ti ti-list-numbers me-2"></i>{{ __('Уроки') }}</h2>
                    <span class="text-secondary">{{ $course->lessons->count() }}</span>
                </div>
                <div class="list-group list-group-flush">
                    @forelse ($course->lessons as $lesson)
                        @php $status = $progress[$lesson->id] ?? 'not_started'; @endphp
                        <a class="list-group-item list-group-item-action lms-lesson-row" href="{{ route('lms.lessons.show', $lesson) }}">
                            <span class="lms-lesson-row__num">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <span class="lms-lesson-row__body">
                                <strong>{{ $lesson->title }}</strong>
                                <small>{{ $lesson->summary ?: __('Материал урока и разбор на доске') }}</small>
                            </span>
                            <span class="lms-lesson-row__meta">
                                @if ($lesson->duration_min)<span><i class="ti ti-clock me-1"></i>{{ $lesson->duration_min }} {{ __('мин') }}</span>@endif
                                <span class="crm-chip crm-chip--{{ $status === 'completed' ? 'green' : 'cyan' }}">{{ $crm_label('lms_progress_status', $status) }}</span>
                            </span>
                            <i class="ti ti-chevron-right text-secondary"></i>
                        </a>
                    @empty
                        <div class="card-body">@include('partials.empty-state', ['icon' => 'book', 'title' => __('Уроков пока нет')])</div>
                    @endforelse
                </div>
            </section>

            <section class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="card-title mb-0"><i class="ti ti-pencil me-2"></i>{{ __('Общая доска') }}</h2>
                    @if ($board)
                        <a href="{{ route('lms.whiteboards.show', $board) }}" class="btn btn-sm btn-outline-primary">{{ __('Открыть доску') }} <i class="ti ti-arrow-right ms-1"></i></a>
                    @endif
                </div>
                <div class="card-body">
                    <div class="lms-board-teaser">
                        <div class="lms-board-teaser__canvas" aria-hidden="true">
                            <span class="lms-board-teaser__line lms-board-teaser__line--1"></span>
                            <span class="lms-board-teaser__line lms-board-teaser__line--2"></span>
                            <span class="lms-board-teaser__note">💡 {{ __('Идея команды') }}</span>
                            <span class="lms-board-teaser__cursor"><i class="ti ti-pointer"></i>{{ __('Алексей') }}</span>
                        </div>
                        <div>
                            <h3 class="mb-1">{{ $board?->title ?: __('Доска курса') }}</h3>
                            <p class="text-secondary mb-3">{{ __('Рисуйте схемы, оставляйте заметки и видите курсоры коллег в реальном времени. Все штрихи сохраняются и синхронизируются между участниками.') }}</p>
                            <div class="d-flex flex-wrap gap-2">
                                <span class="crm-chip crm-chip--cyan"><i class="ti ti-pencil me-1"></i>{{ __('Рисование') }}</span>
                                <span class="crm-chip crm-chip--orange"><i class="ti ti-users me-1"></i>{{ __('Курсоры команды') }}</span>
                                <span class="crm-chip crm-chip--green"><i class="ti ti-history me-1"></i>{{ __('История штрихов') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-lg-4">
            @include('lms.partials.mentor', ['tips' => $tips])

            <div class="card lms-side-card mt-3">
                <div class="card-header">
                    <h2 class="card-title mb-0"><i class="ti ti-video me-2"></i>{{ __('Видеозвонки') }}</h2>
                </div>
                <div class="card-body p-0">
                    @forelse ($meetings as $meeting)
                        <a class="lms-meeting-row" href="{{ route('lms.meetings.show', $meeting) }}">
                            <span class="lms-meeting-row__icon"><i class="ti ti-video"></i></span>
                            <span class="flex-fill">
                                <strong>{{ $meeting->title }}</strong>
                                <small>{{ $crm_label('lms_meeting_status', $meeting->status?->value) }}</small>
                            </span>
                            <i class="ti ti-arrow-right text-secondary"></i>
                        </a>
                    @empty
                        <div class="p-3">@include('partials.empty-state', ['icon' => 'video', 'title' => __('Созвонов пока нет')])</div>
                    @endforelse
                </div>
            </div>

            <div class="card lms-side-card mt-3">
                <div class="card-header">
                    <h2 class="card-title mb-0"><i class="ti ti-pulse me-2"></i>{{ __('Пульс курса') }}</h2>
                </div>
                <div class="card-body p-0">
                    @include('lms.partials.pulse-feed', ['activities' => $activities ?? [], 'pollUrl' => route('lms.pulse.events')])
                </div>
            </div>
        </div>
    </div>
</div>

@if ($canTeach)
    <div class="modal modal-blur fade" id="lmsLessonModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <form method="post" action="{{ route('lms.lessons.store', $course) }}">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title mb-0"><i class="ti ti-book me-2"></i>{{ __('Новый урок') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Закрыть') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="lms-lesson-title">{{ __('Название') }}</label>
                            <input id="lms-lesson-title" name="title" class="form-control" required maxlength="180">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="lms-lesson-summary">{{ __('Коротко') }}</label>
                            <input id="lms-lesson-summary" name="summary" class="form-control" maxlength="500">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="lms-lesson-content">{{ __('Материал') }}</label>
                            <textarea data-crm-rich id="lms-lesson-content" name="content" class="form-control" rows="5"></textarea>
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label" for="lms-lesson-kind">{{ __('Формат') }}</label>
                                <select id="lms-lesson-kind" name="kind" class="form-select">
                                    <option value="text">{{ __('Текст') }}</option>
                                    <option value="whiteboard">{{ __('Доска') }}</option>
                                    <option value="meeting">{{ __('Созвон') }}</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="lms-lesson-duration">{{ __('Длительность, мин') }}</label>
                                <input id="lms-lesson-duration" name="duration_min" type="number" class="form-control" min="0" max="600" value="15">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                        <button class="btn btn-primary" type="submit">{{ __('Добавить урок') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal modal-blur fade" id="lmsMeetingModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <form method="post" action="{{ route('lms.meetings.store') }}">
                    @csrf
                    <input type="hidden" name="course_id" value="{{ $course->id }}">
                    <div class="modal-header">
                        <h5 class="modal-title mb-0"><i class="ti ti-video me-2"></i>{{ __('Новый видеозвонок') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Закрыть') }}"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label" for="lms-meeting-title">{{ __('Тема') }}</label>
                        <input id="lms-meeting-title" name="title" class="form-control" required maxlength="180" value="{{ __('Разбор курса') }}: {{ $course->title }}">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                        <button class="btn btn-primary" type="submit"><i class="ti ti-video me-1"></i>{{ __('Создать комнату') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
@endsection
