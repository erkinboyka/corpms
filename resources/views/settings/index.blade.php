@extends('layouts.tabler')

@section('title', 'Настройки системы')

@php
    $grouped = [];
    foreach ($settings as $key => $meta) {
        $grouped[$meta['group']][$key] = $meta;
    }

    $groupHints = [
        'Задачи' => 'Ограничения структуры задач и подзадач.',
        'Документы' => 'Приём файлов при регистрации и согласовании.',
        'Подписи' => 'Сроки жизни одноразовых кодов и QR-токенов подписи.',
        'Посещаемость' => 'Биометрия, liveness и порог офлайна терминалов.',
        'Уведомления' => 'Расписание утренних сводок и оповещений.',
        'AI-ассистент' => 'Подключите совместимого провайдера. Ключ скрывается после сохранения.',
    ];

    $envMeta = [
        'telegram_bot' => ['Telegram-бот', 'brand-telegram'],
        'telegram_webhook_secret' => ['Webhook-секрет Telegram', 'lock'],
        'ai_base_url' => ['AI-модуль (п.68 ТЗ)', 'sparkles'],
    ];
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Система</div>
            <h2 class="page-title mt-1">Настройки системы</h2>
            <div class="text-secondary mt-1">Значения в БД перекрывают <code>config/corpms.php</code> и <code>.env</code> · каждое сохранение пишется в журнал (settings.updated)</div>
        </div>
        <div class="col-auto d-print-none">
            <span class="crm-chip {{ $canManage ? 'crm-chip--green' : 'crm-chip--grey' }}">
                <i class="ti ti-{{ $canManage ? 'pencil' : 'eye' }} me-1"></i>{{ $canManage ? 'доступна правка' : 'только просмотр' }}
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
                    <div class="crm-form__desc">{{ $groupHints[$group] ?? 'Параметры модуля системы.' }}</div>

                    @foreach ($items as $key => $meta)
                        <div class="crm-form__row align-items-center">
                            <div class="crm-form__field mb-0">
                                <label class="form-label mb-1" for="set-{{ str_replace('.', '-', $key) }}">{{ $meta['name'] }}</label>
                                <div class="text-secondary small"><code>{{ $key }}</code></div>
                            </div>
                            <div class="crm-form__field mb-0">
                                @if (! $canManage)
                                    <span class="crm-chip {{ $meta['type'] === 'bool' ? ($meta['value'] ? 'crm-chip--green' : 'crm-chip--grey') : 'crm-chip--cyan' }}">
                                        {{ $meta['type'] === 'bool' ? ($meta['value'] ? 'вкл' : 'выкл') : ($meta['type'] === 'secret' ? ($meta['configured'] ? 'настроен' : 'не задан') : $meta['value']) }}
                                    </span>
                                @elseif ($meta['type'] === 'bool')
                                    <div class="form-check form-switch m-0">
                                        <input type="hidden" name="settings[{{ $key }}]" value="0">
                                        <input class="form-check-input" type="checkbox" name="settings[{{ $key }}]" value="1"
                                               id="set-{{ str_replace('.', '-', $key) }}" @checked($meta['value'])>
                                        <label class="form-check-label text-secondary small" for="set-{{ str_replace('.', '-', $key) }}">
                                            {{ $meta['value'] ? 'включено сейчас' : 'выключено сейчас' }}
                                        </label>
                                    </div>
                                @elseif ($meta['type'] === 'secret')
                                    <div>
                                        <input type="password" class="form-control" style="max-width: 24rem"
                                               id="set-{{ str_replace('.', '-', $key) }}" name="settings[{{ $key }}]"
                                               value="" autocomplete="new-password"
                                               placeholder="{{ $meta['configured'] ? 'Ключ сохранён — введите новый для замены' : 'Вставьте API-ключ' }}">
                                        <div class="form-hint">Пустое поле оставляет текущий ключ без изменений.</div>
                                    </div>
                                @else
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="{{ $meta['type'] === 'int' ? 'number' : 'text' }}" class="form-control" style="max-width: 24rem"
                                               id="set-{{ str_replace('.', '-', $key) }}"
                                               name="settings[{{ $key }}]" value="{{ $meta['value'] }}"
                                               @if ($key === 'corpms.ai.base_url') placeholder="https://api.groq.com/openai/v1" @endif
                                               @if ($key === 'corpms.ai.model') placeholder="openai/gpt-oss-120b" @endif>
                                        <span class="crm-chip {{ $meta['in_db'] ? 'crm-chip--green' : 'crm-chip--grey' }}">
                                            {{ $meta['in_db'] ? 'из БД' : 'config' }}
                                        </span>
                                    </div>
                                @endif
                                @if ($meta['type'] !== 'bool')
                                    <div class="form-hint">текущий источник: {{ $meta['in_db'] ? 'сохранено в БД' : 'значение из config' }}</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach

            <div class="crm-form__footer crm-form__footer--card">
                <div class="text-secondary small">
                    @if ($canManage)
                        Сохранение применяется сразу и попадает в Audit Log.
                    @else
                        У вас нет права settings.manage — доступен только просмотр.
                    @endif
                </div>
                @if ($canManage)
                    <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Сохранить</button>
                @endif
            </div>
        </form>
    </div>

    {{-- ===== Окружение ===== --}}
    <div class="col-lg-4">
        <div class="crm-form__section">
            <h3>Окружение (.env)</h3>
            <div class="crm-form__desc">Только чтение: правится в файле окружения, не на этой странице.</div>
            @foreach ($envMeta as $key => $item)
                <div class="d-flex align-items-center justify-content-between py-2" style="border-bottom: 1px solid var(--tblr-border-color);">
                    <span class="d-inline-flex align-items-center gap-2 small">
                        <i class="ti ti-{{ $item[1] }}"></i>{{ $item[0] }}
                    </span>
                    <span class="crm-chip {{ $env[$key] === 'настроен' ? 'crm-chip--green' : 'crm-chip--orange' }}">{{ $env[$key] }}</span>
                </div>
            @endforeach
        </div>

        <div class="crm-form__section">
            <h3>Приоритет источников</h3>
            <div class="crm-form__desc">Откуда берётся значение параметра.</div>
            <div class="crm-timeline">
                <div class="crm-timeline__item">
                    <div class="crm-timeline__time">1</div>
                    <div class="crm-timeline__title"><strong>БД (settings)</strong></div>
                    <div class="crm-timeline__text">Записано здесь — перекрывает всё остальное.</div>
                </div>
                <div class="crm-timeline__item">
                    <div class="crm-timeline__time">2</div>
                    <div class="crm-timeline__title"><strong>config/corpms.php</strong></div>
                    <div class="crm-timeline__text">Значение по умолчанию из конфигурации приложения.</div>
                </div>
                <div class="crm-timeline__item">
                    <div class="crm-timeline__time">3</div>
                    <div class="crm-timeline__title"><strong>.env</strong></div>
                    <div class="crm-timeline__text">Секреты и адреса внешних сервисов.</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
