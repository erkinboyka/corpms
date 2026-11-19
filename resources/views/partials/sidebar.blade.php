@php
    $groups = [];
    $current = request()->route()?->getName();
    $active = \App\Support\Sidebar::activeRoute($current);
    $icons = ['Обзор' => 'layout-dashboard', 'Работа' => 'briefcase', 'Люди' => 'users', 'Клиенты' => 'address-book', 'Операции' => 'package', 'Финансы' => 'wallet', 'Документы' => 'files', 'Аналитика' => 'chart-bar', 'Администрирование' => 'adjustments'];
    foreach ($navItems as $entry) {
        if ($entry['type'] === 'section') {
            $section = $entry['label'];
            $groups[$section] = ['active' => false, 'entries' => []];
            continue;
        }
        $groups[$section]['entries'][] = $entry;
        $links = $entry['children'] ?? [$entry];
        if (collect($links)->contains('route', $active)) {
            $groups[$section]['active'] = true;
        }
    }
@endphp
<aside class="bm-dock d-print-none" id="bm-sidebar" aria-label="Навигация по модулям">
    <div class="bm-dock__brand">
        @include('partials.workspace-mark')
        <strong>BM Systems</strong>
        <button class="bm-dock__mobile" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-expanded="false" aria-controls="sidebar-menu" aria-label="Меню"><i class="ti ti-menu-2"></i></button>
    </div>
    <div class="bm-dock__mobile-actions">@include('partials.nav-actions', ['themeId' => null, 'showName' => false])</div>
    <div class="collapse bm-dock__body" id="sidebar-menu">
        <button type="button" class="bm-dock__find" data-crm-palette-open aria-label="Найти раздел"><i class="ti ti-search"></i><span>Найти раздел</span><kbd>Ctrl K</kbd></button>
        <nav aria-label="Разделы платформы">
            @foreach ($groups as $label => $group)
                <details class="bm-dock__group" @if ($group['active']) open @endif>
                    <summary aria-label="{{ $label }}" title="{{ $label }}"><i class="ti ti-{{ $icons[$label] ?? 'folder' }}"></i><span>{{ $label }}</span><i class="ti ti-chevron-down"></i></summary>
                    <div class="bm-dock__links">
                        @foreach ($group['entries'] as $entry)
                            @if (isset($entry['children']) && count($group['entries']) > 1)
                                <div class="bm-dock__caption">{{ $entry['title'] }}</div>
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
    <button type="button" class="bm-dock__backdrop" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-label="Закрыть меню" tabindex="-1"></button>
    <button type="button" class="bm-dock__rail" data-bm-sidebar-collapse aria-label="Компактное меню" aria-pressed="false" title="Компактное меню (Ctrl+B)"><i class="ti ti-layout-sidebar"></i><span>Компактное меню</span></button>
</aside>
