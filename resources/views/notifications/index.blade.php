@extends('layouts.tabler')

@section('title', 'Уведомления')

@php
    $unreadChip = $unread > 0 ? 'crm-chip--red' : 'crm-chip--grey';
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Посещаемость · оповещения</div>
            <h2 class="page-title mt-1">Уведомления</h2>
            <div class="text-secondary mt-1">Системные сообщения о событиях табеля и задач</div>
        </div>
        <div class="col-auto d-print-none">
            <span class="crm-chip {{ $unreadChip }}"><i class="ti ti-mail me-1"></i>{{ $unread > 0 ? 'непрочитанных: ' . $unread : 'все прочитаны' }}</span>
        </div>
    </div>
</div>

<div class="card crm-panel">
    @if ($items->isEmpty())
        @include('partials.empty-state', [
            'icon' => 'bell-off',
            'title' => 'Уведомлений нет',
            'text' => 'Здесь появятся сообщения об опозданиях, пропусках и изменениях графика.',
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
                                <span class="crm-feed__dot" aria-label="Не прочитано"></span>
                                <span class="crm-feed__unread-label">не прочитано</span>
                            @else
                                <span class="crm-feed__read"><i class="ti ti-check me-1"></i>прочитано</span>
                            @endif
                        </div>
                    </div>
                    @if ($isUnread)
                        <form method="post" action="{{ route('notifications.read', $notification) }}" class="crm-feed__action">
                            @csrf @method('PUT')
                            <button class="btn btn-sm btn-outline-secondary"><i class="ti ti-check me-1"></i>Отметить прочитанным</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
