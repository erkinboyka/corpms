<?php

use Illuminate\Support\Facades\Route;

// $navItems считает layout (общий для сайдбара и палитры Ctrl+K) — не дублируем
$items = $navItems ?? \App\Support\Sidebar::build(auth()->user());
$active = auth()->check() ? request()->route()->getName() : '';

/* группируем плоский список по секциям: заголовок + его пункты */
$groups = [];
$pendingLabel = null;
foreach ($items as $item) {
    if ($item['type'] === 'section') {
        $pendingLabel = $item['label'];
        continue;
    }
    if ($pendingLabel !== null) {
        $groups[] = ['label' => $pendingLabel, 'items' => []];
        $pendingLabel = null;
    }
    if ($groups === []) {
        $groups[] = ['label' => '', 'items' => []];
    }
    $groups[count($groups) - 1]['items'][] = $item;
}
?>
<aside class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="light">
    <div class="container-fluid px-2">
        <h1 class="navbar-brand mb-0">
            <i class="ti ti-building-castle me-1"></i>CorpMS
        </h1>

        <div class="d-flex align-items-center d-lg-none ms-auto pe-1">
            {{-- на мобильных всё управление — в одной строке с брендом и бургером --}}
            @include('partials.nav-actions', ['themeId' => null, 'showName' => false])
            <button class="navbar-toggler ms-1" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-controls="sidebar-menu" aria-expanded="false" aria-label="Меню">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>

        <div class="collapse navbar-collapse" id="sidebar-menu">
            <ul class="navbar-nav pt-lg-3">
                @foreach ($groups as $group)
                    @php
                        $groupActive = collect($group['items'])->contains(fn ($i) => $i['route'] === $active);
                    @endphp
                    <li class="nav-item crm-nav-group{{ $groupActive ? ' is-active-group' : '' }}" data-nav-group>
                        @if ($group['label'] !== '')
                            <button type="button" class="crm-nav-group__head" data-nav-group-toggle aria-expanded="true">
                                <span class="crm-nav-group__label">{{ $group['label'] }}</span>
                                <i class="ti ti-chevron-down crm-nav-group__chev" aria-hidden="true"></i>
                            </button>
                        @endif
                        <ul class="navbar-nav crm-nav-group__items">
                            @foreach ($group['items'] as $item)
                                <li class="nav-item {{ $active === $item['route'] ? 'active' : '' }}">
                                    <a class="nav-link" href="{{ \Illuminate\Support\Facades\Route::has($item['route']) ? route($item['route']) : '#' }}">
                                        <span class="nav-link-title">
                                            <i class="ti ti-{{ $item['icon'] }} me-2"></i>{{ $item['title'] }}
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</aside>
