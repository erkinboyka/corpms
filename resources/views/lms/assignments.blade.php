@extends('layouts.tabler')
@section('title', __('Практика'))
@section('content')
<div class="container-xl py-4 lms-page">
    <x-page-header :eyebrow="__('Обучение')" :title="__('Доска практики')" :subtitle="__('Черновики, проверка и обратная связь — весь поток задач в одном месте')">
        @if ($canManage)
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#lmsAssignmentModal">
                <i class="ti ti-plus me-1"></i>{{ __('Новое задание') }}
            </button>
        @endif
        <a href="{{ route('lms.pulse') }}" class="btn btn-outline-secondary"><i class="ti ti-pulse me-1"></i>{{ __('Пульс') }}</a>
    </x-page-header>

    <div class="crm-toolbar mb-3">
        <form method="get" action="{{ route('lms.assignments.index') }}" class="d-flex align-items-end gap-2 flex-wrap">
            <div>
                <label class="form-label mb-1" for="lmsAssignmentCourse">{{ __('Курс') }}</label>
                <select id="lmsAssignmentCourse" name="course_id" class="form-select">
                    <option value="0">{{ __('Все курсы') }}</option>
                    @foreach ($courses as $course)
                        <option value="{{ $course->id }}" @selected($courseId === $course->id)>{{ $course->title }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-outline-primary"><i class="ti ti-filter me-1"></i>{{ __('Показать') }}</button>
        </form>
        <div class="crm-toolbar__spacer"></div>
        <div class="crm-toolbar__count d-flex gap-3 flex-wrap">
            <span><i class="ti ti-files me-1"></i>{{ count($board['draft']) }} {{ __('черновиков') }}</span>
            <span><i class="ti ti-send me-1"></i>{{ count($board['submitted']) }} {{ __('на проверке') }}</span>
            <span><i class="ti ti-circle-check me-1"></i>{{ count($board['reviewed']) }} {{ __('проверено') }}</span>
        </div>
    </div>

    @include('lms.partials.assignment-board', ['board' => $board, 'canReview' => $canManage || ($canTeach ?? false)])
</div>

@if ($canManage || ($canTeach ?? false))
    <div class="modal modal-blur fade" id="lmsAssignmentModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <form method="post" action="{{ route('lms.assignments.store') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title mb-0"><i class="ti ti-clipboard-list me-2"></i>{{ __('Новое практическое задание') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Закрыть') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="lms-assignment-title">{{ __('Название') }}</label>
                            <input id="lms-assignment-title" name="title" class="form-control" required maxlength="180">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="lms-assignment-course">{{ __('Курс') }}</label>
                            <select id="lms-assignment-course" name="course_id" class="form-select" required>
                                @foreach ($courses as $course)
                                    <option value="{{ $course->id }}" @selected($courseId === $course->id)>{{ $course->title }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="lms-assignment-desc">{{ __('Описание') }}</label>
                            <textarea data-crm-rich id="lms-assignment-desc" name="description" class="form-control" rows="4"></textarea>
                        </div>
                        @include('partials.entity-photo-field', ['id' => 'lms-assignment-photo', 'photoPath' => null])
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label" for="lms-assignment-due">{{ __('Дедлайн') }}</label>
                                <input id="lms-assignment-due" name="due_at" type="datetime-local" class="form-control">
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="lms-assignment-score">{{ __('Максимальный балл') }}</label>
                                <input id="lms-assignment-score" name="max_score" type="number" class="form-control" min="1" max="1000" value="100">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                        <button class="btn btn-primary" type="submit"><i class="ti ti-plus me-1"></i>{{ __('Создать задание') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif

@include('lms.partials.submit-modal')
@endsection
