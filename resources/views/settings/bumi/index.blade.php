@extends('layouts.tabler')
@section('workspace_layout', 'configuration')

@section('title', __('Bumi в чатах'))

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Bumi</div>
            <h1 class="page-title mt-1">{{ __('Bumi в чатах') }}</h1>
            <div class="text-secondary mt-1">{{ __('Агенты, внешние каналы и история обращений — как в Velora Agents, но в текущем интерфейсе') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary" href="{{ route('settings.index') }}"><i class="ti ti-settings me-1"></i>{{ __('Настройки системы') }}</a>
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-sparkles"></i>{{ __('Агенты') }}</div>
        <div class="crm-metric__value">{{ $agents->count() }}</div>
        <div class="crm-metric__hint">{{ __('активных') }}: <span data-bumi-counter="agents-active">{{ $agents->where('enabled', true)->count() }}</span></div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-plug-connected"></i>{{ __('Каналы') }}</div>
        <div class="crm-metric__value">{{ $channels->where('enabled', true)->count() }}</div>
        <div class="crm-metric__hint">{{ __('подключено') }}: <span data-bumi-counter="channels-connected">{{ $channels->filter(static fn ($item) => $item->enabled && $item->isConfigured())->count() }}</span> / {{ $channels->count() }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-purple);"><i class="ti ti-users"></i>{{ __('Контакты') }}</div>
        <div class="crm-metric__value">{{ $stats['contacts'] }}</div>
        <div class="crm-metric__hint">{{ __('внешних клиентов') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-user-search"></i>{{ __('Лиды') }}</div>
        <div class="crm-metric__value">{{ $stats['leads'] }}</div>
        <div class="crm-metric__hint">{{ __('создано из обращений') }} · {{ $stats['messages'] }} {{ __('сообщений') }}</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Агенты Bumi') }}</h3></div>
            <div class="card-body">
                <div class="row row-cards">
                    @forelse ($agents as $agent)
                        <div class="col-md-6">
                            <div class="card card-sm h-100 bumi-card" data-bumi-card="agent-{{ $agent->id }}">
                                <div class="card-body">
                                    <div class="d-flex align-items-start justify-content-between gap-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-sm"><i class="ti ti-sparkles"></i></span>
                                            <div>
                                                <div class="font-weight-medium">{{ $agent->name }}</div>
                                                <div class="text-secondary small"><code>{{ $agent->slug }}</code></div>
                                            </div>
                                        </div>
                                        <span class="crm-chip {{ $agent->enabled ? 'crm-chip--green' : 'crm-chip--grey' }}" data-bumi-toggle-chip>
                                            {{ $agent->enabled ? __('Активен') : __('Пауза') }}
                                        </span>
                                    </div>
                                    <div class="text-secondary mt-3">
                                        @if (trim((string) $agent->description) !== '')
                                            @include('partials.rich-text', ['value' => $agent->description, 'class' => 'small'])
                                        @else
                                            {{ __('Описание не заполнено') }}
                                        @endif
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 mt-3 small text-secondary">
                                        <span><i class="ti ti-plug me-1"></i>{{ $agent->channels->count() }} {{ __('каналов') }}</span>
                                        <span><i class="ti ti-bolt me-1"></i>{{ $agent->auto_reply ? __('автоответ вкл') : __('автоответ выкл') }}</span>
                                        <span><i class="ti ti-user-plus me-1"></i>{{ $agent->create_leads ? __('лиды создаются') : __('лиды выкл') }}</span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 mt-3">
                                        <a class="btn btn-sm btn-primary" href="{{ route('bumi.settings.agent', $agent) }}">
                                            <i class="ti ti-settings me-1"></i>{{ __('Настроить') }}
                                        </a>
                                        @can('checkBoxPermission', 'bumi.manage')
                                        <form method="post" action="{{ route('bumi.settings.agent.toggle', $agent) }}" class="ms-auto" data-bumi-toggle data-bumi-toggle-kind="agent" data-bumi-id="{{ $agent->id }}">
                                            @csrf
                                            <input type="hidden" name="enabled" value="{{ $agent->enabled ? '0' : '1' }}">
                                            <button type="submit" class="btn btn-sm btn-ghost-secondary" data-bumi-toggle-button>
                                                <i class="ti ti-{{ $agent->enabled ? 'player-pause' : 'player-play' }} me-1"></i>
                                                {{ $agent->enabled ? __('Пауза') : __('Включить') }}
                                            </button>
                                        </form>
                                        @endcan
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="col-12">@include('partials.empty-state', ['icon' => 'sparkles', 'title' => __('Агентов пока нет'), 'text' => __('Агент Bumi создаётся автоматически при первом открытии настроек.')])</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Каналы обращений') }}</h3></div>
            <div class="card-body p-0">
                @foreach ($channels as $channel)
                    <a href="{{ route('bumi.settings.channel', $channel) }}" class="d-flex align-items-center gap-3 px-3 py-3 text-decoration-none" style="border-bottom: 1px solid var(--tblr-border-color);">
                        <span class="avatar avatar-sm"><i class="ti ti-{{ $channel->type->icon() }}"></i></span>
                        <span class="min-width-0 flex-fill">
                            <span class="d-block font-weight-medium text-body">{{ $channel->name }}</span>
                            <span class="d-block text-secondary small">{{ $channel->type->label() }}</span>
                        </span>
                        <span class="crm-chip {{ $channel->statusClass() }}" data-bumi-channel-status="{{ $channel->id }}">{{ $channel->statusLabel() }}</span>
                        <i class="ti ti-chevron-right text-secondary"></i>
                    </a>
                @endforeach
            </div>
        </div>

        <div class="crm-form__section mt-3">
            <h3>{{ __('Как это работает') }}</h3>
            <div class="crm-form__desc">{{ __('Клиент пишет в канале — Bumi отвечает и создаёт лид, если включено') }}</div>
            <div class="crm-timeline mt-3">
                <div class="crm-timeline__item">
                    <div class="crm-timeline__time">1</div>
                    <div class="crm-timeline__title">{{ __('Подключите канал') }}</div>
                    <div class="crm-timeline__text">{{ __('Укажите ключи или токены и вставьте webhook у провайдера') }}</div>
                </div>
                <div class="crm-timeline__item">
                    <div class="crm-timeline__time">2</div>
                    <div class="crm-timeline__title">{{ __('Настройте агента') }}</div>
                    <div class="crm-timeline__text">{{ __('Промпт, база знаний, приветствие и лимит ответов') }}</div>
                </div>
                <div class="crm-timeline__item">
                    <div class="crm-timeline__time">3</div>
                    <div class="crm-timeline__title">{{ __('Смотрите диалоги') }}</div>
                    <div class="crm-timeline__text">{{ __('История, статусы доставки и повтор автоответа') }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
