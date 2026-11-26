<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="description" content="@yield('meta_description', __('BM Systems — вход в единую бизнес-платформу: сотрудники, задачи, CRM, финансы и аналитика.'))">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'BM Systems') — BM Systems</title>

    {{-- тема до отрисовки (без «вспышки») --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('crm-theme');
                if (t !== 'dark' && t !== 'light') {
                    t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                document.documentElement.setAttribute('data-bs-theme', t);
            } catch (e) { /* private mode: оставляем light */ }
        })();
    </script>

    {{-- Tabler 1.1.1 + иконки — локальные ресурсы (вендоринг: скорость, оффлайн, без внешних CDN) --}}
    <link rel="stylesheet" href="/assets/vendor/tabler.min.css">
    <link rel="stylesheet" href="/assets/vendor/tabler-vendors.min.css">
    <link rel="preload" href="/assets/vendor/fonts/tabler-icons.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/assets/vendor/fonts/inter-cyrillic-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/vendor/tabler-icons.min.css">
    <link rel="stylesheet" href="/assets/css/tokens.css?v34">
    <link rel="stylesheet" href="/assets/css/effects.css?v58">
    <link rel="stylesheet" href="/assets/css/corpms.css?v7">
    <link rel="stylesheet" href="/assets/css/bm-ui.css?v22">
    <script src="/assets/vendor/tabler.min.js" defer></script>
    <script src="/assets/js/corpms.js?v47" defer></script>
    <link rel="icon" type="image/svg+xml" href="/assets/bm-mark.svg">
    <link rel="stylesheet" href="/assets/css/motion.css?v5">
    <link rel="stylesheet" href="/assets/css/workspace.css?v26">
    <link rel="stylesheet" href="/assets/css/bumi-persona.css?v4">
    <link rel="stylesheet" href="/assets/css/legal.css?v1">
    <script src="/assets/js/password-visibility.js" defer></script>
    @include('partials.bumi-i18n')
    <script src="/assets/js/bumi-persona.js?v4" defer></script>
    <script src="/assets/js/auth-mascot.js?v7" defer></script>
    @stack('styles')
</head>
<body class="crm-app crm-auth d-flex flex-column @yield('body_class')"
      @if(isset($bumi)) data-bumi-mood="{{ $bumi->mood->value }}" data-bumi-line="{{ $bumi->line }}" data-bumi-reason="{{ $bumi->reason }}" @endif>
    <main class="crm-auth__wrap bm-auth-layout">
        <div class="bm-auth-art" data-mascot-greeting @if(isset($bumi)) data-mood="{{ $bumi->mood->value }}" @endif>
            <div class="bm-auth-speech" data-mascot-speech aria-hidden="true" hidden>
                <svg class="bm-auth-speech__tail" viewBox="0 0 80 30" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                    <path d="M80 3C57 15 30 15 3 9c18 15 49 21 77 17"/>
                </svg>
                <p><span data-mascot-line>{{ __('Привет! Я Bumi') }}</span><span data-mascot-rest></span></p>
            </div>
            <span class="visually-hidden" data-mascot-status role="status" aria-atomic="true"></span>
            <button class="bm-auth-mascot-trigger" type="button" data-mascot-react aria-label="{{ __("Поговорить с Bumi") }}">
                @include('partials.auth-mascot')
            </button>
        </div>
        <div class="bm-auth-form">
            <header class="crm-auth__brand">
                <a class="crm-auth__title bm-auth-brand-link" href="{{ route('welcome') }}">BM Systems</a>
            </header>
            @yield('content')
        </div>
    </main>
</body>
</html>
