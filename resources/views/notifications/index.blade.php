@extends('layouts.tabler')
@section('workspace_layout', 'stream')

@section('title', __('Уведомления'))

@php
    $unreadChip = $unread > 0 ? 'crm-chip--red' : 'crm-chip--grey';
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Посещаемость · оповещения') }}</div>
            <h1 class="page-title mt-1">{{ __('Уведомления') }}</h1>
            <div class="text-secondary mt-1">{{ __('Системные сообщения о событиях табеля и задач') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <span class="crm-chip {{ $unreadChip }}"><i class="ti ti-mail me-1"></i>{{ $unread > 0 ? 'непрочитанных: ' . $unread : __('все прочитаны') }}</span>
        </div>
    </div>
</div>

<div class="card crm-panel">
    @if ($items->isEmpty())
        @include('partials.empty-state', [
            'icon' => 'bell-off',
            'title' => __('Уведомлений нет'),
            'text' => __('Здесь появятся сообщения об опозданиях, пропусках и изменениях графика.'),
        ])
    @else
        <div class="crm-feed">
            @foreach ($items as $notification)
                @php
                    $isUnread = $notification->read_at === null;
                    $isTelegram = $notification->channel === 'telegram';
                @endphp
                <div class="crm-feed__item {{ $isUnread ? 'crm-feed__item--unread' : '' }}">
                    <span class="crm-feed__icon {{ $isTelegram ? 'crm-feed__icon--tg' : '' }}">
                        <i class="ti ti-{{ $isTelegram ? 'send' : 'bell' }}"></i>
                    </span>
                    <div class="crm-feed__body">
                        <div class="crm-feed__head">
                            <span class="crm-feed__title">{{ $notification->title }}</span>
                            <span class="crm-feed__time">{{ $notification->created_at?->format('d.m H:i') }}</span>
                        </div>
                        <div class="crm-feed__text">{{ Str::limit($notification->body, 160) }}</div>
                        <div class="crm-feed__meta">
                            <span class="crm-chip {{ $isTelegram ? 'crm-chip--cyan' : 'crm-chip--grey' }}">{{ $crm_label('notification_channel', $notification->channel) }}</span>
                            @if ($isUnread)
                                <span class="crm-feed__dot" aria-label="{{ __("Не прочитано") }}"></span>
                                <span class="crm-feed__unread-label">{{ __('не прочитано') }}</span>
                            @else
                                <span class="crm-feed__read"><i class="ti ti-check me-1"></i>{{ __('прочитано') }}</span>
                            @endif
                        </div>
                    </div>
                    @if ($isUnread)
                        <form method="post" action="{{ route('notifications.read', $notification) }}" class="crm-feed__action">
                            @csrf @method('PUT')
                            <button class="btn btn-sm btn-outline-secondary"><i class="ti ti-check me-1"></i>{{ __('Отметить прочитанным') }}</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
