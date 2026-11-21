<div class="modal modal-blur fade" id="chat-settings-dialog" tabindex="-1" aria-labelledby="chat-settings-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content bm-chat-settings">
            <div class="modal-header bm-chat-settings__header">
                <span class="bm-chat-settings__icon" aria-hidden="true"><i class="ti ti-adjustments-horizontal"></i></span>
                <div class="min-w-0 flex-grow-1">
                    <h2 class="modal-title" id="chat-settings-title">{{ __('Настройки диалога') }}</h2>
                    <p class="bm-chat-settings__subtitle">{{ $active->titleFor($me->id) }} · {{ $active->type->label() }}</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __("Закрыть") }}"></button>
            </div>
            <div class="modal-body bm-chat-settings__body">
                @if ($canManageChat)
                    <form method="post" action="{{ route('chat.conversations.update', $active) }}" enctype="multipart/form-data" class="bm-chat-settings__card">
                        @csrf
                        @method('PATCH')
                        <div class="bm-chat-settings__section-head">
                            <span class="bm-chat-settings__icon" aria-hidden="true"><i class="ti ti-id"></i></span>
                            <div>
                                <h3>{{ __('Название и фото') }}</h3>
                                <p>{{ __('Изменения увидят все участники') }}</p>
                            </div>
                        </div>
                        <label class="form-label mb-0" for="chat-settings-name">{{ __('Название') }}</label>
                        <input id="chat-settings-name" name="title" class="form-control" value="{{ $active->title }}" maxlength="120" required>
                        <label class="form-label mb-0" for="chat-settings-description">{{ __('Описание') }}</label>
                        <textarea data-crm-rich id="chat-settings-description" name="description" class="form-control" rows="2" maxlength="500">{{ $active->description }}</textarea>
                        <div class="bm-chat-settings__upload">
                            <span class="bm-chat-settings__avatar" aria-hidden="true">
                                {{ \App\Support\AvatarInitials::of($active->titleFor($me->id)) }}
                                <img id="chat-settings-avatar-image" alt=""
                                     @if ($active->avatar_path) src="{{ route('chat.image', [$active, 'avatar']) }}" @else hidden @endif>
                            </span>
                            <div class="min-w-0">
                                <label class="form-label mb-1" for="chat-settings-avatar">{{ __('Фото диалога') }}</label>
                                <input id="chat-settings-avatar" type="file" name="avatar" class="form-control" accept="image/jpeg,image/png,image/webp"
                                       data-chat-image-preview="chat-settings-avatar-image">
                            </div>
                        </div>
                        @if ($active->avatar_path)
                            <label class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="reset_avatar" value="1">
                                <span class="form-check-label">{{ __('Убрать текущее фото') }}</span>
                            </label>
                        @endif
                        <div class="bm-chat-settings__actions"><button class="btn btn-primary" type="submit">{{ __('Сохранить настройки') }}</button></div>
                    </form>
                @endif

                <form method="post" action="{{ route('chat.wallpaper.update', $active) }}" enctype="multipart/form-data" class="bm-chat-settings__card">
                    @csrf
                    @method('PATCH')
                    <div class="bm-chat-settings__section-head">
                        <span class="bm-chat-settings__icon" aria-hidden="true"><i class="ti ti-photo"></i></span>
                        <div>
                            <h3>{{ __('Обои чата') }}</h3>
                            <p>{{ __('Фон виден только вам') }}</p>
                        </div>
                    </div>
                    <div class="bm-chat-settings__preview" aria-label="{{ __("Пример оформления переписки") }}" role="img">
                        <img id="chat-settings-wallpaper-image" alt=""
                             @if ($hasWallpaper) src="{{ route('chat.image', [$active, 'wallpaper']) }}" @else hidden @endif>
                        <span class="bm-chat-settings__preview-label" aria-hidden="true">{{ __('Предпросмотр') }}</span>
                        <span class="bm-chat-settings__bubble" aria-hidden="true">{{ __('Привет!') }}</span>
                        <span class="bm-chat-settings__bubble bm-chat-settings__bubble--mine" aria-hidden="true">{{ __('На связи') }} <i class="ti ti-checks"></i></span>
                    </div>
                    <label class="form-label mb-0" for="chat-settings-wallpaper">{{ __('Изображение для фона') }}</label>
                    <input id="chat-settings-wallpaper" type="file" name="wallpaper" class="form-control" accept="image/jpeg,image/png,image/webp"
                           data-chat-image-preview="chat-settings-wallpaper-image">
                    <div class="bm-chat-settings__actions">
                        <button class="btn btn-primary" type="submit">{{ __('Установить обои') }}</button>
                        @if ($hasWallpaper)
                            <button class="btn btn-ghost-secondary" type="submit" name="reset_wallpaper" value="1">{{ __('Сбросить') }}</button>
                        @endif
                    </div>
                </form>

                @if ($canManageChat && ! $active->isCompanyWide())
                    <button type="button" class="bm-chat-settings__card bm-chat-settings__link" data-bs-toggle="modal" data-bs-target="#chat-members-dialog">
                        <span class="bm-chat-settings__icon" aria-hidden="true"><i class="ti ti-user-plus"></i></span>
                        <span class="flex-grow-1">{{ __('Добавить участников') }}</span>
                        <i class="ti ti-chevron-right" aria-hidden="true"></i>
                    </button>
                @endif

                @if ($active->isDirect() || $chatOwner)
                    <form class="bm-chat-settings__danger" method="post" action="{{ route('chat.conversations.destroy', $active) }}"
                          data-chat-confirm="{{ $active->isDirect() ? __('Убрать этот диалог из вашего списка?') : __('Удалить диалог и закрыть доступ к нему для всех участников?') }}">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-ghost-danger" type="submit">
                            <i class="ti {{ $active->isDirect() ? 'ti-eye-off' : 'ti-trash' }}" aria-hidden="true"></i>
                            {{ $active->isDirect() ? __('Скрыть диалог у себя') : __('Удалить диалог') }}
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>

@if ($canManageChat && ! $active->isCompanyWide())
    <div class="modal modal-blur fade" id="chat-members-dialog" tabindex="-1" aria-labelledby="chat-members-title" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" method="post" action="{{ route('chat.members.store', $active) }}">
                @csrf
                <div class="modal-header">
                    <h2 class="modal-title" id="chat-members-title">{{ __('Добавить участников') }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __("Закрыть") }}"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label" for="chat-invite-members">{{ __('Сотрудники') }}</label>
                    <select class="form-select" id="chat-invite-members" name="member_ids[]" multiple required
                            data-chat-employee-select="{{ route('chat.employees', ['conversation' => $active->id]) }}"
                            data-placeholder="{{ __("Найдите и выберите сотрудников") }}"></select>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" type="submit">{{ __('Добавить') }}</button>
                </div>
            </form>
        </div>
    </div>
@endif
