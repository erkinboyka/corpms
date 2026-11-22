@php
    $groups = [];
    $current = request()->route()?->getName();
    $active = \App\Support\Sidebar::activeRoute($current);
    foreach ($navItems as $entry) {
        if ($entry['type'] === 'section') {
            $section = $entry['label'];
            $groups[$section] = ['active' => false, 'icon' => $entry['icon'] ?? 'folder', 'entries' => []];
            continue;
        }
        $groups[$section]['entries'][] = $entry;
        $links = $entry['children'] ?? [$entry];
        if (collect($links)->contains('route', $active)) {
            $groups[$section]['active'] = true;
        }
    }
@endphp
<aside class="bm-dock d-print-none" id="bm-sidebar" aria-label="{{ __("Навигация по модулям") }}">
    <div class="bm-dock__brand">
        <a href="{{ route('welcome') }}" class="bm-dock__brand-link" aria-label="{{ __("BM Systems — главная") }}">
            @include('partials.workspace-mark')
            <strong>BM Systems</strong>
        </a>
        <button class="bm-dock__mobile" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-expanded="false" aria-controls="sidebar-menu" aria-label="{{ __("Меню") }}"><i class="ti ti-menu-2"></i></button>
    </div>
    <div class="bm-dock__mobile-actions">@include('partials.nav-actions', ['themeId' => null, 'showName' => false])</div>
    <div class="collapse bm-dock__body" id="sidebar-menu">
        <button type="button" class="bm-dock__find" data-crm-palette-open aria-label="{{ __("Найти раздел") }}"><i class="ti ti-search"></i><span>{{ __('Найти раздел') }}</span><kbd>Ctrl K</kbd></button>
        <nav aria-label="{{ __("Разделы платформы") }}">
            @foreach ($groups as $label => $group)
                <details class="bm-dock__group" @if ($group['active']) open @endif>
                    <summary aria-label="{{ $label }}" title="{{ $label }}"><i class="ti ti-{{ $group['icon'] ?? 'folder' }}"></i><span>{{ $label }}</span><i class="ti ti-chevron-down"></i></summary>
                    <div class="bm-dock__links">
                        @foreach ($group['entries'] as $entry)
                            @if (isset($entry['children']) && count($group['entries']) > 1)
                                <div class="bm-dock__caption">@if (!empty($entry['icon']))<i class="ti ti-{{ $entry['icon'] }}" aria-hidden="true"></i> @endif{{ $entry['title'] }}</div>
                            @endif
                            @foreach ($entry['children'] ?? [$entry] as $link)
                                <a href="{{ route($link['route']) }}" @if ($link['route'] === $active) aria-current="{{ $current === $active ? 'page' : 'location' }}" @endif>{{ $link['title'] }}</a>
                            @endforeach
                        @endforeach
                    </div>
                </details>
            @endforeach
        </nav>
    </div>
    <button type="button" class="bm-dock__backdrop" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-label="{{ __("Закрыть меню") }}" tabindex="-1"></button>
    <button type="button" class="bm-dock__rail" data-bm-sidebar-collapse aria-label="{{ __("Компактное меню") }}" aria-pressed="false" title="{{ __("Компактное меню (Ctrl+B)") }}"><i class="ti ti-layout-sidebar"></i><span>{{ __('Компактное меню') }}</span></button>
</aside>
