@extends('layouts.tabler')
@section('workspace_layout', 'record')

@section('title', __('Профиль'))

@php
    $user = auth()->user();
    $initials = $employee !== null
        ? mb_strtoupper(mb_substr($employee->first_name, 0, 1) . mb_substr($employee->last_name, 0, 1))
        : mb_strtoupper(mb_substr($user->name, 0, 1));
    $statusLabels = [
        \App\Shared\Enums\EmployeeStatus::Active->value => __('Работает'),
        \App\Shared\Enums\EmployeeStatus::Inactive->value => __('Не активен'),
        \App\Shared\Enums\EmployeeStatus::Dismissed->value => __('Уволен'),
    ];
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Учётная запись') }}</div>
            <h1 class="page-title mt-1">{{ __('Профиль') }}</h1>
            <div class="text-secondary mt-1">{{ __('Личные данные, фото и смена пароля') }}</div>
        </div>
    </div>
</div>

{{-- ===== Шапка профиля (аватар + фото) ===== --}}
<div class="crm-profile-hero card mb-3">
    <div class="card-body d-flex flex-column flex-md-row align-items-md-center gap-3">
        <div class="position-relative d-inline-flex flex-none">
            <span class="crm-entity__logo crm-profile-hero__avatar" style="padding: 0; overflow: hidden;">
                @if ($employee?->photo_path)
                    {{-- корневой относительный путь (как и /assets в layout) — не зависит от APP_URL/хоста --}}
                    <img src="/storage/{{ ltrim($employee->photo_path, '/') }}" alt="{{ __('Фото') }} {{ $user->name }}"
                         style="width: 100%; height: 100%; object-fit: cover; display: block;">
                @else
                    {{ $initials }}
                @endif
            </span>
            @if ($employee !== null)
                <button type="button" id="photo-upload-btn"
                        class="btn btn-sm btn-icon btn-secondary position-absolute"
                        style="right: -8px; bottom: -8px; border-radius: 50%; box-shadow: 0 4px 12px rgba(0, 0, 0, .25);"
                        title="{{ __('Загрузить фото') }} (JPEG/PNG/WebP, {{ __('до') }} 2 {{ __('МБ') }})" aria-label="{{ __("Загрузить фото") }}">
                    <i class="ti ti-camera"></i>
                </button>
            @endif
        </div>
        <div class="flex-grow-1 min-w-0">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h2 class="page-title mb-0">{{ $user->name }}</h2>
                @if ($employee !== null)
                    <span class="crm-chip {{ $employee->status === \App\Shared\Enums\EmployeeStatus::Active ? 'crm-chip--green' : 'crm-chip--grey' }}">
                        {{ $statusLabels[$employee->status->value] ?? $employee->status->value }}
                    </span>
                @endif
            </div>
            <div class="text-secondary mt-1 d-flex align-items-center gap-1 flex-wrap">
                <i class="ti ti-mail"></i> {{ $user->email }}
                @if ($employee?->employee_no)
                    <span class="mx-1">·</span> <i class="ti ti-hash"></i> {{ $employee->employee_no }}
                @endif
                @if ($employee?->department)
                    <span class="mx-1">·</span> <i class="ti ti-building"></i> {{ $employee->department->name }}
                @endif
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @forelse ($roles as $role)
                <span class="crm-chip crm-chip--cyan"><i class="ti ti-shield me-1"></i>{{ $role }}</span>
            @empty
                <span class="crm-chip crm-chip--grey"><i class="ti ti-shield me-1"></i>{{ __('роли не назначены') }}</span>
            @endforelse
        </div>
    </div>
</div>

{{-- ===== Невидимая форма загрузки фото (открывается кнопкой-«камерой») ===== --}}
@if ($employee !== null)
    <form id="photo-form" method="post" action="{{ route('profile.photo') }}" enctype="multipart/form-data"
          data-submit-guard hidden>
        @csrf
        <input type="file" id="photo-input" name="photo" accept="image/jpeg,image/png,image/webp">
    </form>
@endif

<div class="row g-3">
    {{-- ===== Личные данные (заполняемые) ===== --}}
    <div class="col-lg-7">
        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
            <div>
                <div class="crm-eyebrow">{{ __('Заполняет сотрудник') }}</div>
                <div class="h3 mt-1 mb-0">{{ __('Личные данные') }}</div>
            </div>
        </div>

        @if ($employee === null)
            <div class="card crm-panel mb-3">
                <div class="card-body">
                    @include('partials.empty-state', [
                        'icon' => 'id-badge',
                        'title' => __('Карточка не привязана'),
                        'text' => __('К вашей учётной записи не привязана карточка сотрудника. Обратитесь в отдел кадров.'),
                    ])
                </div>
            </div>
        @else
            <form method="post" action="{{ route('profile.update') }}" data-submit-guard class="crm-form mb-3">
                @csrf @method('PUT')

                <div class="crm-form__section">
                    <div class="crm-form__desc">{{ __('Контакты для кадрового учёта — то же, что заполняют при приёме на работу. Изменения фиксируются в Audit Log') }}</div>

                    <div class="crm-form__row">
                        @include('partials.phone-field', [
                            'id' => 'pf-phone',
                            'name' => 'phone',
                            'label' => __('Телефон'),
                            'value' => old('phone', $employee->phone),
                            'hint' => __('Международный формат, например +7 (999) 123-45-67.'), 'required' => false,
                            'timezone' => $employee->company?->timezone,
                        ])
                        <div class="crm-form__field">
                            <label class="form-label" for="pf-address">{{ __('Адрес') }}</label>
                            <input type="text" id="pf-address" name="address" class="form-control" maxlength="255"
                                   value="{{ old('address', $employee->address) }}" placeholder="{{ __("г. Душанбе, ул. Рудаки, 1") }}">
                            <div class="form-text">{{ __('Прописка / фактический адрес — для кадровых документов') }}</div>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="pf-telegram">Telegram</label>
                            <input type="text" id="pf-telegram" name="telegram_chat_id" class="form-control" maxlength="64"
                                   value="{{ old('telegram_chat_id', $employee->telegram_chat_id) }}" placeholder="{{ __("@username или ID") }}">
                            <div class="form-text">{{ __('Для уведомлений в боте') }} @username {{ __('или числовой chat ID') }}</div>
                        </div>
                    </div>

                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="pf-birth">{{ __('Дата рождения') }}</label>
                            <input type="date" id="pf-birth" name="birth_date" class="form-control"
                                   value="{{ old('birth_date', $employee->birth_date?->format('Y-m-d')) }}" max="{{ now()->toDateString() }}">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="pf-email">{{ __('Рабочий e-mail') }}</label>
                            <input type="email" id="pf-email" name="email" class="form-control" maxlength="150"
                                   value="{{ old('email', session('profile_suggestions.email', $employee->email)) }}" placeholder="name@company.tj">
                        </div>
                    </div>
                </div>

                <div class="crm-form__footer crm-form__footer--card">
                    <button class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>{{ __('Сохранить') }}</button>
                    <span class="text-secondary small">{{ __('Все поля необязательные — заполняйте по мере необходимости') }}</span>
                </div>
            </form>
        @endif

        {{-- ===== Данные сотрудника (орг info, только чтение) ===== --}}
        @if ($employee !== null)
        <div class="card crm-panel mb-3">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">{{ __('Сотрудник') }}</div>
                    <div class="crm-panel__sub">{{ __('данные из кадрового учёта') }}</div>
                </div>
                <span class="crm-chip crm-chip--cyan" title="{{ __("Карточка доступна только для просмотра") }}"><i class="ti ti-lock-square-rounded me-1"></i>{{ __('только чтение') }}</span>
            </div>
            <div class="card-body">
                <dl class="crm-kv">
                    <div class="crm-kv__row">
                        <dt>{{ __('ФИО') }}</dt>
                        <dd>{{ $employee->last_name }} {{ $employee->first_name }} @if ($employee->middle_name){{ $employee->middle_name }}@endif</dd>
                    </div>
                    <div class="crm-kv__row">
                        <dt>{{ __('Табельный номер') }}</dt>
                        <dd>{{ $employee->employee_no ?: '—' }}</dd>
                    </div>
                    <div class="crm-kv__row">
                        <dt>{{ __('Компания') }}</dt>
                        <dd>{{ $employee->company?->name ?? '—' }}</dd>
                    </div>
                    <div class="crm-kv__row">
                        <dt>{{ __('Подразделение') }}</dt>
                        <dd>{{ $employee->department?->name ?? '—' }}</dd>
                    </div>
                    <div class="crm-kv__row">
                        <dt>{{ __('Должность') }}</dt>
                        <dd>{{ $employee->position?->name ?? '—' }}</dd>
                    </div>
                    <div class="crm-kv__row">
                        <dt>{{ __('Дата приёма') }}</dt>
                        <dd>{{ $employee->hired_at ? \Carbon\Carbon::parse($employee->hired_at)->format('d.m.Y') : '—' }}</dd>
                    </div>
                </dl>
            </div>
        </div>
        @endif
    </div>

    {{-- ===== Безопасность ===== --}}
    <div class="col-lg-5">
        <form method="post" action="{{ route('profile.password') }}" data-submit-guard class="crm-form mb-3">
            @csrf @method('PUT')

            <div class="crm-form__section">
                <h3>{{ __('Безопасность') }}</h3>
                <div class="crm-form__desc">{{ __('Пароль от учётной записи. Смена фиксируется в Audit Log') }}</div>

                <div class="crm-form__field">
                    <label class="form-label" for="pw-current">{{ __('Текущий пароль') }}</label>
                    <input type="password" id="pw-current" name="current_password" class="form-control" required autocomplete="current-password">
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="pw-new">{{ __('Новый пароль') }}</label>
                    <input type="password" id="pw-new" name="password" class="form-control" minlength="8" required autocomplete="new-password">
                    <div class="form-hint">{{ __('минимум 8 символов') }}</div>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="pw-confirm">{{ __('Повторите новый пароль') }}</label>
                    <input type="password" id="pw-confirm" name="password_confirmation" class="form-control" minlength="8" required autocomplete="new-password">
                </div>
            </div>

            <div class="crm-form__footer crm-form__footer--card">
                <button class="btn btn-primary"><i class="ti ti-key me-1"></i>{{ __('Сменить пароль') }}</button>
                <span class="text-secondary small">{{ __('Поля обязательны для заполнения') }}</span>
            </div>
        </form>

        <a class="crm-note text-decoration-none" href="{{ route('profile.2fa') }}">
            <span class="crm-note__icon"><i class="ti ti-shield-lock"></i></span>
            <span class="flex-grow-1">
                <strong class="d-block mb-1">{{ __('Двухфакторная аутентификация') }}</strong>
                <span class="text-secondary">{{ __('Включите защиту входа и управляйте кодами восстановления') }}</span>
            </span>
            <i class="ti ti-arrow-right text-secondary align-self-center"></i>
        </a>

        <div class="crm-note">
            <span class="crm-note__icon"><i class="ti ti-shield-lock"></i></span>
            <span>
                <strong class="d-block mb-1">{{ __('Доступ') }}</strong>
                <span class="text-secondary">{{ __('Роли и доступ определяются на вкладке сотрудника и не изменяются здесь') }}</span>
            </span>
        </div>
    </div>
</div>

@push('scripts')
<script>
    (function () {
        var btn = document.getElementById('photo-upload-btn');
        var input = document.getElementById('photo-input');
        var form = document.getElementById('photo-form');
        if (!btn || !input || !form) { return; }

        btn.addEventListener('click', function () { input.click(); });

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) { return; }

            if (file.size > 2 * 1024 * 1024) {
                window.alert(@json(__('Фото больше 2 МБ. Выберите файл меньшего размера (JPEG/PNG/WebP).')));
                input.value = '';
                return;
            }

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(); // вызывает submit-guard (спиннер) и валидацию на сервере
            } else {
                form.submit();
            }
        });
    })();
</script>
@endpush
@endsection
