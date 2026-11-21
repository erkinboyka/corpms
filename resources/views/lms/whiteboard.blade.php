@extends('layouts.tabler')
@section('title', $whiteboard->title)
@section('content')
@php
    $backUrl = $whiteboard->lesson
        ? route('lms.lessons.show', $whiteboard->lesson)
        : ($whiteboard->course ? route('lms.courses.show', $whiteboard->course) : route('lms.index'));
@endphp
<div class="py-4 lms-page lms-board-page"
     data-lms-whiteboard
     data-id="{{ $whiteboard->id }}"
     data-my-id="{{ $myId }}"
     data-my-name="{{ $myName }}"
     data-my-color="{{ $myColor }}"
     data-poll-ms="{{ $pollMs }}"
     data-url-elements="{{ route('lms.whiteboards.elements', $whiteboard) }}"
     data-url-draw="{{ route('lms.whiteboards.draw', $whiteboard) }}"
     data-url-erase="{{ route('lms.whiteboards.erase', [$whiteboard, '__ID__']) }}"
     data-url-presence="{{ route('lms.whiteboards.presence', $whiteboard) }}"
     data-url-presence-list="{{ route('lms.whiteboards.presence', $whiteboard) }}">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <a href="{{ $backUrl }}" class="btn btn-sm btn-ghost-secondary"><i class="ti ti-arrow-left me-1"></i>{{ __('Назад') }}</a>
        <span class="text-secondary">{{ $whiteboard->course?->title ?? __('Общая доска') }}</span>
    </div>

    <x-page-header :eyebrow="__('Совместная доска')" :title="$whiteboard->title" :subtitle="__('Рисуйте вместе: штрихи и курсоры коллег синхронизируются автоматически')">
        <span class="lms-live-badge"><span></span>{{ __('live') }}</span>
        <span class="crm-chip crm-chip--cyan" id="lmsBoardPeers"><i class="ti ti-users me-1"></i>1</span>
    </x-page-header>

    <div class="crm-toolbar lms-section-toolbar">
        <div class="crm-switch" data-lms-section-switch role="group" aria-label="{{ __('Разделы доски') }}">
            <button type="button" class="is-active" data-section="board"><i class="ti ti-pencil"></i>{{ __('Доска') }}</button>
            <button type="button" data-section="meeting"><i class="ti ti-video"></i>{{ __('Видеозвонок') }}</button>
            <button type="button" data-section="assignments"><i class="ti ti-list-check"></i>{{ __('Задания') }}</button>
            <button type="button" data-section="chat"><i class="ti ti-message-circle"></i>{{ __('Чат') }}</button>
        </div>
        <div class="crm-toolbar__spacer"></div>
        <span class="crm-toolbar__count"><i class="ti ti-info-circle me-1"></i>{{ __('Видеозвонок открывается отдельным окном — доска остаётся доступной') }}</span>
    </div>

    <section data-lms-section="board">
        <div class="card lms-board-shell">
            <div class="lms-board-toolbar">
                <div class="btn-group" role="group" aria-label="{{ __('Инструменты') }}">
                    <button type="button" class="btn btn-sm btn-outline-primary is-active" data-tool="path" title="{{ __('Карандаш') }}"><i class="ti ti-pencil"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-tool="rect" title="{{ __('Прямоугольник') }}"><i class="ti ti-square"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-tool="ellipse" title="{{ __('Круг') }}"><i class="ti ti-circle"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-tool="text" title="{{ __('Текст') }}"><i class="ti ti-letter-t"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-tool="sticky" title="{{ __('Заметка') }}"><i class="ti ti-note"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-tool="eraser" title="{{ __('Ластик') }}"><i class="ti ti-eraser"></i></button>
                </div>
                <span class="lms-board-toolbar__sep"></span>
                <label class="lms-board-toolbar__color" title="{{ __('Цвет') }}">
                    <input type="color" id="lmsBoardColor" value="#206bc4">
                </label>
                <label class="lms-board-toolbar__width" title="{{ __('Толщина') }}">
                    <i class="ti ti-line-dashed"></i>
                    <input type="range" id="lmsBoardWidth" min="1" max="16" value="3">
                </label>
                <span class="lms-board-toolbar__sep"></span>
                <button type="button" class="btn btn-sm btn-ghost-secondary" id="lmsBoardUndo" title="{{ __('Отменить последний штрих') }}">
                    <i class="ti ti-arrow-back-up"></i><span class="d-none d-sm-inline ms-1">{{ __('Отменить') }}</span>
                </button>
                <span class="lms-board-toolbar__hint" id="lmsBoardHint">{{ __('Выберите инструмент и ведите курсором по доске') }}</span>
            </div>

            <div class="lms-canvas-wrap" id="lmsCanvasWrap">
                <canvas id="lmsBoardCanvas"></canvas>
                <div class="lms-presence-layer" id="lmsPresence" aria-hidden="true"></div>
                <div class="lms-text-editor" id="lmsTextEditor" hidden>
                    <textarea id="lmsTextInput" rows="3" placeholder="{{ __('Введите текст и нажмите Enter') }}"></textarea>
                    <div class="d-flex gap-1 mt-1">
                        <button type="button" class="btn btn-sm btn-primary" id="lmsTextSave">{{ __('Добавить') }}</button>
                        <button type="button" class="btn btn-sm btn-ghost-secondary" id="lmsTextCancel">{{ __('Отмена') }}</button>
                    </div>
                </div>
            </div>

            <div class="lms-board-footer">
                <span><i class="ti ti-device-desktop me-1"></i>{{ __('Доска сохраняет каждый штрих — коллеги увидят его без перезагрузки') }}</span>
                <span id="lmsBoardStatus"><i class="ti ti-circle-check text-green me-1"></i>{{ __('Синхронизация активна') }}</span>
            </div>
        </div>
    </section>

    <section data-lms-section="assignments" hidden>
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="card-title mb-0"><i class="ti ti-clipboard-list me-2"></i>{{ __('Задания курса') }}</h2>
                <a href="{{ route('lms.assignments.index', ['course_id' => $chatCourse?->id]) }}" class="btn btn-sm btn-outline-primary">{{ __('Все задания') }} <i class="ti ti-arrow-right ms-1"></i></a>
            </div>
            <div class="card-body">
                @include('lms.partials.assignment-board', ['board' => $assignmentBoard['board'], 'canManage' => $canManage])
            </div>
        </div>
    </section>

    <section data-lms-section="chat" hidden>
        @include('lms.partials.chat', ['chatCourse' => $chatCourse, 'myId' => $myId])
    </section>
</div>

@if ($meeting)
    <div class="lms-meet-window" data-lms-meeting-window hidden>
        <div class="lms-meet-window__head" data-lms-meeting-drag>
            <span class="d-flex align-items-center gap-2 fw-semibold">
                <i class="ti ti-video"></i>{{ $meeting->title }}
            </span>
            <span class="ms-auto d-flex gap-1">
                <button type="button" class="btn btn-sm btn-ghost-secondary" data-lms-meeting-minimize title="{{ __('Свернуть') }}"><i class="ti ti-minus"></i></button>
                <button type="button" class="btn btn-sm btn-ghost-secondary" data-lms-meeting-close title="{{ __('Закрыть') }}"><i class="ti ti-x"></i></button>
            </span>
        </div>
        <div class="lms-meet-window__body">
            @include('lms.partials.meeting-stage', [
                'meeting' => $meeting,
                'myId' => $myId,
                'myName' => $myName,
                'participants' => $meetingParticipants,
                'pollMs' => $pollMs,
                'iceServers' => $iceServers,
            ])
        </div>
    </div>
@else
    <div class="alert alert-info">{{ __('Для этого курса ещё нет комнаты видеозвонка') }}</div>
@endif

@include('lms.partials.submit-modal')
@endsection

@push('scripts')
<script>
    window.LMS_I18N = Object.assign(window.LMS_I18N || {}, {
        drawFailed: @json(__('Не удалось сохранить штрих')),
        eraseFailed: @json(__('Не удалось удалить элемент')),
        syncLost: @json(__('Синхронизация прервана — повторяем')),
        syncOk: @json(__('Синхронизация активна')),
        enterText: @json(__('Введите текст')),
        toolPath: @json(__('Карандаш')),
        toolRect: @json(__('Прямоугольник')),
        toolEllipse: @json(__('Круг')),
        toolText: @json(__('Текст')),
        toolSticky: @json(__('Заметка')),
        toolEraser: @json(__('Ластик')),
        boardHint: @json(__('Выберите инструмент и ведите курсором по доске')),
        you: @json(__('вы')),
        meetingMinimize: @json(__('Свернуть')),
        meetingRestore: @json(__('Развернуть'))
    });
</script>
@endpush
