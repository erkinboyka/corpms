<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'CorpMS') — CorpMS</title>

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
    <link rel="stylesheet" href="/assets/css/tokens.css?v9">
    <link rel="stylesheet" href="/assets/css/effects.css?v30">
    <link rel="stylesheet" href="/assets/css/corpms.css?v5">
    <script src="/assets/vendor/tabler.min.js" defer></script>
    <script src="/assets/js/corpms.js?v21" defer></script>
    @stack('styles')
</head>
<body class="crm-app crm-auth d-flex flex-column @yield('body_class')">
    @yield('content')
</body>
</html>
