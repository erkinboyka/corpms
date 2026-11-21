@php
    $author = $message->author;
    $authorName = trim(($author?->first_name ?? '').' '.($author?->last_name ?? ''));
    $isMine = $author !== null && $author->id === $me->id;
    $isSystem = $message->kind->value === 'system';
    $reactions = $message->reactions->values();

    // Текстовое тело экранируется, разметка проходит whitelist-санитайзер.
    $bodyHtml = \App\Support\HtmlSanitizer::looksLikeHtml($message->body)
        ? \App\Support\HtmlSanitizer::sanitize($message->body)
        : e($message->body);
@endphp

@if ($isSystem)
    <div class="bm-chat__system" data-message-id="{{ $message->id }}">
        <span>{{ $message->body }}</span>
    </div>
@else
    <article class="bm-chat__msg {{ $isMine ? 'is-mine' : '' }}"
             data-message-id="{{ $message->id }}"
             data-client-id="{{ $message->client_id }}"
             @if ($message->edited_at) data-edited="1" @endif>
        <span class="bm-chat__avatar bm-chat__avatar--xs" aria-hidden="true">
            {{ \App\Support\AvatarInitials::of($authorName) }}
        </span>

        <div class="bm-chat__msg-main">
            <header class="bm-chat__msg-meta">
                <span class="bm-chat__msg-author">{{ $authorName !== '' ? $authorName : __('Сотрудник') }}</span>
                <time class="bm-chat__msg-time" datetime="{{ $message->created_at->toIso8601String() }}">
                    {{ $message->created_at->format('H:i') }}
                </time>
                @if ($message->edited_at)
                    <span class="bm-chat__msg-flag" title="{{ __("Сообщение отредактировано") }}">{{ __('изменено') }}</span>
                @endif
            </header>

            @if ($message->replyTo && $message->replyTo->trashed() === false)
                <button type="button" class="bm-chat__msg-quote" data-reply-to="{{ $message->replyTo->id }}" title="{{ __("Перейти к исходному сообщению") }}">
                    <span class="bm-chat__msg-quote-author">
                        {{ trim(($message->replyTo->author?->first_name ?? '').' '.($message->replyTo->author?->last_name ?? '')) ?: __('Сообщение') }}
                    </span>
                    <span class="bm-chat__msg-quote-text">{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($message->replyTo->body ?? ''), 120) ?: __('Вложение') }}</span>
                </button>
            @endif

            <div class="bm-chat__msg-body">{!! $bodyHtml !!}</div>

            @if ($message->attachments->isNotEmpty())
                <ul class="bm-chat__msg-files">
                    @foreach ($message->attachments as $attachment)
                        <li class="bm-chat__file">
                            <i class="ti ti-file" aria-hidden="true"></i>
                            <span>{{ $attachment->name }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($reactions->isNotEmpty())
                <div class="bm-chat__msg-reactions">
                    @foreach ($reactions->groupBy('emoji') as $emoji => $group)
                        @php $mine = $group->contains('employee_id', $me->id); @endphp
                        <button type="button" class="bm-chat__reaction{{ $mine ? ' is-mine' : '' }}" data-emoji="{{ $emoji }}" data-toggle-reaction
                                title="{{ $mine ? __('Снять реакцию') : __('Поставить реакцию') }}">
                            <span>{{ $emoji }}</span>
                            <span class="bm-chat__reaction-count">{{ $group->count() }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="bm-chat__msg-tools" role="group" aria-label="{{ __("Действия с сообщением") }}">
            @if ($canSend)
                <button type="button" class="bm-chat__tool" data-chat-reply="{{ $message->id }}" title="{{ __("Ответить") }}" aria-label="{{ __("Ответить") }}">
                    <i class="ti ti-arrow-back-up"></i>
                </button>
            @endif
            <button type="button" class="bm-chat__tool" data-chat-react="{{ $message->id }}" title="{{ __("Реакция") }}" aria-label="{{ __("Реакция") }}">
                <i class="ti ti-mood-smile"></i>
            </button>
            @if ($isMine && ! $isSystem)
                <button type="button" class="bm-chat__tool" data-chat-edit="{{ $message->id }}" title="{{ __("Редактировать") }}" aria-label="{{ __("Редактировать") }}">
                    <i class="ti ti-pencil"></i>
                </button>
                <button type="button" class="bm-chat__tool bm-chat__tool--danger" data-chat-delete="{{ $message->id }}" title="{{ __("Удалить") }}" aria-label="{{ __("Удалить") }}">
                    <i class="ti ti-trash"></i>
                </button>
            @endif
        </div>
    </article>
@endif
