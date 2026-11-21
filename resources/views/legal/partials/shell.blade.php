{{--
    Единая оболочка для юридических страниц: приватность (махфият), cookie, условия.
    Переменные: $active, $title, $lead, $icon, $sections, $updated
--}}
@php
    $docs = [
        'privacy' => ['route' => 'privacy', 'title' => __('Политика конфиденциальности'), 'icon' => 'shield-lock', 'hint' => __('Как мы работаем с данными')],
        'cookies' => ['route' => 'cookies', 'title' => __('Политика cookie'), 'icon' => 'cookie', 'hint' => __('Файлы в браузере')],
        'terms'   => ['route' => 'terms', 'title' => __('Условия использования'), 'icon' => 'file-text', 'hint' => __('Правила работы в системе')],
    ];
@endphp

<div class="container-xl py-4 legal-shell">
    {{-- Hero --}}
    <header class="legal-hero">
        <span class="legal-hero__eyebrow"><i class="ti ti-{{ $icon }}"></i> {{ __('Правовая информация') }}</span>
        <h1>{{ $title }}</h1>
        <p class="legal-hero__lead">{{ $lead }}</p>
        <div class="legal-hero__meta">
            <span><i class="ti ti-calendar-event"></i> {{ __('Обновлено') }}: {{ $updated }}</span>
            <span><i class="ti ti-file-text"></i> {{ __('Документы') }} · BM Systems</span>
        </div>
    </header>

    <div class="legal-grid">
        {{-- Боковая навигация --}}
        <aside class="legal-side">
            <nav class="legal-nav" aria-label="{{ __('Навигация по документам') }}">
                <div class="legal-nav__title">{{ __('Документы') }}</div>
                <div class="legal-nav__grid">
                    @foreach ($docs as $key => $doc)
                        <a class="legal-nav__link {{ $active === $key ? 'is-active' : '' }}"
                           href="{{ route($doc['route']) }}"
                           @if ($active === $key) aria-current="page" @endif>
                            <i class="ti ti-{{ $doc['icon'] }}" aria-hidden="true"></i>
                            <span>{{ $doc['title'] }}<small>{{ $doc['hint'] }}</small></span>
                        </a>
                    @endforeach
                </div>

                <div class="legal-toc">
                    <div class="legal-toc__title">{{ __('Содержание') }}</div>
                    @foreach ($sections as $section)
                        <a href="#{{ $section['id'] }}">{{ $section['title'] }}</a>
                    @endforeach
                </div>
            </nav>
        </aside>

        {{-- Текст документа --}}
        <main class="legal-main">
            @foreach ($sections as $index => $section)
                <section class="legal-section" id="{{ $section['id'] }}">
                    <div class="legal-section__head">
                        <span class="legal-section__icon"><i class="ti ti-{{ $section['icon'] }}" aria-hidden="true"></i></span>
                        <div>
                            <div class="legal-section__num">{{ __('Раздел') }} {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</div>
                            <h2>{{ $section['title'] }}</h2>
                        </div>
                    </div>
                    @foreach ($section['body'] as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                    @if (! empty($section['note']))
                        <div class="legal-section__note"><i class="ti ti-info-circle me-1"></i>{{ $section['note'] }}</div>
                    @endif
                </section>
            @endforeach

            <footer class="legal-foot">
                <span class="legal-foot__updated"><i class="ti ti-history"></i> {{ __('Обновлено') }}: {{ $updated }}</span>
                <div class="legal-foot__links">
                    @foreach ($docs as $key => $doc)
                        @if ($key !== $active)
                            <a href="{{ route($doc['route']) }}"><i class="ti ti-{{ $doc['icon'] }}"></i>{{ $doc['title'] }}</a>
                        @endif
                    @endforeach
                    <a href="{{ route('welcome') }}"><i class="ti ti-home"></i>{{ __('На главную') }}</a>
                </div>
            </footer>
        </main>
    </div>
</div>
