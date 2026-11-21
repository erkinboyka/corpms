@extends('layouts.tabler')
@section('title', $meeting->title)
@section('content')
@php
    $backUrl = $meeting->lesson
        ? route('lms.lessons.show', $meeting->lesson)
        : ($meeting->course ? route('lms.courses.show', $meeting->course) : route('lms.index'));
@endphp
<div class="container-xl py-4 lms-page lms-meeting-page"
     data-lms-meeting
     data-id="{{ $meeting->id }}"
     data-my-id="{{ $myId }}"
     data-my-name="{{ $myName }}"
     data-poll-ms="{{ $pollMs }}"
     data-ice="{{ json_encode($iceServers) }}"
     data-url-join="{{ route('lms.meetings.join', $meeting) }}"
     data-url-leave="{{ route('lms.meetings.leave', $meeting) }}"
     data-url-signals="{{ route('lms.meetings.signals', $meeting) }}"
     data-url-signal="{{ route('lms.meetings.signal', $meeting) }}"
     data-url-participants="{{ route('lms.meetings.participants', $meeting) }}">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <a href="{{ $backUrl }}" class="btn btn-sm btn-ghost-secondary"><i class="ti ti-arrow-left me-1"></i>{{ __('Назад') }}</a>
        <span class="text-secondary">{{ $meeting->course?->title ?? __('Видеозвонок') }}</span>
    </div>

    <x-page-header :eyebrow="__('Комната')" :title="$meeting->title" :subtitle="__('Прямой эфир без внешнего сервиса — сигнализация через защищённый polling CRM')">
        <span class="crm-chip crm-chip--{{ $meeting->status?->value === 'live' ? 'green' : 'cyan' }}" id="lmsMeetingBadge">
            {{ $crm_label('lms_meeting_status', $meeting->status?->value) }}
        </span>
        <button type="button" class="btn btn-primary" id="lmsMeetJoin"><i class="ti ti-video me-1"></i>{{ __('Подключиться') }}</button>
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card lms-meet-stage">
                <div class="lms-meet-stage__head d-flex align-items-center justify-content-between">
                    <span><i class="ti ti-video me-1"></i>{{ __('Видеопоток') }}</span>
                    <span class="lms-meet-timer" id="lmsMeetTimer">00:00</span>
                </div>
                <div class="lms-meet-grid" id="lmsMeetGrid">
                    <div class="lms-meet-tile is-local" id="lmsLocalTile">
                        <video id="lmsLocalVideo" autoplay playsinline muted></video>
                        <span class="lms-meet-tile__name">{{ $myName }} <small>({{ __('вы') }})</small></span>
                        <span class="lms-meet-tile__off" id="lmsLocalOff"><i class="ti ti-video-off"></i></span>
                    </div>
                    <div class="lms-meet-empty" id="lmsMeetEmpty">
                        <i class="ti ti-users"></i>
                        <div class="mt-2">{{ __('Подключитесь, чтобы увидеть участников') }}</div>
                        <small>{{ __('Камера и микрофон включаются по вашему решению') }}</small>
                    </div>
                </div>
                <div class="lms-meet-controls">
                    <button type="button" class="lms-meet-btn is-active" id="lmsMeetMic" title="{{ __('Микрофон') }}" disabled><i class="ti ti-microphone"></i></button>
                    <button type="button" class="lms-meet-btn is-active" id="lmsMeetCam" title="{{ __('Камера') }}" disabled><i class="ti ti-video"></i></button>
                    <button type="button" class="lms-meet-btn" id="lmsMeetShare" title="{{ __('Демонстрация экрана') }}" disabled><i class="ti ti-screen-share"></i></button>
                    <button type="button" class="lms-meet-btn is-danger" id="lmsMeetLeave" title="{{ __('Отключиться') }}" disabled><i class="ti ti-phone-off"></i></button>
                </div>
                <div class="lms-meet-status" id="lmsMeetStatus">
                    <i class="ti ti-info-circle me-1"></i>{{ __('Готов к подключению. Для сложных сетей можно указать TURN-сервер в настройках LMS.') }}
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card lms-side-card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="card-title mb-0"><i class="ti ti-users me-2"></i>{{ __('Участники') }}</h2>
                    <span class="crm-chip crm-chip--cyan" id="lmsParticipantCount">{{ count($participants) }}</span>
                </div>
                <div class="list-group list-group-flush" id="lmsParticipantList">
                    @forelse ($participants as $participant)
                        <div class="list-group-item d-flex align-items-center gap-2" data-employee="{{ $participant['employee_id'] }}">
                            <span class="lms-board__avatar">{{ mb_substr($participant['name'], 0, 2) }}</span>
                            <span class="flex-fill">{{ $participant['name'] }}</span>
                            <span class="crm-chip crm-chip--{{ $participant['role'] === 'host' ? 'orange' : 'cyan' }}">
                                {{ $participant['role'] === 'host' ? __('Ведущий') : __('Участник') }}
                            </span>
                        </div>
                    @empty
                        <div class="p-3">@include('partials.empty-state', ['icon' => 'users', 'title' => __('Никого нет'), 'text' => __('Вы можете стать первым')])</div>
                    @endforelse
                </div>
            </div>

            <div class="card lms-side-card">
                <div class="card-header">
                    <h2 class="card-title mb-0"><i class="ti ti-sparkles me-2"></i>{{ __('Как это работает') }}</h2>
                </div>
                <div class="card-body">
                    <ol class="lms-meet-help">
                        <li>{{ __('Сигнал offer/answer и ICE-кандидаты идут через базу CRM — Reverb не нужен.') }}</li>
                        <li>{{ __('Видео передаётся напрямую между браузерами через WebRTC.') }}</li>
                        <li>{{ __('STUN подключается автоматически; TURN можно добавить в .env для сложных сетей.') }}</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    window.LMS_I18N = Object.assign(window.LMS_I18N || {}, {
        meetConnecting: @json(__('Подключаем камеру и микрофон…')),
        meetConnected: @json(__('Вы в комнате')),
        meetNoMedia: @json(__('Камера и микрофон недоступны — звонок продолжится в режиме слушателя')),
        meetPeer: @json(__('Участник')),
        meetYou: @json(__('вы')),
        meetLeft: @json(__('Вы отключились')),
        meetError: @json(__('Не удалось подключиться')),
        meetScreenOn: @json(__('Демонстрация экрана включена')),
        meetScreenOff: @json(__('Демонстрация экрана остановлена')),
        micOn: @json(__('Микрофон включён')),
        micOff: @json(__('Микрофон выключен')),
        camOn: @json(__('Камера включена')),
        camOff: @json(__('Камера выключена')),
        host: @json(__('Ведущий')),
        participant: @json(__('Участник')),
        meetLive: @json(__('идёт')),
        meetEnded: @json(__('завершён'))
    });
</script>
@endpush
