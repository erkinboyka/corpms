@extends('layouts.tabler')
@section('workspace_layout', 'conversation')
@section('body_class', 'bm-chat-page')

@section('title', __('Чат'))

@section('content')
    @php
        $me = auth()->user()->employee;
        $activeId = $active?->id;
        // Гейт проекта: @can('checkBoxPermission', 'slug.permission') —
        // та же матрица, что у middleware permission:.
        $canSend = auth()->user()->can('checkBoxPermission', 'chat.send');
        $canCreateChannels = auth()->user()->can('checkBoxPermission', 'chat.channels.create');
    @endphp

    <div class="bm-chat"
         id="bm-chat"
         data-active-id="{{ $activeId ?? '' }}"
         data-last-event-id="{{ (int) $lastEventId }}"
         data-route-messages-base="/chat"
         data-route-store="{{ route('chat.store') }}"
         data-route-unread="{{ route('chat.unread') }}"
         data-my-employee-id="{{ $me->id }}">

        <aside class="bm-chat__list" aria-label="{{ __("Диалоги") }}">
            <div class="bm-chat__list-head">
                <h1>{{ __('Чаты') }}</h1>
                <button type="button" class="bm-chat__new" data-chat-new data-bs-toggle="modal"
                        data-bs-target="#chat-new-dialog" aria-label="{{ __("Новый диалог") }}" title="{{ __("Новый диалог") }}">
                    <i class="ti ti-plus" aria-hidden="true"></i><span>{{ __('Новый') }}</span>
                </button>
            </div>
            <div class="bm-chat__search">
                <i class="ti ti-search" aria-hidden="true"></i>
                <input type="search" id="chat-list-filter" class="form-control" placeholder="{{ __("Поиск диалога") }}" aria-label="{{ __("Поиск диалога") }}">
            </div>

            <div class="bm-chat__tabs" role="tablist" aria-label="{{ __("Тип диалога") }}">
                <button type="button" class="bm-chat__tab is-active" role="tab" aria-selected="true" data-chat-filter="all">{{ __('Все') }}</button>
                <button type="button" class="bm-chat__tab" role="tab" aria-selected="false" data-chat-filter="direct">{{ __('Личные') }}</button>
                <button type="button" class="bm-chat__tab" role="tab" aria-selected="false" data-chat-filter="group">{{ __('Группы') }}</button>
                <button type="button" class="bm-chat__tab" role="tab" aria-selected="false" data-chat-filter="channel">{{ __('Каналы') }}</button>
            </div>

            <div class="bm-chat__items" id="chat-list">
                @forelse ($conversations as $conversation)
                    @php
                        $unread = (int) ($conversation->unread_count ?? 0);
                        $title = $conversation->titleFor($me->id);
                    @endphp
                    <a class="bm-chat__item {{ $activeId === $conversation->id ? 'is-active' : '' }}"
                       href="{{ route('chat.show', $conversation) }}"
                       @if ($activeId === $conversation->id) aria-current="page" @endif
                       data-chat-item
                       data-id="{{ $conversation->id }}"
                       data-type="{{ $conversation->type->value }}"
                       data-title="{{ $title }}">
                        <span class="bm-chat__avatar" aria-hidden="true">
                            @if ($conversation->avatar_path)
                                <img src="{{ route('chat.image', [$conversation, 'avatar']) }}" alt="" loading="lazy">
                            @else
                                {{ \App\Support\AvatarInitials::of($title) }}
                            @endif

                        </span>
                        <span class="bm-chat__item-main">
                            <span class="bm-chat__item-title">
                                {{ $title }}

                                @if ($conversation->isChannel())
                                    <i class="ti ti-hash" aria-hidden="true"></i>
                                @endif
                            </span>
                            <span class="bm-chat__item-sub">
                                {{ $conversation->type->label() }}

                                @if ($conversation->last_message_at)
                                    · {{ $conversation->last_message_at->diffForHumans(short: true) }}

                                @endif
                            </span>
                        </span>
                        @if ($unread > 0)
                            <span class="bm-chat__badge" data-chat-badge="{{ $conversation->id }}">{{ $unread > 99 ? '99+' : $unread }}</span>
                        @endif
                    </a>
                @empty
                    <div class="bm-chat__empty">
                        <i class="ti ti-message-off" aria-hidden="true"></i>
                        <p>{{ __('Диалогов пока нет') }}</p>
                        <button type="button" class="btn btn-sm btn-primary" data-chat-new data-bs-toggle="modal" data-bs-target="#chat-new-dialog">{{ __('Создать') }}</button>
                    </div>
                @endforelse
                <p class="bm-chat__filter-empty" id="chat-filter-empty" role="status" hidden>{{ __('Диалоги не найдены') }}</p>
            </div>
        </aside>

        <section class="bm-chat__thread" aria-label="{{ __("Переписка") }}">
            @if ($active === null)
                <div class="bm-chat__placeholder">
                    <i class="ti ti-messages" aria-hidden="true"></i>
                    <p class="bm-chat__placeholder-title">{{ __('Выберите диалог') }}</p>
                    <p class="bm-chat__placeholder-text">{{ __('Личные сообщения, группы и каналы компании') }}</p>
                </div>
            @else
                <header class="bm-chat__header">
                    <div class="bm-chat__header-main">
                        <a href="{{ route('chat.index') }}" class="bm-chat__back" aria-label="{{ __("Все диалоги") }}"><i class="ti ti-arrow-left" aria-hidden="true"></i></a>
                        <span class="bm-chat__avatar bm-chat__avatar--sm" aria-hidden="true">
                            @if ($active->avatar_path)
                                <img src="{{ route('chat.image', [$active, 'avatar']) }}" alt="">
                            @else
                                {{ \App\Support\AvatarInitials::of($active->titleFor($me->id)) }}
                            @endif

                        </span>
                        <div>
                            <div class="bm-chat__header-title">{{ $active->titleFor($me->id) }}</div>
                            <div class="bm-chat__header-sub" id="chat-thread-sub">
                                {{ $active->type->label() }}

                                · {{ $members->count() }} {{ __('уч') }}.
                            </div>
                        </div>
                    </div>
                    <div class="bm-chat__header-actions">
                        <button type="button" class="btn btn-sm btn-ghost-secondary bm-chat__header-action" data-bs-toggle="modal" data-bs-target="#chat-settings-dialog"
                                aria-label="{{ __("Настройки диалога") }}" title="{{ __("Настройки диалога") }}">
                            <i class="ti ti-settings" aria-hidden="true"></i>
                            <span class="bm-chat__header-action-label">{{ __('Настройки') }}</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-ghost-secondary" id="chat-latest" title="{{ __("К последнему сообщению") }}" aria-label="{{ __("К последнему сообщению") }}" hidden><i class="ti ti-arrow-down" aria-hidden="true"></i></button>
                        <button type="button" class="btn btn-sm btn-ghost-secondary" id="chat-context-toggle" aria-expanded="false" aria-controls="chat-context" title="{{ __("О диалоге") }}">
                            <i class="ti ti-info-circle"></i><span class="visually-hidden">{{ __('Информация о диалоге') }}</span>
                        </button>
                    </div>
                </header>

                <div @class(['bm-chat__messages', 'bm-chat__messages--wallpaper' => $hasWallpaper]) id="chat-messages" tabindex="0" aria-label="{{ __("Сообщения диалога") }}" role="log" aria-live="polite" aria-busy="false"
                     @if ($hasWallpaper) style="--chat-wallpaper: url('{{ route('chat.image', [$active, 'wallpaper']) }}')" @endif
                     data-conversation-id="{{ $active->id }}"
                     data-react-add="{{ __('Поставить реакцию') }}"
                     data-react-remove="{{ __('Снять реакцию') }}">
                    @forelse ($messages as $message)
                        @include('chat.partials.message', ['message' => $message, 'me' => $me])
                    @empty
                        <div class="bm-chat__placeholder bm-chat__placeholder--inline">
                            <i class="ti ti-message-circle" aria-hidden="true"></i>
                            <p class="bm-chat__placeholder-title">{{ __('Сообщений пока нет') }}</p>
                            <p class="bm-chat__placeholder-text">{{ __('Напишите первым') }}</p>
                        </div>
                    @endforelse
                </div>

                <form class="bm-chat__composer" id="chat-composer" autocomplete="off"
                      data-url="{{ route('chat.send', $active) }}"
                      data-can-post="{{ $canSend ? '1' : '0' }}">
                    @csrf
                    <div class="bm-chat__reply-bar" id="chat-reply-bar" hidden>
                        <i class="ti ti-arrow-back-up" aria-hidden="true"></i>
                        <div class="bm-chat__reply-preview" id="chat-reply-preview" role="status">
                            <strong id="chat-reply-author"></strong>
                            <span id="chat-reply-text"></span>
                        </div>
                        <button type="button" class="btn btn-sm btn-ghost-secondary" id="chat-reply-cancel" aria-label="{{ __("Отменить ответ") }}">
                            <i class="ti ti-x"></i>
                        </button>
                    </div>
                    <div class="bm-chat__composer-row">
                        <textarea class="form-control bm-chat__input" id="chat-input" rows="1"
                                  placeholder="{{ $canSend ? __('Сообщение…') : __('Отправка сообщений недоступна') }}"
                                  {{ $canSend ? '' : 'disabled' }}

                                  maxlength="8000" aria-label="{{ __("Текст сообщения") }}"></textarea>
                        <button type="submit" class="btn btn-primary bm-chat__send" id="chat-send" disabled>
                            <i class="ti ti-arrow-up"></i><span class="visually-hidden">{{ __('Отправить') }}</span>
                        </button>
                    </div>
                </form>
            @endif
        </section>

        <aside class="bm-chat__context" id="chat-context" aria-label="{{ __("Информация о диалоге") }}">
            @if ($active !== null)
                <div class="bm-chat__context-head">
                    <span class="crm-chip">{{ $active->visibility->label() }}</span>
                    @if ($active->description)
                        <p class="bm-chat__context-desc">{{ $active->description }}</p>
                    @endif
                </div>

                <div class="bm-chat__context-block">
                    <div class="d-flex align-items-center justify-content-between gap-2">
                        <h3 class="bm-chat__context-title mb-0">{{ __('Участники') }}</h3>
                        @if ($canManageChat && ! $active->isCompanyWide())
                            <button type="button" class="btn btn-sm btn-ghost-secondary" data-bs-toggle="modal" data-bs-target="#chat-members-dialog">{{ __('Добавить') }}</button>
                        @endif
                    </div>
                    <div class="bm-chat__members" id="chat-members">
                        @foreach ($members as $member)
                            <div class="bm-chat__member">
                                <span class="bm-chat__avatar bm-chat__avatar--xs" aria-hidden="true">
                                    {{ \App\Support\AvatarInitials::of(trim(($member->employee?->first_name ?? '').' '.($member->employee?->last_name ?? ''))) }}

                                </span>
                                <span class="bm-chat__member-name">
                                    {{ trim(($member->employee?->first_name ?? '').' '.($member->employee?->last_name ?? '')) }}

                                </span>
                                @if ($member->role->isAdmin())
                                    <span class="crm-chip crm-chip--grey">{{ $member->role->label() }}</span>
                                @endif
                                @if ($canManageChat && ! $active->isCompanyWide() && $member->employee_id !== $me->id
                                    && $member->role !== \App\Shared\Enums\ConversationRole::Owner && ($chatOwner || ! $member->role->isAdmin()))
                                    <form method="post" action="{{ route('chat.members.destroy', [$active, $member->employee_id]) }}"
                                          data-chat-confirm="{{ __("Исключить сотрудника из диалога?") }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="bm-chat__tool bm-chat__tool--danger" title="{{ __("Исключить участника") }}" aria-label="{{ __('Исключить') }} {{ $member->employee?->first_name }} {{ $member->employee?->last_name }}">
                                            <i class="ti ti-user-minus" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

            @endif
        </aside>
    </div>

    @if ($active)
        @include('chat.partials.settings')
    @endif

    <div class="modal modal-blur fade" id="chat-new-dialog" tabindex="-1" aria-labelledby="chat-new-title" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" method="post" action="{{ route('chat.store') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="chat-new-title">{{ __('Новый диалог') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __("Закрыть") }}"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="chat-new-type">{{ __('Тип') }}</label>
                        <select class="form-select" name="type" id="chat-new-type">
                            <option value="direct">{{ __('Личный диалог') }}</option>
                            <option value="group">{{ __('Группа') }}</option>
                            @if ($canCreateChannels)
                                <option value="channel">{{ __('Канал компании') }}</option>
                            @endif
                        </select>
                    </div>

                    <div class="mb-3" id="chat-new-employee">
                         <label class="form-label" for="chat-new-employee-select">{{ __('Сотрудник') }}</label>
                         <select class="form-select" id="chat-new-employee-select" name="employee_id" data-chat-employee-select="{{ route('chat.employees') }}"
                                 data-placeholder="{{ __("Введите имя или почту") }}">
                             <option></option>
                         </select>
                    </div>

                    <div class="mb-3" id="chat-new-title-field" hidden>
                        <label class="form-label" for="chat-new-title-input">{{ __('Название') }}</label>
                        <input type="text" class="form-control" name="title" id="chat-new-title-input" maxlength="120">
                    </div>

                    <div class="mb-3" id="chat-new-visibility" hidden>
                        <label class="form-label">{{ __('Видимость канала') }}</label>
                        <select class="form-select" name="visibility">
                            <option value="company">{{ __('Вся компания') }}</option>
                            <option value="private">{{ __('Только приглашённые') }}</option>
                        </select>
                    </div>

                    <div class="mb-3" id="chat-new-post-permission" hidden>
                        <label class="form-label">{{ __('Кто может писать') }}</label>
                        <select class="form-select" name="post_permission">
                            <option value="all">{{ __('Все участники') }}</option>
                            <option value="admins">{{ __('Только администраторы') }}</option>
                        </select>
                    </div>

                    <div class="mb-3" id="chat-new-members-block" hidden>
                         <label class="form-label" for="chat-new-members">{{ __('Участники') }}</label>
                         <select class="form-select" id="chat-new-members" name="member_ids[]" multiple data-chat-employee-select="{{ route('chat.employees') }}"
                                 data-placeholder="{{ __("Найдите и выберите сотрудников") }}" aria-describedby="chat-new-members-hint"></select>
                        <div class="form-hint" id="chat-new-members-hint">{{ __('Поиск добавляет участников в группу или закрытый канал') }}</div>
                    </div>

                    <p class="small text-danger mb-0" id="chat-new-error" role="alert" hidden></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('Создать') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="/assets/css/bm-chat.css?v9">
@endpush

@push('scripts')
    <script src="/assets/js/bm-chat.js?v8" defer></script>
@endpush
