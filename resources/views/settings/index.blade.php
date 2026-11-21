@extends('layouts.tabler')
@section('workspace_layout', 'configuration')

@section('title', __('Настройки системы'))

@php
    $grouped = [];
    foreach ($settings as $key => $meta) {
        $grouped[$meta['group']][$key] = $meta;
    }

    $groupHints = [
        __('Задачи') => __('Ограничения структуры задач и подзадач.'),
        __('Документы') => __('Приём файлов при регистрации и согласовании.'),
        __('Подписи') => __('Сроки жизни одноразовых кодов и QR-токенов подписи.'),
        __('Посещаемость') => __('Биометрия, liveness и порог офлайна терминалов.'),
        __('Уведомления') => __('Расписание утренних сводок и оповещений.'),
        __('AI-ассистент') => __('Подключите совместимого провайдера. Ключ скрывается после сохранения.'),
    ];

    $envMeta = [
        'telegram_bot' => [__('Telegram-бот'), 'brand-telegram'],
        'telegram_webhook_secret' => [__('Webhook-секрет Telegram'), 'lock'],
        'ai_base_url' => [__('AI-модуль (п.68 ТЗ)'), 'sparkles'],
    ];
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Система') }}</div>
            <h1 class="page-title mt-1">{{ __('Настройки системы') }}</h1>
            <div class="text-secondary mt-1">{{ __('Значения сохраняются для вашей компании и перекрывают общие настройки') }} <code>config/corpms.php</code> {{ __('и') }} <code>.env</code> {{ __('каждое сохранение пишется в журнал (settings.updated)') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <span class="crm-chip {{ $canManage ? 'crm-chip--green' : 'crm-chip--grey' }}">
                <i class="ti ti-{{ $canManage ? 'pencil' : 'eye' }} me-1"></i>{{ $canManage ? __('доступна правка') : __('только просмотр') }}
            </span>
        </div>
    </div>
</div>

<div class="row g-3">
    {{-- ===== Параметры модулей ===== --}}
    <div class="col-lg-8">
        <form method="post" action="{{ route('settings.update') }}" data-submit-guard>
            @csrf
            @foreach ($grouped as $group => $items)
                <div class="crm-form__section {{ $loop->last ? 'mb-0' : '' }}">
                    <h3>{{ $group }}</h3>
                    <div class="crm-form__desc">{{ $groupHints[$group] ?? __('Параметры модуля системы.') }}</div>

                    @foreach ($items as $key => $meta)
                        <div class="crm-form__row align-items-center">
                            <div class="crm-form__field mb-0">
                                <label class="form-label mb-1" for="set-{{ str_replace('.', '-', $key) }}">{{ $meta['name'] }}</label>
                                <div class="text-secondary small"><code>{{ $key }}</code></div>
                            </div>
                            <div class="crm-form__field mb-0">
                                @if (! $canManage)
                                    <span class="crm-chip {{ $meta['type'] === 'bool' ? ($meta['value'] ? 'crm-chip--green' : 'crm-chip--grey') : 'crm-chip--cyan' }}">
                                        {{ $meta['type'] === 'bool' ? ($meta['value'] ? __('вкл') : __('выкл')) : ($meta['type'] === 'secret' ? ($meta['configured'] ? __('настроен') : __('не задан')) : $meta['value']) }}
                                    </span>
                                @elseif ($meta['type'] === 'bool')
                                    <div class="form-check form-switch m-0">
                                        <input type="hidden" name="settings[{{ $key }}]" value="0">
                                        <input class="form-check-input" type="checkbox" name="settings[{{ $key }}]" value="1"
                                               id="set-{{ str_replace('.', '-', $key) }}" @checked($meta['value'])>
                                        <label class="form-check-label text-secondary small" for="set-{{ str_replace('.', '-', $key) }}">
                                            {{ $meta['value'] ? __('включено сейчас') : __('выключено сейчас') }}
                                        </label>
                                    </div>
                                @elseif ($meta['type'] === 'secret')
                                    <div>
                                        <input type="password" class="form-control" style="max-width: 24rem"
                                               id="set-{{ str_replace('.', '-', $key) }}" name="settings[{{ $key }}]"
                                               value="" autocomplete="new-password"
                                               placeholder="{{ $meta['configured'] ? __('Ключ сохранён — введите новый для замены') : __('Вставьте API-ключ') }}">
                                        <div class="form-hint">{{ __('Пустое поле оставляет текущий ключ без изменений') }}</div>
                                    </div>
                                @else
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="{{ $meta['type'] === 'int' ? 'number' : 'text' }}" class="form-control" style="max-width: 24rem"
                                               id="set-{{ str_replace('.', '-', $key) }}"
                                               name="settings[{{ $key }}]" value="{{ $meta['value'] }}"
                                               @if ($key === 'corpms.ai.base_url') placeholder="https://api.groq.com/openai/v1" @endif
                                               @if ($key === 'corpms.ai.model') placeholder="openai/gpt-oss-120b" @endif>
                                        <span class="crm-chip {{ $meta['scope'] === 'company' ? 'crm-chip--green' : ($meta['scope'] === 'global' ? 'crm-chip--cyan' : 'crm-chip--grey') }}">
                                            {{ $meta['scope'] === 'company' ? __('компания') : ($meta['scope'] === 'global' ? __('общая') : 'config') }}
                                        </span>
                                    </div>
                                @endif
                                @if ($meta['type'] !== 'bool')
                                    <div class="form-hint">{{ __('текущий источник') }} {{ $meta['scope'] === 'company' ? __('сохранено для вашей компании') : ($meta['scope'] === 'global' ? __('общая настройка (для всех компаний)') : __('значение из config')) }}</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach

            <div class="crm-form__footer crm-form__footer--card">
                <div class="text-secondary small">
                    @if ($canManage)
                        {{ __('Сохранение применяется сразу и попадает в') }} Audit Log.
                    @else
                        {{ __('У вас нет права settings.manage — доступен только просмотр') }}
                    @endif
                </div>
                @if ($canManage)
                    <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>{{ __('Сохранить') }}</button>
                @endif
            </div>
        </form>
    </div>

    {{-- ===== Окружение ===== --}}
    <div class="col-lg-4">
        <div class="crm-form__section">
            <h3>{{ __('Окружение (.env)') }}</h3>
            <div class="crm-form__desc">{{ __('Только чтение: правится в файле окружения, не на этой странице') }}</div>
            @foreach ($envMeta as $key => $item)
                <div class="d-flex align-items-center justify-content-between py-2" style="border-bottom: 1px solid var(--tblr-border-color);">
                    <span class="d-inline-flex align-items-center gap-2 small">
                        <i class="ti ti-{{ $item[1] }}"></i>{{ $item[0] }}
                    </span>
                    <span class="crm-chip {{ $env[$key] == __('настроен') ? 'crm-chip--green' : 'crm-chip--orange' }}">{{ $env[$key] }}</span>
                </div>
            @endforeach
        </div>

        <div class="crm-form__section">
            <h3>{{ __('Приоритет источников') }}</h3>
            <div class="crm-form__desc">{{ __('Откуда берётся значение параметра') }}</div>
            <div class="crm-timeline">
                <div class="crm-timeline__item">
                    <div class="crm-timeline__time">1</div>
                    <div class="crm-timeline__title"><strong>{{ __('БД (settings)') }}</strong></div>
                    <div class="crm-timeline__text">{{ __('Записано здесь — перекрывает всё остальное') }}</div>
                </div>
                <div class="crm-timeline__item">
                    <div class="crm-timeline__time">2</div>
                    <div class="crm-timeline__title"><strong>config/corpms.php</strong></div>
                    <div class="crm-timeline__text">{{ __('Значение по умолчанию из конфигурации приложения') }}</div>
                </div>
                <div class="crm-timeline__item">
                    <div class="crm-timeline__time">3</div>
                    <div class="crm-timeline__title"><strong>.env</strong></div>
                    <div class="crm-timeline__text">{{ __('Секреты и адреса внешних сервисов') }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
