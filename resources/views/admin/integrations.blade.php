@extends('layouts.tabler')
@section('workspace_layout', 'configuration')

@section('title', 'Интеграции')

@php
    $statusMeta = [
        'configured' => ['crm-chip--green', 'подключено', 'circle-check'],
        'fallback' => ['crm-chip--cyan', 'резервный режим', 'arrows-exchange'],
        'polling' => ['crm-chip--cyan', 'polling', 'refresh'],
        'local' => ['crm-chip--cyan', 'локально', 'device-hard-drive'],
        'native' => ['crm-chip--cyan', 'встроено', 'puzzle'],
        'not_configured' => ['crm-chip--orange', 'не настроено', 'circle-dashed'],
    ];
    $icons = [
        'Telegram-бот уведомлений' => 'brand-telegram',
        'Live-обновления посещаемости' => 'broadcast',
        'Почта (SMTP)' => 'mail',
        'Биометрические терминалы' => 'fingerprint',
        'Файловое хранилище' => 'folder',
        'AI-ассистент' => 'sparkles',
    ];

    $configured = 0;
    $notConfigured = 0;
    $builtin = 0;
    foreach ($integrations as $integration) {
        if ($integration['status'] === 'configured') {
            $configured++;
        } elseif ($integration['status'] === 'not_configured') {
            $notConfigured++;
        } else {
            $builtin++;
        }
    }
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Система</div>
            <h1 class="page-title mt-1">Интеграции</h1>
            <div class="text-secondary mt-1">Состояние внешних подсистем · конфигурация через .env и config, страница только для чтения</div>
        </div>
        <div class="col-auto d-print-none">
            @can('checkBoxPermission', 'settings.view')
                <a class="btn btn-ghost-secondary" href="{{ $settingsPage }}"><i class="ti ti-settings me-1"></i>Настройки системы</a>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-plug"></i>Интеграций</div>
        <div class="crm-metric__value">{{ count($integrations) }}</div>
        <div class="crm-metric__hint">внешних подсистем в системе</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-circle-check"></i>Подключено</div>
        <div class="crm-metric__value">{{ $configured }}</div>
        <div class="crm-metric__hint">заполнены обязательные параметры</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-circle-dashed"></i>Не настроено</div>
        <div class="crm-metric__value">{{ $notConfigured }}</div>
        <div class="crm-metric__hint">работают в ограниченном режиме</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-puzzle"></i>Встроенные</div>
        <div class="crm-metric__value">{{ $builtin }}</div>
        <div class="crm-metric__hint">без внешней конфигурации</div>
    </div>
</div>

<div class="row g-3">
    @foreach ($integrations as $integration)
        @php($meta = $statusMeta[$integration['status']] ?? ['crm-chip--grey', $integration['status'], 'circle'])
        <div class="col-md-6 col-xl-4">
            <div class="crm-entity h-100">
                <div class="crm-entity__head">
                    <span class="crm-entity__logo"><i class="ti ti-{{ $icons[$integration['name']] ?? 'plug' }}"></i></span>
                    <div class="min-w-0">
                        <div class="crm-entity__name">{{ $integration['name'] }}</div>
                        <div class="crm-entity__sub">{{ $integration['description'] }}</div>
                    </div>
                </div>
                <div class="crm-entity__meta">
                    <span class="d-inline-flex align-items-center gap-1">
                        <i class="ti ti-{{ $meta[2] }}"></i>{{ $integration['details'] }}
                    </span>
                </div>
                <div class="crm-entity__footer">
                    <span class="crm-chip {{ $meta[0] }}">{{ $meta[1] }}</span>
                    <span class="text-secondary small">только чтение</span>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="crm-note mt-3">
    <span class="crm-note__icon"><i class="ti ti-info-circle"></i></span>
    <div>
        <div class="fw-semibold">Где это настраивается</div>
        <div class="text-secondary small">
            Значения читаются из <code>.env</code> и <code>config/*.php</code>: TELEGRAM_BOT_TOKEN, SMTP-хост,
            параметры LLM. Изменения применяются после перечитывания конфигурации; состояние ниже — снимок при открытии страницы.
        </div>
    </div>
</div>
@endsection
