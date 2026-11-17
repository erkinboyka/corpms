@php
    $canSearch = app(\App\Support\PermissionService::class)->check(auth()->user(), 'search.global');
    $bellEmployee = auth()->user()->employee;
    $bellUnread ??= \App\Models\AppNotification::query()
        ->where('recipient_id', $bellEmployee?->id ?? 0)
        ->whereNull('read_at')->count();
    $bellItems = \App\Models\AppNotification::query()->where('recipient_id', $bellEmployee?->id ?? 0)->orderByDesc('created_at')->limit(8)->get();
@endphp
<header class="navbar navbar-expand-md d-print-none d-none d-lg-flex">
    <div class="container-xl">
        {{-- поиск слева, экшены справа --}}
        <div class="navbar-nav flex-row w-100">
            <div class="nav-item d-none d-md-flex me-auto">
                @if ($canSearch)
                    <div class="crm-search" id="crm-search" data-hint-url="{{ route('search.hint') }}" data-full-url="{{ route('search.index') }}">
                        <form method="get" action="{{ route('search.index') }}" class="input-icon" role="search">
                            <input type="text" name="q" value="{{ request('q', '') }}" class="form-control crm-search-input" placeholder="Поиск: сотрудники, задачи, документы…" autocomplete="off" aria-autocomplete="list" aria-expanded="false" />
                            <span class="input-icon-addon"><i class="ti ti-search"></i></span>
                        </form>
                        <div class="crm-search-panel" id="crm-search-panel" aria-busy="false" hidden></div>
                    </div>
                @else
                    <div class="input-icon">
                        <input type="text" disabled="disabled" class="form-control" placeholder="Поиск недоступен" />
                        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
                    </div>
                @endif
            </div>

            {{-- тема, уведомления, профиль — общие экшены из nav-actions --}}
            @include('partials.nav-actions', ['themeId' => 'crm-theme-toggle', 'showName' => true])
        </div>
    </div>
</header>

{{-- боковая панель уведомлений (открывается с колокольчика) --}}
<div class="offcanvas offcanvas-end crm-offcanvas" tabindex="-1" id="crm-bell" aria-labelledby="crm-bell-title">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="crm-bell-title">
            Уведомления
            @if ($bellUnread > 0)<span class="badge bg-red-lt ms-1">{{ $bellUnread }}</span>@endif
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Закрыть"></button>
    </div>
    <div class="offcanvas-body p-0">
        <ul class="list-group list-group-flush">
            @forelse ($bellItems as $notification)
                <li class="list-group-item px-3 {{ $notification->read_at === null ? '' : 'opacity-75' }}">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="{{ $notification->read_at === null ? 'fw-bold' : '' }}">{{ $notification->title }}</div>
                            <div class="text-secondary small mt-1">{{ Str::limit($notification->body, 100) }}</div>
                            <div class="text-secondary small mt-1">{{ $notification->created_at?->format('d.m H:i') }}</div>
                        </div>
                        @if ($notification->read_at === null)
                            <form method="post" action="{{ route('notifications.read', $notification) }}">
                                @csrf @method('PUT')
                                <button class="btn btn-sm btn-outline-secondary" title="Отметить прочитанным"><i class="ti ti-check"></i></button>
                            </form>
                        @endif
                    </div>
                </li>
            @empty
                <li class="list-group-item text-center text-secondary py-4"><i class="ti ti-bell-off me-1"></i> Уведомлений нет</li>
            @endforelse
        </ul>
    </div>
    <div class="offcanvas-header border-top pt-2">
        <a href="{{ route('notifications.index') }}" class="btn w-100">Все уведомления</a>
    </div>
</div>
