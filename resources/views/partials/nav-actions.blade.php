@php
    $navEmployee = auth()->user()->employee;
    $navInitials = mb_strtoupper(mb_substr($navEmployee->first_name ?? '', 0, 1) . mb_substr($navEmployee->last_name ?? '', 0, 1));
    // переиспользуем счётчик из layout/navbar, чтобы не выполнять запрос повторно
    $navUnreadBell = $bellUnread ?? \App\Models\AppNotification::query()
        ->where('recipient_id', $navEmployee?->id ?? 0)
        ->whereNull('read_at')->count();
@endphp
{{-- переключатель темы: класс .crm-theme-toggle вместо жёсткого id — блок используется
     в двух местах (верхний бренд-бар на мобиле и Header на десктопе) --}}
<div class="nav-item">
    <button type="button" @if (!empty($themeId)) id="{{ $themeId }}" @endif class="btn btn-icon btn-ghost-secondary crm-theme-toggle" aria-label="Переключить тему" title="Светлая/тёмная тема">
        <i class="ti ti-moon crm-theme-icon-dark"></i>
        <i class="ti ti-sun crm-theme-icon-light"></i>
    </button>
</div>

@if (app(\App\Support\PermissionService::class)->check(auth()->user(), 'settings.view'))
    <div class="nav-item">
        <a href="{{ route('settings.index') }}" class="btn btn-icon btn-ghost-secondary" aria-label="Настройки системы" title="Настройки системы">
            <i class="ti ti-tool"></i>
        </a>
    </div>
@endif

{{-- настройки интерфейса (оформление, секции экрана, AI) — одна сворачиваемая панель --}}
<div class="nav-item">
    <button type="button" class="btn btn-icon btn-ghost-secondary" data-bs-toggle="offcanvas" data-bs-target="#crm-settings"
            aria-controls="crm-settings" aria-label="Настройки интерфейса" title="Настройки интерфейса">
        <i class="ti ti-settings"></i>
    </button>
</div>

<div class="nav-item">
    <button type="button" class="nav-link px-0 position-relative" data-bs-toggle="offcanvas" data-bs-target="#crm-bell" aria-controls="crm-bell" title="Уведомления">
        <i class="ti ti-bell"></i>
        @if ($navUnreadBell > 0)<span class="badge bg-red position-absolute top-0 end-0 translate-middle">{{ $navUnreadBell }}</span>@endif
    </button>
</div>

{{-- C3: палитра Ctrl+K — кнопка видна и на 360px, где топбар с поиском скрыт --}}
<div class="nav-item">
    <button type="button" class="nav-link px-0" data-crm-palette-open
            aria-label="Палитра команд (Ctrl+K)" title="Команды и поиск — Ctrl+K">
        <i class="ti ti-command"></i>
    </button>
</div>

<div class="nav-item dropdown">
    <a href="#" class="nav-link d-flex lh-1 text-reset p-0 px-2" data-bs-toggle="dropdown">
        @if ($navEmployee?->photo_path)
            <span class="avatar avatar-sm avatar-rounded">
                <img src="/storage/{{ ltrim($navEmployee->photo_path, '/') }}" alt="" class="avatar-img">
            </span>
        @else
            <span class="avatar avatar-sm avatar-rounded bg-blue-lt">{{ $navInitials !== '' ? $navInitials : 'CM' }}</span>
        @endif
        @if (!empty($showName))
            <div class="d-none d-xl-block ps-2">
                <div>{{ $navEmployee->user->name ?? auth()->user()->name }}</div>
                <div class="mt-1 small text-secondary">{{ $navEmployee->position->name ?? 'Сотрудник' }}</div>
            </div>
        @endif
    </a>
    <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
        <div class="dropdown-header d-flex align-items-center gap-2 py-2">
            @if ($navEmployee?->photo_path)
                <span class="avatar avatar-sm avatar-rounded">
                    <img src="/storage/{{ ltrim($navEmployee->photo_path, '/') }}" alt="" class="avatar-img">
                </span>
            @endif
            <div class="lh-sm">
                <div class="fw-bold">{{ $navEmployee?->user->name ?? auth()->user()->name }}</div>
                <div class="small text-secondary">{{ $navEmployee->position->name ?? 'Сотрудник' }}</div>
                @if ($navEmployee?->phone)
                    <div class="small text-secondary">{{ $navEmployee->phone }}</div>
                @endif
            </div>
        </div>
        <div class="dropdown-divider"></div>
        <a class="dropdown-item" href="{{ route('profile.show') }}"><i class="ti ti-user dropdown-item-icon"></i>Профиль</a>
        <div class="dropdown-divider"></div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="dropdown-item"><i class="ti ti-logout dropdown-item-icon"></i>Выйти</button>
        </form>
    </div>
</div>
