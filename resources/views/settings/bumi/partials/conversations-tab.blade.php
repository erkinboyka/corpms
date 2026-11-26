@php
    $base = route('bumi.settings.agent', [$agent, 'tab' => 'conversations']);
    $countByType = $messages->countBy(static fn ($message) => $message->channel?->type->value ?? 'unknown');
@endphp

<div class="card mb-3">
    <div class="card-body py-3">
        <form method="get" action="{{ $base }}" class="d-flex flex-wrap align-items-end justify-content-between gap-3" id="bumi-conversation-filters">
            <input type="hidden" name="tab" value="conversations">
            <div class="flex-grow-1">
                <div class="small text-secondary mb-2">{{ __('Канал') }}</div>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-sm {{ $channelFilter === '' ? 'btn-primary' : 'btn-ghost-secondary' }}"
                            type="submit" name="channel" value="">
                        <i class="ti ti-layout-grid me-1"></i>{{ __('Все') }}
                        <span class="badge ms-1">{{ $messages->count() }}</span>
                    </button>
                    @foreach ($channels as $channel)
                        <button class="btn btn-sm {{ $channelFilter === $channel->type->value ? 'btn-primary' : 'btn-ghost-secondary' }}"
                                type="submit" name="channel" value="{{ $channel->type->value }}">
                            <i class="ti ti-{{ $channel->type->icon() }} me-1"></i>{{ $channel->type->label() }}
                            <span class="badge ms-1">{{ $countByType->get($channel->type->value, 0) }}</span>
                        </button>
                    @endforeach
                    <input type="hidden" name="view" value="{{ $viewMode }}">
                </div>
            </div>
            <div>
                <div class="small text-secondary mb-2">{{ __('Отображение') }}</div>
                <div class="crm-switch" data-crm-switch="bumi-conversations" role="group" aria-label="{{ __('Вид диалогов') }}">
                    <button type="submit" data-view="table" name="view" value="table" class="{{ $viewMode === 'table' ? 'is-active' : '' }}">
                        <i class="ti ti-list me-1"></i>{{ __('Таблица') }}
                    </button>
                    <button type="submit" data-view="cards" name="view" value="cards" class="{{ $viewMode === 'cards' ? 'is-active' : '' }}">
                        <i class="ti ti-layout-grid me-1"></i>{{ __('Карточки') }}
                    </button>
                </div>
            </div>
        </form>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3 border-top pt-3">
            <input type="search" class="form-control crm-search-input" style="max-width: 360px;"
                   placeholder="{{ __('Поиск по клиенту или тексту') }}"
                   data-crm-filter="[data-bumi-message]"
                   data-crm-filter-count="#bumi-conversations-count">
            <span class="crm-toolbar__count small text-secondary" id="bumi-conversations-count">
                {{ __('Показано') }} {{ $messages->count() }}
            </span>
        </div>
    </div>
</div>

@if ($viewMode === 'cards')
    <div class="row row-cards g-3" data-crm-view-container="bumi-conversations" data-view-default="cards">
        @forelse ($messages as $message)
            <div class="col-md-6 col-xl-4 bumi-card" data-bumi-message data-bumi-message-id="{{ $message->id }}">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between gap-2">
                        <span class="d-flex align-items-center gap-2">
                            @if ($message->channel)
                                <i class="ti ti-{{ $message->channel->type->icon() }}"></i>{{ $message->channel->type->label() }}
                            @else
                                {{ __('Канал удалён') }}
                            @endif
                        </span>
                        @include('settings.bumi.partials.message-status', ['message' => $message])
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between small text-secondary mb-2">
                            <span>{{ $message->isFromClient() ? __('Клиент') : 'Bumi' }} · {{ $message->contact?->displayLabel() ?? '—' }}</span>
                            <span>{{ $message->created_at?->format('d.m.Y H:i') }}</span>
                        </div>
                        <div class="text-break">@include('partials.rich-text', ['value' => \Illuminate\Support\Str::limit((string) $message->text, 320)])</div>
                    </div>
                    @if ($canManage && $message->isFromClient())
                        <div class="card-footer">
                            <form method="post" action="{{ route('bumi.settings.message.retry', $message) }}" data-bumi-retry>
                                @csrf
                                <button class="btn btn-sm btn-ghost-secondary"><i class="ti ti-rotate-clockwise me-1"></i>{{ __('Повторить автоответ') }}</button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="col-12">@include('partials.empty-state', ['icon' => 'messages', 'title' => __('Диалогов пока нет'), 'text' => __('Подключите канал — первые обращения появятся здесь.')])</div>
        @endforelse
    </div>
@else
    <div class="card" data-crm-view-container="bumi-conversations" data-view-default="table">
        <div class="table-responsive">
            <table class="table table-vcenter card-table table-hover">
                <thead>
                    <tr>
                        <th>{{ __('Дата') }}</th>
                        <th>{{ __('Канал') }}</th>
                        <th>{{ __('Клиент') }}</th>
                        <th>{{ __('Кто написал') }}</th>
                        <th>{{ __('Сообщение') }}</th>
                        <th>{{ __('Статус') }}</th>
                        <th class="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($messages as $message)
                        <tr data-bumi-message data-bumi-message-id="{{ $message->id }}">
                            <td class="text-secondary small">{{ $message->created_at?->format('d.m.Y H:i') }}</td>
                            <td>
                                @if ($message->channel)
                                    <i class="ti ti-{{ $message->channel->type->icon() }} me-1"></i>{{ $message->channel->type->label() }}
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $message->contact?->displayLabel() ?? '—' }}</td>
                            <td>{{ $message->isFromClient() ? __('Клиент') : 'Bumi' }}</td>
                            <td class="text-break" style="max-width: 420px;">{{ \Illuminate\Support\Str::limit((string) $message->text, 240) }}</td>
                            <td>
                                @include('settings.bumi.partials.message-status', ['message' => $message])
                            </td>
                            <td>
                                @if ($canManage && $message->isFromClient())
                                    <form method="post" action="{{ route('bumi.settings.message.retry', $message) }}" data-bumi-retry>
                                        @csrf
                                        <button class="btn btn-sm btn-ghost-secondary" title="{{ __('Повторить автоответ') }}"><i class="ti ti-rotate-clockwise"></i></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">@include('partials.empty-state', ['icon' => 'messages', 'title' => __('Диалогов пока нет'), 'text' => __('Подключите канал — первые обращения появятся здесь.')])</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
