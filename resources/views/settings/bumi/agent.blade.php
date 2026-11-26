@extends('layouts.tabler')
@section('workspace_layout', 'configuration')
@section('body_class', 'bumi-agent-shell')

@section('title', __('Bumi — агент').' '.$agent->name)

@php
    $tabLabels = [
        'settings' => __('Настройки'),
        'channels' => __('Каналы'),
        'knowledge' => __('База знаний'),
        'playground' => __('Тест ответов'),
        'conversations' => __('Диалоги'),
    ];
    $tabIcons = [
        'settings' => 'settings',
        'channels' => 'plug',
        'knowledge' => 'books',
        'playground' => 'sparkles',
        'conversations' => 'messages',
    ];
    $tabHints = [
        'settings' => __('имя, модель, промпт'),
        'channels' => __('сайт и мессенджеры'),
        'knowledge' => __('факты, ответы, файлы'),
        'playground' => __('проверить ответ'),
        'conversations' => __('история обращений'),
    ];
    $qaCount = $knowledgeItems->where('kind', 'qa')->count();
    $fileCount = $knowledgeItems->where('kind', 'file')->count();
@endphp

@section('content')
<nav class="bm-crumbs mb-3" aria-label="{{ __('Хлебные крошки') }}">
    <a class="bm-crumbs__item" href="{{ route('bumi.settings.index') }}">{{ __('Bumi в чатах') }}</a>
    <i class="ti ti-chevron-right bm-crumbs__sep" aria-hidden="true"></i>
    <span class="bm-crumbs__item bm-crumbs__item--current">{{ $agent->name }}</span>
</nav>

<div class="row g-3 bumi-agent-shell__layout">
    <aside class="col-xl-3">
        <div class="card bumi-agent-shell__identity mb-3">
            <div class="card-body text-center">
                @include('partials.bumi-face', [
                    'class' => 'bumi-agent-shell__face',
                    'mood' => $agent->enabled ? 'happy' : 'sleepy',
                    'line' => $agent->enabled ? __('На связи. Клиенты могут писать мне прямо сейчас.') : __('Я на паузе. Включи — вернусь с идеями.'),
                ])
                <h1 class="h3 mt-2 mb-1">{{ $agent->name }}</h1>
                <span class="crm-chip {{ $agent->enabled ? 'crm-chip--green' : 'crm-chip--grey' }}" data-bumi-agent-status="{{ $agent->id }}">
                    {{ $agent->enabled ? __('Активен') : __('На паузе') }}
                </span>
                <div class="mt-3 text-start">
                    @if (trim((string) $agent->description) !== '')
                        @include('partials.rich-text', ['value' => $agent->description, 'class' => 'small'])
                    @else
                        <div class="text-secondary small">{{ __('Агент внешних каналов') }}</div>
                    @endif
                </div>
            </div>
            <div class="list-group list-group-flush bumi-agent-shell__facts">
                <div class="list-group-item d-flex justify-content-between gap-2">
                    <span class="text-secondary small"><i class="ti ti-plug me-1"></i>{{ __('Каналы') }}</span>
                    <span class="small fw-semibold" data-bumi-counter="channels">{{ $channels->count() }}</span>
                </div>
                <div class="list-group-item d-flex justify-content-between gap-2">
                    <span class="text-secondary small"><i class="ti ti-message-circle me-1"></i>{{ __('Ответы') }}</span>
                    <span class="small fw-semibold" data-bumi-counter="qa">{{ $qaCount }}</span>
                </div>
                <div class="list-group-item d-flex justify-content-between gap-2">
                    <span class="text-secondary small"><i class="ti ti-file-description me-1"></i>{{ __('Файлы') }}</span>
                    <span class="small fw-semibold" data-bumi-counter="files">{{ $fileCount }}</span>
                </div>
                <div class="list-group-item d-flex justify-content-between gap-2">
                    <span class="text-secondary small"><i class="ti ti-brain me-1"></i>{{ __('Модель') }}</span>
                    <span class="small fw-semibold text-break">{{ $agent->model ?: $defaultModel }}</span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">{{ __('Разделы') }}</div>
            <div class="list-group list-group-flush bumi-agent-shell__nav">
                @foreach ($tabs as $item)
                    <a class="list-group-item list-group-item-action d-flex align-items-center gap-2 {{ $tab === $item ? 'active' : '' }}"
                       href="{{ route('bumi.settings.agent', [$agent, 'tab' => $item]) }}"
                       @if ($tab === $item) aria-current="page" @endif>
                        <i class="ti ti-{{ $tabIcons[$item] ?? 'circle' }}"></i>
                        <span class="flex-grow-1">
                            <span class="d-block">{{ $tabLabels[$item] ?? $item }}</span>
                            <span class="d-block text-secondary small bumi-agent-shell__nav-hint">{{ $tabHints[$item] ?? '' }}</span>
                        </span>
                        <i class="ti ti-chevron-right text-secondary"></i>
                    </a>
                @endforeach
            </div>
        </div>
    </aside>

    <div class="col-xl-9 bumi-agent-shell__content">
        @if ($tab === 'settings')
            @include('settings.bumi.partials.settings-tab')
        @elseif ($tab === 'channels')
            @include('settings.bumi.partials.channels-tab')
        @elseif ($tab === 'knowledge')
            @include('settings.bumi.partials.knowledge-tab')
        @elseif ($tab === 'playground')
            @include('settings.bumi.partials.playground-tab')
        @else
            @include('settings.bumi.partials.conversations-tab')
        @endif
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
            var old = button.textContent;
            button.textContent = '{{ __('Скопировано') }}';
            window.setTimeout(function () { button.textContent = old; }, 1500);
        });
    });

    document.addEventListener('click', function (event) {
        var chip = event.target.closest && event.target.closest('[data-bumi-question]');
        if (!chip) { return; }
        var text = chip.getAttribute('data-bumi-question') || '';
        var field = document.getElementById('bumi-playground-message');
        if (!field) { return; }
        var editor = window.tinymce && typeof window.tinymce.get === 'function'
            ? window.tinymce.get(field.id) : null;
        if (editor) { editor.setContent(text); editor.focus(); }
        else { field.value = text; field.focus(); }
    });

    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.querySelector('.bumi-kb-reopen');
        if (modal && window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        }
    });
</script>
@endpush
