@php
    $selected = $selectedChannel ?? '';
    $hints = [
        'website' => __('Готовый чат: клиент пишет без входа, ответы видны в диалогах.'),
        'telegram' => __('BotFather → токен бота → webhook с secret token.'),
        'whatsapp' => __('Meta App: callback URL и Verify Token, подписка на messages.'),
        'instagram' => __('Instagram Graph: callback URL и Verify Token.'),
        'greenapi' => __('Green API: webhook URL и тот же токен в кабинете.'),
    ];
    $connected = $channels->filter(static fn ($channel) => $channel->enabled && $channel->isConfigured())->count();
@endphp

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <div class="font-weight-medium">{{ __('Подключено каналов') }}: <span data-bumi-counter="channels-connected">{{ $connected }}</span> / {{ $channels->count() }}</div>
            <div class="text-secondary small">{{ __('Выберите канал, затем нажмите «Настроить» — всё остальное скрыто до нужного момента.') }}</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @foreach ($channels as $item)
                <a class="btn btn-sm {{ $selected === $item->type->value ? 'btn-primary' : 'btn-ghost-secondary' }}"
                   href="{{ route('bumi.settings.agent', [$agent, 'tab' => 'channels', 'channel' => $item->type->value]) }}">
                    <i class="ti ti-{{ $item->type->icon() }} me-1"></i>{{ $item->type->label() }}
                    <span class="badge bg-green-lt ms-1" data-bumi-channel-badge="{{ $item->id }}" @if (! ($item->enabled && $item->isConfigured())) hidden @endif>OK</span>
                </a>
            @endforeach
        </div>
    </div>
</div>

<div class="row row-cards g-3">
    @foreach ($channels as $channel)
        @php
            $type = $channel->type->value;
            $open = $selected === $type;
        @endphp
        <div class="col-12">
            <div class="card bumi-card" data-bumi-card="channel-{{ $channel->id }}">
                <div class="card-body p-3">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <span class="avatar avatar-md"><i class="ti ti-{{ $channel->type->icon() }}"></i></span>
                            <div>
                                <div class="font-weight-medium">{{ $channel->type->label() }}</div>
                                <div class="text-secondary small">{{ $hints[$type] ?? '' }}</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="crm-chip {{ $channel->statusClass() }}" data-bumi-toggle-chip>{{ $channel->statusLabel() }}</span>
                            @if ($channel->type === \App\Shared\Enums\BumiChannelType::Website)
                                <a class="btn btn-sm btn-primary" href="{{ route('bumi.chat.show', $channel) }}" target="_blank" rel="noopener">
                                    <i class="ti ti-external-link me-1"></i>{{ __('Открыть чат') }}
                                </a>
                            @endif
                            <a class="btn btn-sm btn-ghost-secondary"
                               href="{{ route('bumi.settings.agent', [$agent, 'tab' => 'channels', 'channel' => $open ? '' : $type]) }}">
                                <i class="ti ti-{{ $open ? 'chevron-up' : 'settings' }} me-1"></i>{{ $open ? __('Свернуть') : __('Настроить') }}
                            </a>
                        </div>
                    </div>

                    @if (! $open && $channel->webhookUrl() !== '')
                        <div class="input-group input-group-sm mt-3" style="max-width: 720px;">
                            <span class="input-group-text">{{ __('Webhook URL') }}</span>
                            <input class="form-control" value="{{ $channel->webhookUrl() }}" readonly id="bumi-short-url-{{ $channel->id }}">
                            <button class="btn btn-ghost-secondary" type="button" data-bumi-copy="#bumi-short-url-{{ $channel->id }}"><i class="ti ti-copy"></i></button>
                        </div>
                    @endif

                    @if ($open)
                        <div class="border-top mt-3 pt-3">
                            @if ($channel->type === \App\Shared\Enums\BumiChannelType::Website)
                                <label class="form-label small mb-1" for="bumi-embed-{{ $channel->id }}">{{ __('Код для сайта') }}</label>
                                <textarea class="form-control form-control-sm mb-2" rows="2" readonly id="bumi-embed-{{ $channel->id }}">&lt;iframe src="{{ route('bumi.chat.show', $channel) }}" title="Bumi" style="width:100%;height:640px;border:0"&gt;&lt;/iframe&gt;</textarea>
                                <button type="button" class="btn btn-sm btn-ghost-secondary" data-bumi-copy="#bumi-embed-{{ $channel->id }}"><i class="ti ti-copy me-1"></i>{{ __('Копировать код') }}</button>
                            @else
                                <div class="row g-3">
                                    <div class="col-md-7">
                                        <label class="form-label small mb-1" for="bumi-url-{{ $channel->id }}">{{ __('1. Webhook URL — вставить у провайдера') }}</label>
                                        <div class="input-group input-group-sm mb-3">
                                            <input id="bumi-url-{{ $channel->id }}" class="form-control" value="{{ $channel->webhookUrl() }}" readonly>
                                            <button class="btn btn-ghost-secondary" type="button" data-bumi-copy="#bumi-url-{{ $channel->id }}"><i class="ti ti-copy"></i></button>
                                        </div>
                                        @if ($channel->credential('verify_token') !== '')
                                            <label class="form-label small mb-1" for="bumi-verify-{{ $channel->id }}">{{ __('2. Verify Token') }}</label>
                                            <div class="input-group input-group-sm mb-3">
                                                <input id="bumi-verify-{{ $channel->id }}" class="form-control" value="{{ $channel->credential('verify_token') }}" readonly>
                                                <button class="btn btn-ghost-secondary" type="button" data-bumi-copy="#bumi-verify-{{ $channel->id }}"><i class="ti ti-copy"></i></button>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="col-md-5">
                                        @can('checkBoxPermission', 'bumi.manage')
                                        <form method="post" action="{{ route('bumi.settings.channel.update', $channel) }}" data-submit-guard>
                                            @csrf
                                            <div class="form-label small">{{ __('3. Ключи канала') }}</div>
                                            @foreach ($channel->type->secretFields() as $field)
                                                <div class="mb-2">
                                                    <label class="form-label small mb-1" for="bumi-cred-{{ $channel->id }}-{{ $field }}">{{ $field }}</label>
                                                    <input id="bumi-cred-{{ $channel->id }}-{{ $field }}" class="form-control form-control-sm" name="credentials[{{ $field }}]"
                                                           value="{{ old('credentials.'.$field, in_array($field, $channel->type->publicFields(), true) ? $channel->credential($field) : '') }}"
                                                           placeholder="{{ ! in_array($field, $channel->type->publicFields(), true) && $channel->credential($field) !== '' ? __('Сохранено — оставьте пустым') : '' }}" autocomplete="off">
                                                    @error('credentials.'.$field)<div class="text-danger small">{{ $message }}</div>@enderror
                                                </div>
                                            @endforeach
                                            <input type="hidden" name="name" value="{{ $channel->name }}">
                                            <input type="hidden" name="enabled" value="1">
                                            <input type="hidden" name="auto_reply" value="{{ $channel->auto_reply ? '1' : '0' }}">
                                            <button class="btn btn-sm btn-primary w-100" type="submit"><i class="ti ti-plug me-1"></i>{{ __('Подключить') }}</button>
                                        </form>
                                        @endcan
                                    </div>
                                </div>
                            @endif

                            @can('checkBoxPermission', 'bumi.manage')
                                <form method="post" action="{{ route('bumi.settings.channel.toggle', $channel) }}" class="mt-3" data-bumi-toggle data-bumi-toggle-kind="channel" data-bumi-id="{{ $channel->id }}">
                                    @csrf
                                    <input type="hidden" name="enabled" value="{{ $channel->enabled ? '0' : '1' }}">
                                    <button type="submit" class="btn btn-sm {{ $channel->enabled ? 'btn-ghost-warning' : 'btn-primary' }}" data-bumi-toggle-button>
                                        <i class="ti ti-{{ $channel->enabled ? 'player-pause' : 'player-play' }} me-1"></i>
                                        {{ $channel->enabled ? __('Отключить канал') : __('Включить канал') }}
                                    </button>
                                </form>
                            @endcan

                            <a class="btn btn-sm btn-ghost-secondary mt-3" href="{{ route('bumi.settings.channel', $channel) }}">
                                <i class="ti ti-history me-1"></i>{{ __('История канала') }}
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
</div>
