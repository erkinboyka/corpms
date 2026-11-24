<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="description" content="@yield('meta_description', __('BM Systems — единая бизнес-платформа: сотрудники, задачи, CRM, финансы, документы и аналитика в одном интерфейсе.'))">
    <meta name="robots" content="@yield('meta_robots', 'index,follow')">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:title" content="@yield('title', 'BM Systems') — BM Systems">
    <meta property="og:description" content="@yield('meta_description', __('BM Systems — единая бизнес-платформа.'))">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if (config('broadcasting.default') === 'reverb' && config('broadcasting.connections.reverb.key'))
        {{-- параметры realtime-канала для Echo (см. assets/js/bm-realtime.js) --}}
        <meta name="reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
        <meta name="reverb-host" content="{{ config('broadcasting.connections.reverb.options.host') }}">
        <meta name="reverb-port" content="{{ (string) config('broadcasting.connections.reverb.options.port') }}">
        <meta name="reverb-scheme" content="{{ config('broadcasting.connections.reverb.options.scheme') }}">
        @auth
            <meta name="employee-id" content="{{ (string) auth()->user()->employee?->id }}">
        @endauth
    @endif
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
                var a = localStorage.getItem('crm-accent');
                if (a && /^(teal|blue|violet|rose|amber)$/.test(a)) {
                    document.documentElement.setAttribute('data-crm-accent', a);
                }
                var s = localStorage.getItem('crm-scale');
                if (s && /^(sm|md|lg)$/.test(s) && s !== 'md') {
                    document.documentElement.setAttribute('data-crm-scale', s);
                }
                var r = localStorage.getItem('bm-side-rail');
                if (r === '1') {
                    document.documentElement.classList.add('bm-side-rail');
                }
            } catch (e) { /* private mode: оставляем light */ }
        })();
    </script>
    <link rel="stylesheet" href="/assets/vendor/tabler.min.css">
    <link rel="stylesheet" href="/assets/vendor/tabler-vendors.min.css">
    <link rel="preload" href="/assets/vendor/fonts/tabler-icons.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/assets/vendor/fonts/inter-cyrillic-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/vendor/tabler-icons.min.css">
    <link rel="stylesheet" href="/assets/css/tokens.css?v34">
    <link rel="stylesheet" href="/assets/css/effects.css?v62">
    <link rel="stylesheet" href="/assets/css/corpms.css?v9">
    <link rel="stylesheet" href="/assets/css/bm-ui.css?v22">
    <link rel="stylesheet" href="/assets/css/ai-panel.css?v16">
    <link rel="stylesheet" href="/assets/css/motion.css?v8">
    <link rel="stylesheet" href="/assets/css/ui-fixes.css?v3">
    <script src="/assets/vendor/tabler.min.js" defer></script>
    <script src="/assets/js/corpms.js?v54" defer></script>
    <script src="/assets/js/bm-ui.js?v4" defer></script>
    <script src="/assets/js/crm-select.js?v2" defer></script>
    <script src="/assets/js/lms.js?v6" defer></script>
    <script src="/assets/js/lms-whiteboard.js?v1" defer></script>
    <script src="/assets/js/lms-meeting.js?v1" defer></script>
    <script src="/assets/js/lms-pulse.js?v1" defer></script>
    <script src="/assets/js/ai-panel.js?v18" defer></script>
    <script src="/assets/vendor/echo/pusher.min.js" defer></script>
    <script src="/assets/vendor/echo/echo.iife.js" defer></script>
    <script src="/assets/js/bm-realtime.js?v1" defer></script>
    <link rel="icon" type="image/svg+xml" href="/assets/bm-mark.svg">
    <link rel="stylesheet" href="/assets/css/workspace.css?v31">
    <link rel="stylesheet" href="/assets/css/bumi-tools.css?v2">
    <link rel="stylesheet" href="/assets/css/legal.css?v3">
    <link rel="stylesheet" href="/assets/css/crm-select.css?v3">
    <link rel="stylesheet" href="/assets/css/lms.css?v11">
    @stack('styles')
</head>
<body class="crm-app @yield('body_class')">
<a class="bm-skip-link visually-hidden-focusable" href="#main-content" data-crm-palette-background>{{ __('Перейти к содержанию') }}</a>
<div class="page" data-crm-palette-background>

    @auth
        {{-- счётчик непрочитанных считаем один раз на запрос (раньше 3 раза: navbar + nav-actions ×2) --}}
        @php
            $bellUnread = \App\Models\AppNotification::query()
                ->where('recipient_id', auth()->user()->employee?->id ?? 0)
                ->whereNull('read_at')->count();

            // C3: команды палитры Ctrl+K — тот же permission-фильтр, что и в сайдбаре
            // (строим один раз и переиспользуем в sidebar, чтобы не дублировать запросы прав)
            $navItems = \App\Support\Sidebar::build(auth()->user());
            $paletteCommands = [];
            $paletteSection = '';
            foreach ($navItems as $paletteEntry) {
                $paletteType = $paletteEntry['type'] ?? 'item';
                if ($paletteType === 'section') {
                    $paletteSection = $paletteEntry['label'] ?? '';
                    continue;
                }
                if ($paletteType === 'module') {
                    // модуль открывает свой первый пункт; дети идут отдельными командами
                    $firstChild = $paletteEntry['children'][0] ?? null;
                    if ($firstChild !== null && \Illuminate\Support\Facades\Route::has($firstChild['route'])) {
                        $paletteCommands[] = [
                            'title' => $paletteEntry['title'],
                            'section' => $paletteSection,
                            'icon' => $paletteEntry['icon'] ?? 'circle',
                            'url' => route($firstChild['route']),
                        ];
                    }
                    foreach ($paletteEntry['children'] as $paletteChild) {
                        $paletteCommands[] = [
                            'title' => $paletteChild['title'],
                            'section' => ($paletteSection !== '' ? $paletteSection . ' · ' : '') . $paletteEntry['title'],
                            'icon' => $paletteChild['icon'] ?? 'circle',
                            'url' => \Illuminate\Support\Facades\Route::has($paletteChild['route'])
                                ? route($paletteChild['route'])
                                : '#',
                        ];
                    }
                    continue;
                }
                $paletteCommands[] = [
                    'title' => $paletteEntry['title'],
                    'section' => $paletteSection,
                    'icon' => $paletteEntry['icon'] ?? 'circle',
                    'url' => \Illuminate\Support\Facades\Route::has($paletteEntry['route'])
                        ? route($paletteEntry['route'])
                        : '#',
                ];
            }
            $paletteSearchAllowed = app(\App\Support\PermissionService::class)
                ->check(auth()->user(), 'search.global');
            $bmCurrentRoute = request()->route()?->getName() ?? '';
            $bmActiveRoute = \App\Support\Sidebar::activeRoute($bmCurrentRoute);
            $bmCrumbs = \App\Support\Sidebar::breadcrumbsFor($bmCurrentRoute);
            $bmModule = null;
            if (in_array(explode('.', $bmCurrentRoute, 2)[0], ['tasks', 'attendance', 'crm', 'finance', 'documents', 'workflows'], true)) {
                $bmModule = collect($navItems)->first(fn ($item) => isset($item['children']) &&
                    collect($item['children'])->contains('route', $bmActiveRoute));
            }
        @endphp
        @include('partials.sidebar')
    @endauth

    <div class="page-wrapper">

        @auth
            @include('partials.navbar')
        @endauth

        <main data-workspace="@yield('workspace_layout', 'directory')" class="page-body" id="main-content" tabindex="-1">
            <div class="container-xl">
                @foreach (['success' => 'success', 'status' => 'success', 'error' => 'danger'] as $flashKey => $flashClass)
                    @if (session($flashKey))
                        <div class="alert alert-{{ $flashClass }} alert-dismissible mb-3" role="alert">
                            <div class="d-flex">
                                <div><i class="ti ti-{{ $flashClass === 'danger' ? 'alert-triangle' : 'check' }} icon alert-icon"></i></div>
                                <div>{{ session($flashKey) }}</div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __("Закрыть") }}"></button>
                        </div>
                    @endif
                @endforeach

                {{-- ошибки валидации/бизнес-логики: единый рендер для всех страниц,
                     раньше каждая страница дублировала этот блок сама (11 копий),
                     а ~40 страниц не показывали ошибки вовсе --}}
                @if ($errors->any())
                    <div class="alert alert-danger alert-dismissible mb-3" role="alert" data-test="validation-errors">
                        <div class="d-flex">
                            <div><i class="ti ti-alert-triangle icon alert-icon"></i></div>
                            <div>
                                @if ($errors->count() === 1)
                                    {{ $errors->first() }}
                                @else
                                    <ul class="mb-0 ps-3">
                                        @foreach ($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __("Закрыть") }}"></button>
                    </div>
                @endif

                @stack('breadcrumbs')

                {{-- автокрошки из структуры навигации (детальные страницы добавляют
                     свой уровень через @include('partials.breadcrumbs', [...])) --}}
                @auth
                    @if (empty($bmNoAutoCrumbs) && $bmCrumbs !== null)
                        @include('partials.breadcrumbs', ['bmItems' => array_values(array_filter([
                            ['label' => $bmCrumbs[0]],
                            $bmCrumbs[1] !== null ? ['label' => $bmCrumbs[1]] : null,
                            ['label' => $bmCrumbs[2], 'current' => true],
                        ]))])
                    @endif
                @endauth

                @if (isset($bmModule) && count($bmModule['children']) > 1)
                    <nav class="bm-module-nav d-print-none" aria-label="{{ $bmModule['title'] }}">
                        @foreach ($bmModule['children'] as $bmDestination)
                            <a href="{{ route($bmDestination['route']) }}"
                                @if ($bmDestination['route'] === $bmActiveRoute)
                                    aria-current="{{ $bmCurrentRoute === $bmActiveRoute ? 'page' : 'location' }}"
                                @endif>
                                <i class="ti ti-{{ $bmDestination['icon'] }}" aria-hidden="true"></i>
                                {{ $bmDestination['title'] }}
                            </a>
                        @endforeach
                    </nav>
                @endif

                @yield('content')
            </div>
        </main>

        <footer class="footer">
            <div class="container-xl">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-secondary">
                        <a href="{{ route('privacy') }}" class="text-secondary">{{ __('Конфиденциальность') }}</a> ·
                        <a href="{{ route('terms') }}" class="text-secondary">{{ __('Условия') }}</a> ·
                        <a href="{{ route('cookies') }}" class="text-secondary">Cookie</a>
                    </span>
                    <span class="text-secondary">© {{ date('Y') }}</span>
                </div>
            </div>
        </footer>
    </div>
</div>

@auth
    {{-- Единая панель настроек внешнего вида: оформление + секции экрана --}}
    <div class="offcanvas offcanvas-end crm-offcanvas" tabindex="-1" id="crm-settings" aria-labelledby="crm-settings-title" data-crm-palette-background>
        <div class="offcanvas-header crm-cz__header">
            <div class="crm-cz__badge"><i class="ti ti-palette"></i></div>
            <div class="flex-grow-1">
                <h5 class="offcanvas-title" id="crm-settings-title">{{ __('Оформление') }}</h5>
                <div class="crm-cz__sub">{{ __('Тема, цвет и секции экрана — сохраняется в этом браузере') }}</div>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="{{ __("Закрыть") }}"></button>
        </div>

        <div class="offcanvas-body p-0">
            <div class="crm-cz__body">
                {{-- Тема: карточки-превью --}}
                <div class="crm-cz__section">
                    <div class="crm-cz__label"><i class="ti ti-layout me-1"></i>{{ __('Тема') }}</div>
                    <div class="crm-cz__themes" role="group" aria-label="{{ __("Тема оформления") }}">
                        <label class="crm-cz__theme" for="crm-c-theme-light">
                            <input type="radio" class="crm-cz__input" name="crm-c-theme" id="crm-c-theme-light" value="light" autocomplete="off">
                            <span class="crm-cz__preview crm-cz__preview--light" aria-hidden="true">
                                <span class="crm-cz__pv-bar"></span>
                                <span class="crm-cz__pv-side"></span>
                                <span class="crm-cz__pv-lines"><i></i><i></i><i></i></span>
                            </span>
                            <span class="crm-cz__theme-name"><i class="ti ti-sun"></i>{{ __('Светлая') }}</span>
                            <span class="crm-cz__check"><i class="ti ti-check"></i></span>
                        </label>
                        <label class="crm-cz__theme" for="crm-c-theme-dark">
                            <input type="radio" class="crm-cz__input" name="crm-c-theme" id="crm-c-theme-dark" value="dark" autocomplete="off">
                            <span class="crm-cz__preview crm-cz__preview--dark" aria-hidden="true">
                                <span class="crm-cz__pv-bar"></span>
                                <span class="crm-cz__pv-side"></span>
                                <span class="crm-cz__pv-lines"><i></i><i></i><i></i></span>
                            </span>
                            <span class="crm-cz__theme-name"><i class="ti ti-moon"></i>{{ __('Тёмная') }}</span>
                            <span class="crm-cz__check"><i class="ti ti-check"></i></span>
                        </label>
                    </div>
                </div>

                {{-- Акцент --}}
                <div class="crm-cz__section">
                    <div class="crm-cz__label"><i class="ti ti-droplet me-1"></i>{{ __('Акцентный цвет') }}</div>
                    <div class="crm-cz__accents" id="crm-c-accents">
                        <div class="crm-cz__accent">
                            <button type="button" class="crm-swatch" data-crm-accent="teal" aria-label="{{ __("Бирюзовый") }}" title="{{ __("Бирюзовый") }}" style="--sw:var(--crm-success)"><i class="ti ti-check"></i></button>
                            <span>{{ __('Бирюза') }}</span>
                        </div>
                        <div class="crm-cz__accent">
                            <button type="button" class="crm-swatch" data-crm-accent="blue" aria-label="{{ __("Синий") }}" title="{{ __("Синий") }}" style="--sw:var(--crm-accent)"><i class="ti ti-check"></i></button>
                            <span>{{ __('Синий') }}</span>
                        </div>
                        <div class="crm-cz__accent">
                            <button type="button" class="crm-swatch" data-crm-accent="violet" aria-label="{{ __("Фиолетовый") }}" title="{{ __("Фиолетовый") }}" style="--sw:var(--crm-purple)"><i class="ti ti-check"></i></button>
                            <span>{{ __('Фиолетовый') }}</span>
                        </div>
                        <div class="crm-cz__accent">
                            <button type="button" class="crm-swatch" data-crm-accent="rose" aria-label="{{ __("Розовый") }}" title="{{ __("Розовый") }}" style="--sw:var(--crm-danger)"><i class="ti ti-check"></i></button>
                            <span>{{ __('Розовый') }}</span>
                        </div>
                        <div class="crm-cz__accent">
                            <button type="button" class="crm-swatch" data-crm-accent="amber" aria-label="{{ __("Янтарный") }}" title="{{ __("Янтарный") }}" style="--sw:var(--crm-warning)"><i class="ti ti-check"></i></button>
                            <span>{{ __('Янтарный') }}</span>
                        </div>
                    </div>
                </div>

                {{-- Размер шрифта --}}
                <div class="crm-cz__section mb-0">
                    <div class="crm-cz__label"><i class="ti ti-font-size me-1"></i>{{ __('Размер шрифта') }}</div>
                    <div class="crm-cz__scales" role="group" aria-label="{{ __("Размер шрифта") }}">
                        <label class="crm-cz__scale" for="crm-c-scale-sm">
                            <input type="radio" class="crm-cz__input" name="crm-c-scale" id="crm-c-scale-sm" value="sm" autocomplete="off">
                            <span class="crm-cz__scale-a crm-cz__scale-a--sm">A</span>
                            <span>{{ __('Мелкий') }}</span>
                        </label>
                        <label class="crm-cz__scale" for="crm-c-scale-md">
                            <input type="radio" class="crm-cz__input" name="crm-c-scale" id="crm-c-scale-md" value="md" autocomplete="off">
                            <span class="crm-cz__scale-a">A</span>
                            <span>{{ __('Обычный') }}</span>
                        </label>
                        <label class="crm-cz__scale" for="crm-c-scale-lg">
                            <input type="radio" class="crm-cz__input" name="crm-c-scale" id="crm-c-scale-lg" value="lg" autocomplete="off">
                            <span class="crm-cz__scale-a crm-cz__scale-a--lg">A</span>
                            <span>{{ __('Крупный') }}</span>
                        </label>
                    </div>
                </div>
            </div>

            {{-- Секции экрана: список наполняет corpms.js; скрыто, пока на странице нет секций --}}
            <div class="crm-settings-block" id="crm-sec-block" hidden>
                <div class="crm-cz__label px-3"><i class="ti ti-layout-column me-1"></i>{{ __('Секции экрана') }}</div>
                <ul class="list-group list-group-flush" id="crm-sec-list"></ul>
                <div class="px-3 py-2 text-secondary small">
                    {{ __('Потяните разделители между секциями — ширина сохранится для этой страницы') }}
                </div>
                <div class="px-3 pb-3">
                    <button type="button" class="btn btn-ghost-secondary w-100" data-crm-sec-reset>
                        <i class="ti ti-restore me-1"></i>{{ __('Сбросить настройки экрана') }}
                    </button>
                </div>
            </div>
        </div>

        <div class="offcanvas-footer border-top d-flex gap-2 p-3">
            <button type="button" class="btn btn-ghost-secondary flex-fill" id="crm-c-reset"><i class="ti ti-restore me-1"></i>{{ __('Сбросить оформление') }}</button>
            <button type="button" class="btn btn-primary flex-fill" data-bs-dismiss="offcanvas">{{ __('Готово') }}</button>
        </div>
    </div>

    @if (! request()->routeIs('ai.index', 'chat.index', 'chat.show') && app(\App\Support\PermissionService::class)->check(auth()->user(), 'analytics.ai.use'))
        @include('partials.ai-panel')
    @endif

    {{-- C3: палитра команд Ctrl+K — навигация по разделам + быстрый поиск --}}
    <div class="crm-palette" id="crm-palette" hidden
         data-commands="{{ json_encode($paletteCommands, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) }}"
         data-search-url="{{ $paletteSearchAllowed ? route('search.index') : '' }}">
        <div class="crm-palette__backdrop" data-crm-palette-close></div>
        <div class="crm-palette__dialog" role="dialog" aria-modal="true" aria-label="{{ __("Палитра команд") }}">
            <div class="crm-palette__input-row">
                <i class="ti ti-search crm-palette__icon" aria-hidden="true"></i>
                <input type="text" class="crm-palette__input" id="crm-palette-input"
                       placeholder="{{ __("Перейти к разделу или найти…") }}"
                       autocomplete="off" spellcheck="false"
                       aria-label="{{ __("Поиск команд и разделов") }}" role="combobox"
                       aria-autocomplete="list" aria-haspopup="listbox"
                       aria-controls="crm-palette-list" aria-expanded="true" />
                <kbd class="crm-hint-key">Esc</kbd>
            </div>
            <div class="crm-palette__list" id="crm-palette-list" role="listbox" aria-label="{{ __("Команды") }}"></div>
            <div class="crm-palette__footer">
                <span><span class="crm-hint-key">↑↓</span> {{ __('выбор') }}</span>
                <span><span class="crm-hint-key">Enter</span> {{ __('открыть') }}</span>
                <span><span class="crm-hint-key">Ctrl K</span> {{ __('закрыть') }}</span>
            </div>
        </div>
    </div>
@endauth

        {{-- S9 (аудит): ошибки валидации из модалок/форм — поля подсвечиваются,
             модалка с ошибкой переоткрывается автоматически (раньше после
             редиректа форма закрывалась и ошибку было не видно) --}}
        @if ($errors->any())
            <script data-crm-validation-errors>
                document.addEventListener('DOMContentLoaded', function () {
                    var errors = @json($errors->messages());
                    var preferred = null;
                    try {
                        preferred = sessionStorage.getItem('crm-invalid-modal');
                        sessionStorage.removeItem('crm-invalid-modal');
                    } catch (e) { /* private mode */ }

                    var scope = preferred ? document.getElementById(preferred) : null;
                    if (!scope) { scope = document; }

                    var firstModal = scope !== document && scope.classList.contains('modal') ? scope : null;
                    var all = [];

                    Object.keys(errors).forEach(function (field) {
                        var messages = errors[field] || [];
                        all = all.concat(messages);

                        var el = scope.querySelector('[name="' + field + '"]') || document.querySelector('[name="' + field + '"]');
                        if (!el) { return; }

                        el.classList.add('is-invalid');
                        var next = el.nextElementSibling;
                        if (!next || !next.classList.contains('invalid-feedback')) {
                            var fb = document.createElement('div');
                            fb.className = 'invalid-feedback';
                            fb.textContent = messages.join(' ');
                            el.insertAdjacentElement('afterend', fb);
                        }

                        var modal = el.closest('.modal');
                        if (modal && !firstModal) { firstModal = modal; }
                    });

                    if (firstModal && typeof bootstrap !== 'undefined') {
                        var body = firstModal.querySelector('.modal-body');
                        if (body && !body.querySelector('[data-crm-validation-summary]')) {
                            var summary = document.createElement('div');
                            summary.className = 'alert alert-danger';
                            summary.setAttribute('data-crm-validation-summary', '');
                            summary.textContent = all.join(' ');
                            body.insertBefore(summary, body.firstChild);
                        }
                        bootstrap.Modal.getOrCreateInstance(firstModal).show();
                    }
                });
            </script>
        @endif

<script>
    window.LMS_I18N = Object.assign(window.LMS_I18N || {}, {
        pulseOpen: @json(__('Открыть курс'))
    });
</script>
@stack('scripts')
</body>
</html>
