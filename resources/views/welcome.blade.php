<!doctype html>
<html lang="ru" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ __('Познакомьтесь с BM Systems. Пройдите рабочий день от отметки о приходе до отчёта руководителя в интерактивной демонстрации') }}">
    <title>{{ __('BM Systems — рабочее пространство компании') }}</title>
    <link rel="icon" href="/assets/bm-mark.svg" type="image/svg+xml">
    <link rel="preload" href="/assets/vendor/fonts/inter-cyrillic-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    <script>
        try {
            const theme = localStorage.getItem('crm-theme');
            document.documentElement.dataset.bsTheme = ['light','dark'].includes(theme) ? theme : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        } catch {}
    </script>
    <link rel="stylesheet" href="/assets/css/tokens.css?v34">
    <link rel="stylesheet" href="/assets/css/motion.css?v5">
    <link rel="stylesheet" href="/assets/css/landing.css?v=5">
    <link rel="stylesheet" href="/assets/css/landing-tour.css?v=6">
    <link rel="stylesheet" href="/assets/css/bumi-persona.css?v4">
    @include('partials.bumi-i18n')
    <script src="/assets/js/bumi-persona.js?v4" defer></script>
    <script type="module" src="/assets/js/landing.js?v=6"></script>
</head>
<body class="bm-landing" id="top"
      @if(isset($bumi)) data-bumi-mood="{{ $bumi->mood->value }}" data-bumi-line="{{ $bumi->line }}" data-bumi-reason="{{ $bumi->reason }}" @endif>
@php
    $entry = auth()->check() ? route('dashboard') : route('login');
    $enterLabel = auth()->check() ? __('Рабочий стол') : __('Войти');
    $stages = [
        [__('Посещаемость'),'09:00','fingerprint',__('Начало смены'),__('Анна пришла в офис. Отметьте её приход в журнале посещаемости.'),__('Присутствие'),__('Анна Соколова'),__('Ожидает отметки'),__('Отметить приход'),__('В офисе · 09:04')],
        [__('Задачи'),'10:30','layout-kanban',__('От плана к делу'),__('Возьмите задачу по подготовке запуска. У команды сразу появится её новый статус.'),__('Доска проекта'),__('Подготовить запуск'),__('Запланирована'),__('Взять в работу'),__('В работе')],
        [__('Документы'),'14:15','file-check',__('На подпись'),__('Смета прошла проверку. Согласуйте её и передайте на подпись руководителю.'),__('Маршрут документа'),__('Смета проекта.pdf'),__('На согласовании'),__('Согласовать смету'),__('На подписи')],
        [__('Аналитика'),'18:00','chart-bar',__('Итоги дня'),__('Соберите сводку и посмотрите, какие действия команда успела выполнить в демо.'),__('Сводка дня'),__('Отчёт по проекту'),__('Черновик'),__('Собрать сводку'),__('Сводка собрана')],
    ];
    // Фразы Bumi и динамические подписи: JS читает уже переведённые строки.
    $landingI18n = [
        'captionCore' => __('Ядро в сборе'),
        'captionLinks' => __('Связи модулей'),
        'guideLabel' => __('Бими объясняет проект'),
        'chapters' => [
            ['hello', 'wink', __('Я Бими. Покажу, как связаны люди, задачи и документы.')],
            ['overview', 'curious', __('Я вижу связи между модулями. Выберите кольцо, чтобы открыть его в демо.')],
            ['arrival', 'happy', __('Начнём со смены. Я покажу, кто в офисе и кто работает удалённо.')],
            ['tasks', 'thinking', __('Возьмите задачу. Я замечу смену статуса и учту её в сводке.')],
            ['documents', 'curious', __('У сметы есть маршрут. Согласуйте её, и я покажу следующий этап.')],
            ['report', 'proud', __('Здесь я собираю ваши действия за день в один отчёт.')],
            ['assistant', 'curious', __('Теперь моя очередь. Спросите, что сделано, и я сверюсь с этим демо.')],
            ['people', 'wink', __('В оргструктуре видно, кто за что отвечает. Раскройте отдел.')],
            ['analytics', 'surprised', __('Смотрите, как менялась нагрузка. Выберите неделю и день.')],
            ['security', 'angry', __('Меня не пускают без прав — и это правильно. Здесь видно, кто что может.')],
            ['finish', 'proud', __('Мы прошли рабочий день. Войдите, чтобы открыть своё пространство.')],
        ],
        'answers' => [
            'bothOnShift' => __('На смене оба сотрудника. Анна в офисе с 09:04, Фарид работает удалённо.'),
            'faridRemote' => __('Фарид работает удалённо. У Анны пока нет отметки о приходе.'),
            'demoPrefix' => __('По данным демо:'),
            'dayStarted' => __('День только начался. Отметьте приход или возьмите задачу в демо выше.'),
            'estimateSigned' => __('Смета передана на подпись.'),
            'estimateWaiting' => __('Смета проекта ждёт согласования.'),
            'teamWorking' => __('Команда уже работает над запуском.'),
            'taskPlanned' => __('Задача по подготовке запуска запланирована.'),
        ],
        'events' => [
            __('Анна отметила приход'),
            __('задача по запуску взята в работу'),
            __('смета передана на подпись'),
            __('сводка собрана'),
        ],
        'reactions' => [
            'checked' => __('Я сверился с действиями в демо. Ответ уже в диалоге.'),
            'department' => __('Вот руководитель и сотрудники этого отдела.'),
            'steps' => [
                __('Анна в офисе. В журнале появилась её отметка.'),
                __('Готово! Задача переместилась в работу.'),
                __('Смета согласована. Теперь она ждёт подписи.'),
                __('Сводка готова. В ней учтены ваши действия.'),
            ],
        ],
        'chartDone' => __('Завершено задач:'),
        'accessLines' => [
            __('Можно открыть карточку и прочитать документ.'),
            __('Можно принять решение по документу на согласовании.'),
        ],
        'accessTitles' => [
            __('Чтение документов'),
            __('Решение по документу'),
        ],
    ];
@endphp
<script type="application/json" id="landing-i18n">{!! json_encode($landingI18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<a class="skip" href="#main">{{ __('К содержанию') }}</a>
<header class="mast">
    <div class="wrap mast-inner">
        <a class="brand" href="{{ route('welcome') }}">@include('partials.workspace-mark')<b>BM Systems</b></a>
        <nav class="main-nav" aria-label="{{ __("Разделы лендинга") }}">
            <a href="#ecosystem">{{ __('Платформа') }}</a><a href="#demo">{{ __('Демо') }}</a><a href="#bumi">Bumi AI</a>
        </nav>
        <div class="mast-actions">
            <a class="button mast-login" href="{{ $entry }}">{{ $enterLabel }} <i class="ti ti-arrow-up-right" aria-hidden="true"></i></a>
            <button type="button" class="menu-btn" aria-label="{{ __("Меню") }}" aria-expanded="false" aria-controls="mobile-nav" hidden><i class="ti ti-menu-2"></i></button>
        </div>
    </div>
    <nav class="mobile-nav wrap" id="mobile-nav" aria-label="{{ __("Разделы на телефоне") }}" hidden>
        <a href="#ecosystem">{{ __('Платформа') }}</a><a href="#demo">{{ __('Демо') }}</a><a href="#bumi">Bumi AI</a><a href="#people">{{ __('Команда') }}</a>
    </nav>
</header>
<main id="main" data-landing>
    <section class="overture" aria-labelledby="landing-title">
        <div class="hero-stick">
            <div class="wrap hero-grid">
                <div class="hero-text">
                    <div class="hero-intro">
                        <h1 id="landing-title">{{ __('Ваша компания') }}<br><em>{{ __('Одна система') }}</em></h1>
                        <p>{{ __('Люди, задачи и документы связаны в одном рабочем пространстве. Bumi помогает увидеть, что требует внимания') }}</p>
                        <div class="hero-actions">
                            <a class="button primary" href="#ecosystem">{{ __('Изучить платформу') }} <i class="ti ti-arrow-down-right"></i></a>
                            <a class="text-link" href="{{ $entry }}">{{ $enterLabel }} <i class="ti ti-arrow-up-right"></i></a>
                        </div>
                    </div>
                    <div class="hero-chapter" id="ecosystem">
                        <h2>{{ __('От команды') }}<br><em>{{ __('до отчёта') }}</em></h2>
                        <p>{{ __('Отметка сотрудника открывает день. Задачи ведут к документам, а решения попадают в отчёт. Выберите модуль и попробуйте его в деле') }}</p>
                        <div class="module-list" aria-label="{{ __("Модули платформы") }}">
                            @foreach ($stages as $index => $stage)
                                <a href="#landing-panel-{{ $index }}" data-module="{{ $index }}"><i class="ti ti-{{ $stage[2] }}"></i>{{ $stage[0] }}<i class="ti ti-arrow-down-right"></i></a>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="assembly" data-landing-scene>
                    <div class="assembly-field" aria-hidden="true"></div>
                    <div class="assembly-shadow" aria-hidden="true"></div>
                    <img class="assembly-poster" src="/assets/landing/core.webp?v=3" width="720" height="720" alt="" fetchpriority="high">
                    <img class="exploded-poster" data-exploded-src="/assets/landing/modules.webp?v=3" width="720" height="720" alt="" hidden>
                    <canvas data-landing-canvas aria-hidden="true"></canvas>
                    <button type="button" class="core-guide" data-landing-bumi aria-label="{{ __("Подсказка от Bumi") }}" aria-expanded="false" aria-controls="core-quote" hidden>
                        @include('partials.auth-mascot')
                    </button>
                    <noscript><img class="final-guide" src="/assets/bm-mascot-mini.svg" alt="{{ __("Bumi в центре системы") }}" width="100" height="100"></noscript>
                    <p class="core-quote" id="core-quote" role="status" hidden>{{ __('Я Bumi. Прокрутите страницу, чтобы разобрать ядро на модули') }}</p>
                    <div class="hotspots" aria-label="{{ __("Модули на схеме") }}" inert>
                        @foreach ($stages as $index => $stage)
                            <a href="#landing-panel-{{ $index }}" data-module="{{ $index }}" aria-label="{{ $stage[0] }}. {{ $stage[4] }}"><i class="ti ti-{{ $stage[2] }}"></i><span>{{ $stage[0] }}</span></a>
                        @endforeach
                    </div>
                    <span class="drawing-label" aria-hidden="true"><i></i><span data-drawing-label>{{ __('Ядро в сборе') }}</span></span>
                </div>
            </div>
            <div class="hero-foot wrap">
                <a class="scroll-cue" href="#demo"><span aria-hidden="true">↓</span> {{ __('К рабочему дню') }}</a>
                <div class="assembly-scale" aria-hidden="true"><span>01</span><i></i><span>02</span></div>
                <span class="drawing-caption">BM Systems <b>/</b> {{ __('Архитектура платформы') }}</span>
            </div>
        </div>
    </section>
    <section class="day wrap" aria-labelledby="demo-title" id="demo">
        <div class="day-frame">
        <div class="day-header">
            <h2 id="demo-title">{{ __('Рабочий день компании') }}<span>.</span></h2>
            <span class="demo-note"><i></i> {{ __('Интерактивное демо') }}</span>
        </div>
        <div class="day-layout">
            <div class="day-track">
                @foreach ($stages as $index => $stage)
                    <article class="day-step" data-demo-panel="{{ $index }}" id="landing-panel-{{ $index }}">
                        <div class="time"><span></span>{{ $stage[1] }} <b>/</b> {{ $stage[0] }}</div>
                        <h3>{{ $stage[3] }}</h3><p>{{ $stage[4] }}</p>
                        <button class="button demo-action" data-demo-action="{{ $index }}" data-result="{{ $stage[9] }}" type="button" hidden>{{ $stage[8] }} <i class="ti ti-arrow-right"></i></button>
                        <span class="result" role="status" data-receipt></span>
                    </article>
                @endforeach
            </div>
            <div class="workbench">
                <div class="workbench-top">@include('partials.workspace-mark')<span>BM Workspace</span><small>{{ __('Демо-данные') }}</small></div>
                <div class="workbench-layout">
                    <nav aria-label="{{ __("Сценарии рабочего дня") }}" class="workbench-rail">
                        @foreach ($stages as $index => $stage)
                            <a href="#landing-panel-{{ $index }}" data-demo-link="{{ $index }}" aria-label="{{ $stage[0] }}" title="{{ $stage[0] }}"><i class="ti ti-{{ $stage[2] }}"></i><span>{{ $stage[0] }}</span></a>
                        @endforeach
                    </nav>
                    <div class="workbench-body">
                        @foreach ($stages as $index => $stage)
                            <div class="screen" data-screen="{{ $index }}" @if (!$loop->first) hidden @endif>
                                <div class="screen-caption">{{ $stage[0] }} <span>/</span> {{ __('Сегодня') }}</div>
                                <h4>{{ $stage[5] }}</h4>
                                @if ($index === 0)
                                    <div class="shift-dial" aria-hidden="true"><i></i><b></b><span></span></div>
                                    <div class="readout"><strong data-attendance>1</strong><span>{{ __('из 2 сотрудников') }}<br>{{ __('начали смену') }}</span></div>
                                    <div class="person"><b>АС</b><span>{{ __('Анна Соколова') }}<small>{{ __('Проектный отдел') }}</small></span><em data-status>{{ $stage[7] }}</em></div>
                                    <div class="person"><b>ФР</b><span>{{ __('Фарид Рахимов') }}<small>{{ __('Операционный отдел') }}</small></span><em>{{ __('Удалённо') }}</em></div>
                                @elseif ($index === 1)
                                    <div class="kanban">
                                        <div><small>{{ __('Запланированы') }}</small><div class="task-slip" data-task><i></i><strong>{{ $stage[6] }}</strong><span>{{ __('Анна Соколова') }}</span></div></div>
                                        <div data-in-progress><small>{{ __('В работе') }}</small></div>
                                    </div><span class="sr" data-status>{{ $stage[7] }}</span>
                                @elseif ($index === 2)
                                    <div class="paper"><i class="ti ti-file-description"></i><strong>{{ $stage[6] }}</strong><span>{{ __('Проектный отдел · версия 2') }}</span><i></i><i></i><b class="approval-stamp">{{ __('Согласовано') }}</b></div>
                                    <div class="doc-state"><span>{{ __('Этап маршрута') }}</span><em data-status>{{ $stage[7] }}</em></div>
                                @else
                                    <div class="readout"><strong data-completed>0</strong><span>{{ __('из 3 действий') }}<br>{{ __('выполнено в демо') }}</span></div>
                                    <div class="summary-bars" aria-hidden="true"><i></i><i></i><i></i></div>
                                    <div class="doc-state"><span>{{ __('Отчёт по проекту') }}</span><em data-status>{{ $stage[7] }}</em></div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        <button type="button" class="replay" data-replay hidden><i class="ti ti-rotate"></i> {{ __('Пройти демо ещё раз') }}</button>
        </div>
    </section>
    <section class="intelligence" id="bumi" aria-labelledby="bumi-title">
        <div class="wrap ai-grid">
            <div>
                <div class="assistant-name"><img src="/assets/bm-mascot-mini.svg" alt="" width="40" height="40"> Bumi AI</div>
                <h2 id="bumi-title">{{ __('Спросите') }}<br><em>{{ __('у') }} Bumi.</em></h2>
                <p>{{ __('Выберите вопрос. Bumi сверится с задачами и действиями из вашего демо') }}</p>
            </div>
            <div class="conversation">
                <div class="conv-head"><span><i></i> Bumi</span><small>{{ __('Демо-диалог') }}</small></div>
                <p class="user-line" data-question>{{ __('Что требует внимания?') }}</p>
                <p class="bot-line" data-answer aria-live="polite">{{ __('Смета проекта ждёт согласования. Задача по подготовке запуска запланирована') }}</p>
                <div class="prompts" aria-label="{{ __("Вопросы для Bumi") }}">
                    @foreach ([__('Что требует внимания?'), __('Кто на смене?'), __('Что сделано за день?')] as $question)
                        <button type="button" data-prompt="{{ $loop->index }}" @if ($loop->first) aria-pressed="true" @else aria-pressed="false" @endif>{{ $question }}</button>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
    <section class="people wrap editorial" id="people" aria-labelledby="people-title">
        <div><h2 id="people-title">{{ __('Кто за что') }}<br><em>{{ __('отвечает') }}</em></h2><p>{{ __('Раскройте отдел, чтобы увидеть его руководителя и команду') }}</p></div>
        <div class="org-map">
            <div class="org-root"><i class="ti ti-building"></i><span>{{ __('Компания') }}</span><small>{{ __('Демо') }}</small></div>
            @foreach ([[__('Проектный отдел'),__('Анна Соколова'),__('Менеджер проектов'),__('Ирина Ким'),__('Дизайнер')],[__('Операционный отдел'),__('Фарид Рахимов'),__('Руководитель отдела'),__('Олег Орлов'),__('Координатор')]] as $team)
                <details class="department" @if ($loop->first) open @endif>
                    <summary><i class="ti ti-sitemap"></i>{{ $team[0] }}<i class="ti ti-chevron-down"></i></summary>
                    <ul><li><b>{{ $team[1] }}</b><span>{{ $team[2] }}</span></li><li><b>{{ $team[3] }}</b><span>{{ $team[4] }}</span></li></ul>
                </details>
            @endforeach
        </div>
    </section>
    <section class="insights wrap editorial" id="analytics" aria-labelledby="analytics-title">
        <div><h2 id="analytics-title">{{ __('Ритм') }}<br><em>{{ __('команды') }}</em></h2><p>{{ __('Сравните завершённые задачи за две недели. Выберите день для точного значения') }}</p></div>
        <figure class="chart">
            <figcaption>{{ __('Завершённые задачи') }} <small>{{ __('Демо-данные') }}</small></figcaption>
            <div class="chart-controls"><div><strong data-chart-total>42</strong><span> {{ __('за неделю') }}</span></div>
                <div class="periods" role="group" aria-label="{{ __("Период") }}"><button type="button" data-period="0" aria-pressed="true">{{ __('Эта') }}</button><button type="button" data-period="1" aria-pressed="false">{{ __('Прошлая') }}</button></div>
            </div>
            <div class="bars" aria-label="{{ __("Задачи по дням") }}">
                @foreach ([__('Пн')=>5,__('Вт')=>9,__('Ср')=>7,__('Чт')=>11,__('Пт')=>10] as $name=>$count)
                    <button type="button" data-bar data-day="{{ $name }}" data-count="{{ $count }}" aria-label="{{ $name }} — {{ $count }}" style="--height:{{ $count / 12 * 100 }}%"><i></i><span>{{ $name }}</span></button>
                @endforeach
            </div><p data-chart-detail role="status">{{ __('Чт · 11 завершённых задач') }}</p>
        </figure>
    </section>
    <section class="trust wrap" id="security"><div><h2>{{ __('Доступ и контроль') }}</h2>
        <div class="access-pass">
            <div class="pass-head">@include('partials.workspace-mark')<span>{{ __('Пропуск в демо') }}</span></div>
            <strong data-pass-title>{{ __('Чтение документов') }}</strong>
            <div class="pass-options" role="group" aria-label="{{ __("Пример разрешения") }}">
                <button type="button" data-access="0" aria-pressed="true">{{ __('Просмотр') }}</button>
                <button type="button" data-access="1" aria-pressed="false">{{ __('Согласование') }}</button>
            </div>
            <p data-pass-scope role="status">{{ __('Можно открыть карточку и прочитать документ') }}</p>
        </div></div><div>
        <details open><summary>{{ __('Роли и разрешения') }} <i class="ti ti-plus"></i></summary><p>{{ __('Права роли определяют доступ к модулям и действиям. Для просмотра и согласования документов предусмотрены отдельные разрешения') }}</p></details>
        <details><summary>{{ __('Журнал действий') }} <i class="ti ti-plus"></i></summary><p>{{ __('Сотрудники с правом просмотра аудита могут открыть журнал действий своей компании') }}</p></details>
    </div></section>
    <section class="close-scene" aria-labelledby="close-title">
        <div class="close-art" aria-hidden="true">
            <img class="assembly-poster" src="/assets/landing/core.webp?v=3" alt="" loading="lazy" width="720" height="720">
            <img class="final-guide" src="/assets/bm-mascot-mini.svg" alt="" loading="lazy" width="100" height="100">
        </div>
        <div class="wrap"><h2 id="close-title">{{ __('Ваше рабочее') }}<br><em>{{ __('пространство') }}</em></h2><a class="button primary" href="{{ $entry }}">{{ auth()->check() ? __('Открыть рабочий стол') : __('Войти в BM Systems') }} <i class="ti ti-arrow-up-right"></i></a></div>
    </section>
</main>
<footer class="wrap footer"><a class="brand" href="{{ route('welcome') }}">@include('partials.workspace-mark')<b>BM Systems</b></a><a href="#top">{{ __('Наверх') }} <i class="ti ti-arrow-up"></i></a></footer>
@if (config('corpms.service_worker.enabled'))
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
        });
    }
</script>
@endif
</body>
</html>
