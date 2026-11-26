@extends('layouts.tabler')
@section('workspace_layout', 'configuration')

@section('title', __('Bumi — канал').' '.$channel->name)

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow"><a class="text-decoration-none" href="{{ route('bumi.settings.index') }}">Bumi</a></div>
            <h1 class="page-title mt-1"><i class="ti ti-{{ $channel->type->icon() }} me-2"></i>{{ $channel->name }}</h1>
            <div class="text-secondary mt-1">{{ $channel->type->label() }} · {{ __('агент') }}: {{ $channel->agent?->name ?? '—' }}</div>
        </div>
        <div class="col-auto d-print-none">
            <span class="crm-chip {{ $channel->statusClass() }}">{{ $channel->statusLabel() }}</span>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Подключение') }}</h3></div>
            <div class="card-body">
                @if ($channel->type === \App\Shared\Enums\BumiChannelType::Website)
                    <div class="crm-form__desc mb-3">{{ __('Готовый чат открывается без входа. Вставьте код на сайт или дайте клиенту прямую ссылку.') }}</div>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <a class="btn btn-sm btn-primary" href="{{ route('bumi.chat.show', $channel) }}" target="_blank" rel="noopener">
                            <i class="ti ti-external-link me-1"></i>{{ __('Открыть чат') }}
                        </a>
                    </div>
                    <label class="form-label small mb-1" for="bumi-embed">{{ __('Код для сайта') }}</label>
                    <textarea id="bumi-embed" class="form-control form-control-sm mb-2" rows="2" readonly>&lt;iframe src="{{ route('bumi.chat.show', $channel) }}" title="Bumi" style="width:100%;height:640px;border:0"&gt;&lt;/iframe&gt;</textarea>
                    <button type="button" class="btn btn-sm btn-ghost-secondary" data-bumi-copy="#bumi-embed"><i class="ti ti-copy me-1"></i>{{ __('Копировать') }}</button>
                @else
                    <div class="crm-form__desc mb-3">{{ __('Укажите эти значения на стороне провайдера. Секреты в журнал и ответы не попадают.') }}</div>
                    @if ($channel->webhookUrl() !== '')
                        <label class="form-label small mb-1" for="bumi-webhook-url">{{ __('Webhook URL') }}</label>
                        <div class="input-group mb-3">
                            <input id="bumi-webhook-url" class="form-control" value="{{ $channel->webhookUrl() }}" readonly>
                            <button class="btn btn-ghost-secondary" type="button" data-bumi-copy="#bumi-webhook-url"><i class="ti ti-copy"></i></button>
                        </div>
                    @endif
                    @if (array_key_exists('verify_token', $credentials) && $credentials['verify_token'] !== '')
                        <label class="form-label small mb-1" for="bumi-verify-token">{{ __('Verify Token') }}</label>
                        <div class="input-group mb-3">
                            <input id="bumi-verify-token" class="form-control" value="{{ $credentials['verify_token'] }}" readonly>
                            <button class="btn btn-ghost-secondary" type="button" data-bumi-copy="#bumi-verify-token"><i class="ti ti-copy"></i></button>
                        </div>
                    @endif
                @endif

                @can('checkBoxPermission', 'bumi.manage')
                    <form method="post" action="{{ route('bumi.settings.channel.token', $channel) }}" class="mt-2">
                        @csrf
                        <button class="btn btn-sm btn-ghost-warning" data-submit-guard>
                            <i class="ti ti-key me-1"></i>{{ __('Обновить публичный токен') }}
                        </button>
                        <div class="form-hint">{{ __('После обновления нужно заменить URL на стороне провайдера') }}</div>
                    </form>
                @endcan
            </div>
        </div>

        @if ($canManage)
        <form method="post" action="{{ route('bumi.settings.channel.update', $channel) }}" class="mt-3" data-submit-guard>
            @csrf
            <div class="crm-form__section">
                <h3>{{ __('Настройки канала') }}</h3>
                @foreach ($errors->all() as $error)
                    <div class="alert alert-danger py-1">{{ $error }}</div>
                @endforeach
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="bumi-channel-name">{{ __('Название') }}<span class="req">*</span></label>
                        <input id="bumi-channel-name" name="name" class="form-control" value="{{ old('name', $channel->name) }}" maxlength="255" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="bumi-channel-greeting">{{ __('Приветствие канала') }}</label>
                        <input id="bumi-channel-greeting" name="greeting" class="form-control" value="{{ old('greeting', $channel->greeting) }}" maxlength="1000">
                    </div>
                </div>
                @foreach ($channel->type->secretFields() as $field)
                    @php
                        $isPublic = in_array($field, $channel->type->publicFields(), true);
                    @endphp
                    <div class="crm-form__field mb-3">
                        <label class="form-label" for="bumi-credential-{{ $field }}">{{ $field }}</label>
                        <input id="bumi-credential-{{ $field }}" class="form-control" name="credentials[{{ $field }}]"
                               value="{{ old('credentials.'.$field, $isPublic ? ($credentials[$field] ?? '') : '') }}"
                               placeholder="{{ ! $isPublic && ($credentials[$field] ?? '') !== '' ? __('Сохранено — оставьте пустым, чтобы не менять') : '' }}"
                               autocomplete="off">
                        @error('credentials.'.$field)<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                @endforeach
                <div class="form-check form-switch mb-2">
                    <input type="hidden" name="enabled" value="0">
                    <input class="form-check-input" type="checkbox" name="enabled" id="bumi-channel-enabled" value="1" @checked((string) old('enabled', $channel->enabled ? '1' : '0') === '1')>
                    <label class="form-check-label" for="bumi-channel-enabled">{{ __('Канал включён') }}</label>
                </div>
                <div class="form-check form-switch mb-3">
                    <input type="hidden" name="auto_reply" value="0">
                    <input class="form-check-input" type="checkbox" name="auto_reply" id="bumi-channel-auto" value="1" @checked((string) old('auto_reply', $channel->auto_reply ? '1' : '0') === '1')>
                    <label class="form-check-label" for="bumi-channel-auto">{{ __('Автоответ Bumi') }}</label>
                </div>
                <div class="crm-form__footer crm-form__footer--card">
                    <div class="text-secondary small">{{ __('Без ключей внешний канал не включается') }}</div>
                    <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>{{ __('Сохранить канал') }}</button>
                </div>
            </div>
        </form>
        @endif
    </div>

    <div class="col-lg-4">
        <div class="crm-form__section">
            <h3>{{ __('Статус') }}</h3>
            <div class="crm-form__desc">{{ __('Контактов') }}: {{ $contacts }} · {{ __('сообщений') }}: {{ $messages->count() }}</div>
            <div class="mt-2"><span class="crm-chip {{ $channel->statusClass() }}" data-bumi-toggle-chip>{{ $channel->statusLabel() }}</span></div>
            @can('checkBoxPermission', 'bumi.manage')
            <form method="post" action="{{ route('bumi.settings.channel.toggle', $channel) }}" class="mt-3" data-bumi-toggle data-bumi-toggle-kind="channel" data-bumi-id="{{ $channel->id }}">
                @csrf
                <input type="hidden" name="enabled" value="{{ $channel->enabled ? '0' : '1' }}">
                <button type="submit" class="btn {{ $channel->enabled ? 'btn-ghost-danger' : 'btn-primary' }} w-100" data-bumi-toggle-button>
                    <i class="ti ti-{{ $channel->enabled ? 'player-pause' : 'player-play' }} me-1"></i>
                    {{ $channel->enabled ? __('Отключить канал') : __('Подключить канал') }}
                </button>
            </form>
            @endcan
            <a class="btn btn-ghost-secondary w-100 mt-2" href="{{ route('bumi.settings.agent', [$channel->agent_id, 'tab' => 'channels']) }}">
                <i class="ti ti-arrow-left me-1"></i>{{ __('К каналу агента') }}
            </a>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header"><h3 class="card-title">{{ __('История канала') }}</h3></div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th>{{ __('Дата') }}</th>
                    <th>{{ __('Клиент') }}</th>
                    <th>{{ __('Кто написал') }}</th>
                    <th>{{ __('Сообщение') }}</th>
                    <th>{{ __('Статус') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($messages as $message)
                    <tr>
                        <td class="text-secondary small">{{ $message->created_at?->format('d.m.Y H:i') }}</td>
                        <td>{{ $message->contact?->displayLabel() ?? '—' }}</td>
                        <td>{{ $message->isFromClient() ? __('Клиент') : 'Bumi' }}</td>
                        <td class="text-break" style="max-width: 480px;">{{ \Illuminate\Support\Str::limit((string) $message->text, 240) }}</td>
                        <td>@include('settings.bumi.partials.message-status', ['message' => $message])</td>
                    </tr>
                @empty
                    <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'messages', 'title' => __('Сообщений пока нет'), 'text' => __('Как только клиент напишет в канал, диалог появится здесь.')])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('[data-bumi-copy]');
        if (!button || !navigator.clipboard) { return; }
        var target = document.querySelector(button.getAttribute('data-bumi-copy'));
        if (!target) { return; }
        navigator.clipboard.writeText(target.value || target.textContent || '').then(function () {
            var old = button.innerHTML;
            button.textContent = '{{ __('Скопировано') }}';
            window.setTimeout(function () { button.innerHTML = old; }, 1500);
        });
    });
</script>
@endpush
