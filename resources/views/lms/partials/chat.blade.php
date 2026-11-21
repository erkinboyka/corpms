@php
    $chatCourse = $chatCourse ?? null;
    $pollMs = $chatPollMs ?? 3000;
@endphp
<div class="card lms-chat-card"
     @if ($chatCourse)
         data-lms-chat
         data-url-list="{{ route('lms.courses.messages', $chatCourse) }}"
         data-url-send="{{ route('lms.courses.messages.store', $chatCourse) }}"
         data-poll-ms="{{ $pollMs }}"
         data-my-id="{{ $myId }}"
     @endif
>
    <div class="card-header d-flex align-items-center justify-content-between">
        <h2 class="card-title mb-0"><i class="ti ti-message-circle me-2"></i>{{ __('Чат курса') }}</h2>
        <span class="lms-live-badge"><span></span>{{ __('онлайн') }}</span>
    </div>
    @if ($chatCourse)
        <div class="lms-chat-messages" data-lms-chat-list>
            <div class="text-secondary small p-3">{{ __('Загружаем сообщения…') }}</div>
        </div>
        <form class="lms-chat-form" data-lms-chat-form>
            @csrf
            <input type="text" name="body" class="form-control" maxlength="2000" required
                   placeholder="{{ __('Написать сообщение…') }}" autocomplete="off">
            <button type="submit" class="btn btn-primary" title="{{ __('Отправить') }}"><i class="ti ti-send"></i></button>
        </form>
    @else
        <div class="card-body text-secondary small">{{ __('Чат доступен на странице курса') }}</div>
    @endif
</div>

@push('scripts')
<script>
    window.LMS_I18N = Object.assign(window.LMS_I18N || {}, {
        chatEmpty: @json(__('Пока нет сообщений. Напишите первым')),
        chatFailed: @json(__('Не удалось отправить сообщение')),
        chatYou: @json(__('вы'))
    });
</script>
@endpush
