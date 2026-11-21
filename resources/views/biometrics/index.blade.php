@extends('layouts.tabler')
@section('workspace_layout', 'directory')

@section('title', __('Биометрия'))

@php
    $statusChips = ['online' => 'crm-chip--green', 'offline' => 'crm-chip--orange', 'disabled' => 'crm-chip--grey'];
    $profileChips = [
        'enrolled' => 'crm-chip--green',
        'disabled' => 'crm-chip--orange',
        'deleted' => 'crm-chip--grey',
    ];

    $onlineCount = $devices->filter(fn ($d) => $d->computed_status->value === 'online')->count();
    $offlineCount = $devices->filter(fn ($d) => $d->computed_status->value === 'offline')->count();
    $eventsToday = (int) $devices->sum('events_today');

    // карточки выбора в мастере регистрации устройства
    $deviceTypeCards = [
        'face_terminal' => ['ti-face-id', __('Терминал распознавания лиц')],
        'kiosk' => ['ti-hand-click', __('Киоск самоотметки')],
        'fingerprint_gate' => ['ti-fingerprint', __('Турникет с отпечатком')],
        'turnstile' => ['ti-door', __('Турникет')],
        'qr_reader' => ['ti-qrcode', __('Сканер QR/пропусков')],
    ];
    $directionCards = [
        'in' => ['ti-login', __('Только вход')],
        'out' => ['ti-logout', __('Только выход')],
        'both' => ['ti-arrows-left-right', __('Вход и выход')],
    ];
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Доступ') }}</div>
            <h1 class="page-title mt-1">{{ __('Биометрия') }}</h1>
            <div class="text-secondary mt-1">{{ __('Устройства и профили доступа · offline после') }} {{ $offlineAfter }} {{ __('мин молчания') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('biometrics.events') }}"><i class="ti ti-activity me-1"></i>{{ __('События') }}</a>
            <a class="btn btn-ghost-secondary me-1" href="{{ route('biometrics.privacy') }}"><i class="ti ti-shield-lock me-1"></i>{{ __('Приватность') }}</a>
            <a class="btn btn-ghost-secondary me-1" href="{{ route('rfid.index') }}"><i class="ti ti-credit-card me-1"></i>RFID</a>
            @if ($canManage)
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#registerDevice">
                    <i class="ti ti-plus me-1"></i>{{ __('Зарегистрировать устройство') }}
                </button>
            @endif
        </div>
    </div>
</div>

{{-- ===== Онбординг: одна строка о том, как пользоваться биометрией ===== --}}
<div class="bio-flow mb-3 d-print-none">
    <span class="bio-flow__lead"><i class="ti ti-route me-1"></i>{{ __('Как это работает') }}</span>
    @if ($canManage)
        <button type="button" class="bio-flow__item" data-bs-toggle="modal" data-bs-target="#registerDevice">
            <span class="bio-flow__num">1</span>{{ __('Зарегистрируйте устройство') }}
        </button>
    @else
        <span class="bio-flow__item"><span class="bio-flow__num">1</span>{{ __('Зарегистрируйте устройство') }}</span>
    @endif
    <span class="bio-flow__sep"><i class="ti ti-arrow-right"></i></span>
    @if ($canEnroll)
        <button type="button" class="bio-flow__item" data-bs-toggle="modal" data-bs-target="#enrollProfile">
            <span class="bio-flow__num">2</span>{{ __('Привяжите сотрудника (идентификация)') }}
        </button>
    @else
        <span class="bio-flow__item"><span class="bio-flow__num">2</span>{{ __('Привяжите сотрудника (идентификация)') }}</span>
    @endif
    <span class="bio-flow__sep"><i class="ti ti-arrow-right"></i></span>
    @can('checkBoxPermission', 'attendance.view')
        <a class="bio-flow__item" href="{{ route('attendance.index') }}">
            <span class="bio-flow__num">3</span>{{ __('Отметки появятся в разделе «Посещаемость') }}
        </a>
    @else
        <span class="bio-flow__item"><span class="bio-flow__num">3</span>{{ __('Отметки появятся в разделе «Посещаемость') }}</span>
    @endcan
</div>

<div class="crm-metrics mb-3" data-crm-sec="metrics" data-crm-sec-label="{{ __("Метрики") }}">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-devices"></i>{{ __('Устройств') }}</div>
        <div class="crm-metric__value">{{ $devices->count() }}</div>
        <div class="crm-metric__hint">{{ __('offline порог') }} {{ $offlineAfter }} {{ __('мин') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-wifi"></i>{{ __('Онлайн') }}</div>
        <div class="crm-metric__value">{{ $onlineCount }}</div>
        <div class="crm-metric__hint">{{ $offlineCount }} offline</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-fingerprint"></i>{{ __('Профилей') }}</div>
        <div class="crm-metric__value">{{ $profilesCount }}</div>
        <div class="crm-metric__hint">{{ __('хэши шаблонов') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-bolt"></i>{{ __('Событий сегодня') }}</div>
        <div class="crm-metric__value">{{ $eventsToday }}</div>
        <div class="crm-metric__hint">{{ __('по всем устройствам') }}</div>
    </div>
</div>

{{-- ===== Матрица устройств ===== --}}
<div class="crm-form__section" data-crm-sec="devices" data-crm-sec-label="{{ __("Матрица устройств") }}">
    <h3>{{ __('Матрица устройств') }} <span class="text-secondary fw-normal">({{ $devices->count() }})</span></h3>
    <div class="crm-form__desc">{{ __('Состояния оборудования в реальном времени: связь, направление, нагрузка') }}</div>
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table mb-0 crm-thead-sticky">
            <thead>
                <tr><th scope="col">{{ __('Устройство') }}</th><th scope="col">{{ __('Тип') }}</th><th scope="col">{{ __('Производитель / модель') }}</th><th scope="col">S/N</th><th scope="col">{{ __('Направление') }}</th><th scope="col">{{ __('Статус') }}</th><th scope="col">{{ __('Виден') }}</th><th scope="col">{{ __('Событ. сегодня') }}</th><th scope="col">Enrollment</th><th scope="col"></th></tr>
            </thead>
            <tbody>
                @forelse ($devices as $device)
                    @php($computed = $device->computed_status)
                    <tr>
                        <td>
                            <span class="crm-table__main">{{ $device->name }}</span>
                            <div class="crm-table__sub">{{ $device->location ?? '—' }}</div>
                        </td>
                        <td class="text-secondary">{{ $crm_label('device_type', $device->device_type) }}</td>
                        <td class="text-secondary">{{ $device->vendor }} {{ $device->model ?? '' }}</td>
                        <td><code class="small">{{ $device->serial_number }}</code></td>
                        <td class="text-secondary">{{ $crm_label('device_direction', $device->direction->value) }}</td>
                        <td>
                            <span class="crm-chip {{ $statusChips[$computed->value] ?? 'crm-chip--grey' }}">
                                <span class="crm-live-dot" style="background: {{ $computed->value === 'online' ? 'var(--crm-success)' : ($computed->value === 'offline' ? 'var(--crm-warning)' : 'var(--crm-text-secondary)') }};"></span>
                                {{ $crm_label('device_status', $computed->value) }}
                            </span>
                        </td>
                        <td class="text-secondary small crm-table-num">{{ $device->last_seen_at?->format('d.m H:i') ?? '—' }}</td>
                        <td class="crm-table-num">{{ $device->events_today }}</td>
                        <td class="text-secondary">{{ $device->enrollments_count }}</td>
                        <td class="text-end text-nowrap">
                            @if ($canManage)
                                <form method="post" action="{{ route('biometrics.devices.update', $device) }}" class="d-inline" data-submit-guard>
                                    @csrf
                                    <input type="hidden" name="status" value="{{ $device->status->value === 'disabled' ? 'online' : 'disabled' }}">
                                    <button class="btn btn-sm btn-ghost-secondary" title="{{ $device->status->value === 'disabled' ? __('Включить') : __('Отключить') }}">
                                        <i class="ti ti-{{ $device->status->value === 'disabled' ? 'power' : 'player-pause' }}"></i>
                                    </button>
                                </form>
                                <button type="button" class="btn btn-sm btn-ghost-danger" title="{{ __("Удалить") }}"
                                        data-bs-toggle="modal" data-bs-target="#delDevice-{{ $device->id }}"><i class="ti ti-trash"></i></button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10">@include('partials.empty-state', ['icon' => 'devices', 'title' => __('Устройств нет'), 'text' => __('Зарегистрируйте первое устройство — оно появится в матрице со статусом связи.')])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3">
    {{-- ===== Профили сотрудников ===== --}}
    <div class="col-lg-6" data-crm-sec="profiles">
        <div class="crm-form__section h-100">
            <h3>
                {{ __('Профили сотрудников') }}
                @if ($canEnroll)
                    <button class="btn btn-sm btn-primary float-end" data-bs-toggle="modal" data-bs-target="#enrollProfile">
                        <i class="ti ti-user-plus me-1"></i>{{ __('Регистрация') }}
                    </button>
                @endif
            </h3>
            <div class="crm-form__desc">{{ __('Хэши биометрических шаблонов — сами данные на устройствах') }}</div>
            <div class="table-responsive">
                <table data-record-key="0" class="table table-hover table-vcenter card-table mb-0">
                    <thead><tr><th scope="col">{{ __('Сотрудник') }}</th><th scope="col">Vendor</th><th scope="col">{{ __('Хэш шаблона') }}</th><th scope="col">{{ __('Статус') }}</th><th scope="col"></th></tr></thead>
                    <tbody>
                        @forelse ($profiles as $profile)
                            <tr>
                                <td><span class="crm-table__main">{{ $profile->employee?->last_name }} {{ $profile->employee?->first_name }}</span></td>
                                <td class="text-secondary">{{ $profile->vendor }}</td>
                                <td class="text-secondary"><code class="small">{{ \Illuminate\Support\Str::limit($profile->template_hash, 16, '…') }}</code></td>
                                <td>
                                    <span class="crm-chip {{ $profileChips[$profile->status->value] ?? 'crm-chip--cyan' }}">{{ $crm_label('biometric_profile_status', $profile->status->value) }}</span>
                                </td>
                                <td class="text-end text-nowrap">
                                    @if ($canEnroll && $profile->status->value === 'enrolled')
                                        <form method="post" action="{{ route('biometrics.profile.disable', $profile) }}" class="d-inline" data-submit-guard>
                                            @csrf
                                            <button class="btn btn-sm btn-ghost-secondary" title="{{ __("Деактивировать") }}"><i class="ti ti-player-pause"></i></button>
                                        </form>
                                    @endif
                                    @if ($canEnroll && $profile->status->value !== 'deleted')
                                        <form method="post" action="{{ route('biometrics.profile.delete', $profile) }}" class="d-inline"
                                              onsubmit="return confirm(__('Удалить профиль?'))" data-submit-guard>
                                            @csrf
                                            <button class="btn btn-sm btn-ghost-danger" title="{{ __("Удалить") }}"><i class="ti ti-trash"></i></button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'user-x', 'title' => __('Профилей нет'), 'text' => __('Запишите биометрию сотрудника на устройстве.')])</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ===== Журнал enrollment ===== --}}
    <div class="col-lg-6" data-crm-sec="enroll-log">
        <div class="crm-form__section h-100">
            <h3>{{ __('Журнал регистрации') }} <span class="text-secondary fw-normal">({{ $enrollments->count() }})</span></h3>
            <div class="crm-form__desc">{{ __('Кто, где и когда записывал биометрию') }}</div>
            <div class="table-responsive">
                <table data-record-key="0" class="table table-hover table-vcenter card-table mb-0">
                    <thead><tr><th scope="col">{{ __('Когда') }}</th><th scope="col">{{ __('Сотрудник') }}</th><th scope="col">{{ __('Устройство') }}</th><th scope="col">{{ __('Производитель') }}</th><th scope="col">{{ __('Оператор') }}</th></tr></thead>
                    <tbody>
                        @forelse ($enrollments as $enrollment)
                            <tr>
                                <td class="text-secondary crm-table-num">{{ $enrollment->created_at?->format('d.m H:i') }}</td>
                                <td><span class="crm-table__main">{{ $enrollment->employee?->last_name }} {{ $enrollment->employee?->first_name }}</span></td>
                                <td class="text-secondary">{{ $enrollment->device?->name }}</td>
                                <td class="text-secondary">{{ $enrollment->device?->vendor }}</td>
                                <td class="text-secondary">{{ $enrollment->operated_by ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'history', 'title' => __('Записей нет'), 'text' => __('Журнал наполнится после первой записи профиля.')])</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- ===== Регистрация устройства: 3-шаговый мастер в модалке ===== --}}
@if ($canManage)
<div class="modal modal-blur fade" id="registerDevice" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('biometrics.devices.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Регистрация устройства') }}</h5></div>
            <div class="modal-body">

                {{-- шаги мастера --}}
                <div class="wz-steps" role="group" aria-label="{{ __("Шаги регистрации устройства") }}">
                    <button type="button" class="wz-steps__item is-active" data-wz-goto="1" aria-current="step">
                        <span class="wz-steps__num">1</span><span class="wz-steps__title">{{ __('Устройство') }}</span>
                    </button>
                    <span class="wz-steps__sep" aria-hidden="true"></span>
                    <button type="button" class="wz-steps__item" data-wz-goto="2" disabled>
                        <span class="wz-steps__num">2</span><span class="wz-steps__title">{{ __('Подключение') }}</span>
                    </button>
                    <span class="wz-steps__sep" aria-hidden="true"></span>
                    <button type="button" class="wz-steps__item" data-wz-goto="3" disabled>
                        <span class="wz-steps__num">3</span><span class="wz-steps__title">{{ __('Проверка') }}</span>
                    </button>
                </div>

                {{-- Шаг 1 — Устройство --}}
                <div class="wz-pane" data-wz-step="1">
                    <p class="wz-lead">{{ __('Как устройство будет называться в списке и что написано на корпусе') }}</p>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="bd-name">{{ __('Название') }}<span class="req">*</span></label>
                            <input type="text" id="bd-name" name="name" class="form-control" required placeholder="{{ __("Турникет вход lobby") }}" autocomplete="off">
                            <div class="form-text">{{ __('Как устройство будет отображаться в списке') }}</div>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="bd-sn">{{ __('Серийный номер') }}<span class="req">*</span></label>
                            <input type="text" id="bd-sn" name="serial_number" class="form-control" required placeholder="ZN-000123" autocomplete="off">
                            <div class="form-text">{{ __('Серийный номер с наклейки на корпусе') }}</div>
                        </div>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label">{{ __('Тип устройства') }}<span class="req">*</span></label>
                        <div class="wz-cards">
                            @foreach ($deviceTypeCards as $type => $card)
                                <label class="wz-card">
                                    <input class="wz-card__input" type="radio" name="device_type" value="{{ $type }}" required @checked($type === 'face_terminal')>
                                    <span class="wz-card__icon"><i class="ti {{ $card[0] }}"></i></span>
                                    <span class="wz-card__label">{{ $card[1] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Шаг 2 — Подключение --}}
                <div class="wz-pane d-none" data-wz-step="2">
                    <p class="wz-lead">{{ __('Направление прохода и производитель оборудования') }}</p>
                    <div class="crm-form__field">
                        <label class="form-label">{{ __('Направление') }}<span class="req">*</span></label>
                        <div class="wz-cards wz-cards--dir">
                            @foreach ($directionCards as $dir => $card)
                                <label class="wz-card">
                                    <input class="wz-card__input" type="radio" name="direction" value="{{ $dir }}" required @checked($dir === 'both')>
                                    <span class="wz-card__icon"><i class="ti {{ $card[0] }}"></i></span>
                                    <span class="wz-card__label">{{ $card[1] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="bd-vendor">{{ __('Производитель') }}<span class="req">*</span></label>
                        <input type="text" id="bd-vendor" name="vendor" class="form-control" required placeholder="ZKTeco" autocomplete="off">
                        <div class="form-text">{{ __('производитель: ZKTeco, Suprema…') }}</div>
                    </div>

                    <details class="wz-extra">
                        <summary>
                            <i class="ti ti-chevron-down wz-extra__chev"></i>
                            {{ __('Дополнительно') }} <span class="text-secondary fw-normal">{{ __('(необязательно)') }}</span>
                        </summary>
                        <div class="wz-extra__grid">
                            <div class="crm-form__field">
                                <label class="form-label" for="bd-model">{{ __('Модель') }}</label>
                                <input type="text" id="bd-model" name="model" class="form-control" autocomplete="off" placeholder="iFace 402">
                            </div>
                            <div class="crm-form__field">
                                <label class="form-label" for="bd-loc">{{ __('Расположение') }}</label>
                                <input type="text" id="bd-loc" name="location" class="form-control" autocomplete="off" placeholder="{{ __("вход, 1 этаж") }}">
                                <div class="form-text">{{ __('этаж, вход, зона') }}</div>
                            </div>
                            <div class="crm-form__field">
                                <label class="form-label" for="bd-ip">IP</label>
                                <input type="text" id="bd-ip" name="ip" class="form-control" autocomplete="off" placeholder="192.168.1.50">
                                <div class="form-text">{{ __('напр. 192.168.1.50 — для подключения по сети') }}</div>
                            </div>
                            <div class="crm-form__field">
                                <label class="form-label" for="bd-fw">{{ __('Прошивка') }}</label>
                                <input type="text" id="bd-fw" name="firmware" class="form-control" autocomplete="off" placeholder="v6.60">
                            </div>
                        </div>
                    </details>
                </div>

                {{-- Шаг 3 — Проверка --}}
                <div class="wz-pane d-none" data-wz-step="3">
                    <p class="wz-lead">{{ __('Проверьте данные — после регистрации устройство сразу начнёт отдавать события') }}</p>
                    <div class="wz-sum" id="bd-summary" aria-live="polite"></div>
                    <div class="alert alert-warning d-flex align-items-start gap-2 mt-3 mb-0">
                        <i class="ti ti-alert-triangle mt-1"></i>
                        <div>{{ __('После регистрации API-токен будет показан один раз') }}</div>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn me-auto" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="button" class="btn btn-ghost-secondary wz-back d-none"><i class="ti ti-arrow-left me-1"></i>{{ __('Назад') }}</button>
                <button type="button" class="btn btn-primary wz-next">{{ __('Далее') }}<i class="ti ti-arrow-right ms-1"></i></button>
                <button type="submit" class="btn btn-primary wz-submit d-none"><i class="ti ti-check me-1"></i>{{ __('Зарегистрировать устройство') }}</button>
            </div>
        </form>
    </div>
</div>

{{-- подтверждение удаления устройства --}}
@foreach ($devices as $device)
<div class="modal modal-blur fade" id="delDevice-{{ $device->id }}" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-sm" role="document">
        <form class="modal-content" method="post" action="{{ route('biometrics.devices.delete', $device) }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Удалить устройство') }}</h5></div>
            <div class="modal-body">
                {{ __('Удалить') }}{{ $device->name }}{{ __('? Действие необратимо') }}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-danger">{{ __('Удалить') }}</button>
            </div>
        </form>
    </div>
</div>
@endforeach
@endif

{{-- ===== Регистрация профиля ===== --}}
@if ($canEnroll)
<div class="modal modal-blur fade" id="enrollProfile" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('biometrics.profile.enroll') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Регистрация биометрического профиля') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Кого и где') }}</h3>
                    <div class="crm-form__desc">{{ __('Сотрудник и устройство, на котором снимается шаблон') }}</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="be-empl">{{ __('Сотрудник') }}<span class="req">*</span></label>
                        <select id="be-empl" name="employee_id" class="form-select" required>
                            <option value="">{{ __('выбрать') }}</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->last_name }} {{ $employee->first_name }} ({{ $employee->employee_no }})</option>
                            @endforeach
                        </select>
                        <div class="form-text">{{ __('Кого записываем — биометрия привяжется к этому сотруднику') }}</div>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="be-dev">{{ __('Устройство') }}<span class="req">*</span></label>
                        <select id="be-dev" name="device_id" class="form-select" required>
                            @foreach ($devices as $device)
                                <option value="{{ $device->id }}">{{ $device->name }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">{{ __('Устройство, где сотрудник стоит при записи шаблона') }}</div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Шаблон') }}</h3>
                    <div class="crm-form__desc">{{ __('Биометрия не передаётся по сети — только хэш') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="be-vendor">Vendor<span class="req">*</span></label>
                            <input type="text" id="be-vendor" name="vendor" class="form-control" required placeholder="generic">
                            <div class="form-text">{{ __('Совпадает с производителем устройства') }}</div>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="be-ref">{{ __('Ссылка шаблона') }}</label>
                            <input type="text" id="be-ref" name="template_ref" class="form-control">
                        </div>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="be-hash">{{ __('Хэш шаблона (от устройства)') }}<span class="req">*</span></label>
                        <input type="text" id="be-hash" name="template_hash" class="form-control" required>
                        <div class="form-text">{{ __('Строка, которую выдало устройство после записи') }}</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Записать') }}</button>
            </div>
        </form>
    </div>
</div>
@endif

@push('styles')
<style>
    /* ===== Подсказка-флоу над таблицей (онбординг) ===== */
    .bio-flow {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: .35rem .55rem;
        padding: .55rem .85rem;
        border: 1px solid var(--crm-border);
        border-radius: 12px;
        background: var(--crm-surface);
        font-size: .8125rem;
        color: var(--crm-text-secondary);
    }
    .bio-flow__lead {
        display: inline-flex;
        align-items: center;
        padding-right: .5rem;
        margin-right: .1rem;
        border-right: 1px solid var(--crm-border);
        font-size: .6875rem;
        font-weight: 700;
        letter-spacing: .05em;
        text-transform: uppercase;
        color: var(--crm-text-muted);
    }
    .bio-flow__item {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        padding: 0;
        border: 0;
        background: none;
        font: inherit;
        color: var(--crm-text-secondary);
        text-decoration: none;
        border-radius: 8px;
        transition: color .15s ease;
    }
    button.bio-flow__item,
    a.bio-flow__item { cursor: pointer; }
    button.bio-flow__item:hover,
    a.bio-flow__item:hover { color: var(--crm-accent-strong); text-decoration: none; }
    .bio-flow__num {
        width: 1.25rem;
        height: 1.25rem;
        flex: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: rgba(var(--crm-accent-rgb), .14);
        color: var(--crm-accent-strong, var(--tblr-primary));
        font-size: .6875rem;
        font-weight: 700;
    }
    .bio-flow__sep { display: inline-flex; align-items: center; color: var(--crm-text-muted); }
    @media (max-width: 575.98px) { .bio-flow__lead { display: none; } }

    /* ===== Мастер регистрации устройства (#registerDevice) ===== */
    #registerDevice .wz-steps {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: .5rem;
        margin: 0 0 1.1rem;
    }
    #registerDevice .wz-steps__item {
        display: inline-flex;
        align-items: center;
        gap: .5rem;
        padding: 0;
        border: 0;
        background: none;
        font: inherit;
        font-size: .875rem;
        font-weight: 600;
        color: var(--crm-text-secondary);
        transition: color .15s ease;
    }
    #registerDevice .wz-steps__item:disabled { cursor: default; opacity: .7; }
    #registerDevice .wz-steps__item.is-active,
    #registerDevice .wz-steps__item.is-done { color: var(--crm-text); opacity: 1; }
    #registerDevice .wz-steps__item.is-done { cursor: pointer; }
    #registerDevice .wz-steps__item.is-done:hover { color: var(--crm-accent-strong); }
    #registerDevice .wz-steps__num {
        width: 1.75rem;
        height: 1.75rem;
        flex: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: rgba(151, 163, 182, .16);
        box-shadow: inset 0 0 0 1px var(--crm-border);
        font-size: .8125rem;
        font-weight: 700;
        transition: background-color .18s ease, color .18s ease, box-shadow .18s ease;
    }
    #registerDevice .wz-steps__item.is-done .wz-steps__num {
        background: rgba(47, 179, 68, .16);
        color: var(--crm-success);
        box-shadow: inset 0 0 0 1px rgba(47, 179, 68, .45);
    }
    [data-bs-theme="dark"] #registerDevice .wz-steps__item.is-done .wz-steps__num { color: #69db7c; }
    #registerDevice .wz-steps__item.is-active .wz-steps__num {
        background: var(--crm-accent-strong, var(--tblr-primary));
        color: #fff;
        box-shadow: 0 0 0 4px rgba(var(--crm-accent-rgb), .18);
    }
    #registerDevice .wz-steps__sep {
        flex: 1 1 1.25rem;
        min-width: 1rem;
        height: 2px;
        border-radius: 2px;
        background: var(--crm-border);
    }

    #registerDevice .wz-pane { margin: 0; }
    #registerDevice .wz-pane:not(.d-none) { animation: wz-pane-in .18s ease both; }
    @keyframes wz-pane-in { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
    #registerDevice .wz-lead {
        font-size: .8125rem;
        line-height: var(--crm-lh-caption);
        color: var(--crm-text-secondary);
        margin: 0 0 1rem;
    }
    #registerDevice .wz-pane .crm-form__field:last-child { margin-bottom: 0; }

    /* --- карточки-радио (тип устройства, направление) --- */
    #registerDevice .wz-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
        gap: .5rem;
    }
    #registerDevice .wz-card {
        position: relative;
        display: flex;
        align-items: center;
        gap: .6rem;
        padding: .6rem .7rem;
        border: 1px solid var(--crm-border);
        border-radius: 12px;
        background: var(--crm-input-bg);
        cursor: pointer;
        transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
    }
    #registerDevice .wz-card:hover { border-color: rgba(var(--crm-accent-rgb), .5); }
    #registerDevice .wz-card__input {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        margin: 0;
        opacity: 0;
        cursor: pointer;
    }
    #registerDevice .wz-card__icon {
        flex: none;
        width: 2rem;
        height: 2rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: rgba(var(--crm-accent-rgb), .12);
        color: var(--crm-accent-strong, var(--tblr-primary));
        font-size: 1.125rem;
        transition: background-color .15s ease, color .15s ease;
    }
    #registerDevice .wz-card__label {
        font-size: .8125rem;
        font-weight: 600;
        line-height: 1.3;
        color: var(--crm-text-secondary);
        transition: color .15s ease;
    }
    #registerDevice .wz-card.is-checked {
        border-color: rgba(var(--crm-accent-rgb), .65);
        background: rgba(var(--crm-accent-rgb), .10);
        box-shadow: 0 0 0 3px rgba(var(--crm-accent-rgb), .15);
    }
    #registerDevice .wz-card.is-checked .wz-card__label { color: var(--crm-text); }
    #registerDevice .wz-card.is-checked .wz-card__icon {
        background: rgba(var(--crm-accent-rgb), .2);
        color: var(--crm-accent-strong);
    }
    #registerDevice .wz-card:has(.wz-card__input:focus-visible) {
        outline: 2px solid rgba(var(--crm-accent-rgb), .85);
        outline-offset: 2px;
    }

    /* --- свёрнутый блок «Дополнительно» --- */
    #registerDevice .wz-extra {
        border: 1px dashed var(--crm-border);
        border-radius: 12px;
        background: rgba(var(--crm-accent-rgb), .04);
    }
    #registerDevice .wz-extra > summary {
        display: flex;
        align-items: center;
        gap: .45rem;
        list-style: none;
        cursor: pointer;
        padding: .6rem .75rem;
        font-size: .8125rem;
        font-weight: 650;
        color: var(--crm-text-secondary);
        transition: color .15s ease;
    }
    #registerDevice .wz-extra > summary::-webkit-details-marker { display: none; }
    #registerDevice .wz-extra > summary:hover { color: var(--crm-text); }
    #registerDevice .wz-extra[open] > summary {
        color: var(--crm-text);
        border-bottom: 1px solid var(--crm-border);
    }
    #registerDevice .wz-extra__chev {
        font-size: .9375rem;
        transition: transform .18s ease;
    }
    #registerDevice .wz-extra[open] .wz-extra__chev { transform: rotate(180deg); }
    #registerDevice .wz-extra__grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        padding: .8rem .75rem .75rem;
    }
    #registerDevice .wz-extra__grid .crm-form__field { margin-bottom: 0; }
    @media (max-width: 575.98px) {
        #registerDevice .wz-extra__grid { grid-template-columns: minmax(0, 1fr); }
    }

    /* --- сводка шага «Проверка» --- */
    #registerDevice .wz-sum {
        display: flex;
        flex-direction: column;
        gap: .55rem;
        padding: .85rem 1rem;
        border: 1px solid var(--crm-border);
        border-radius: 12px;
        background: rgba(var(--crm-accent-rgb), .05);
    }
    #registerDevice .wz-sum__row {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 1rem;
        font-size: .875rem;
    }
    #registerDevice .wz-sum__k {
        flex: none;
        font-size: .8125rem;
        color: var(--crm-text-secondary);
    }
    #registerDevice .wz-sum__v {
        font-weight: 650;
        text-align: right;
        word-break: break-word;
    }
    #registerDevice .wz-sum__v.is-empty { color: var(--crm-text-muted); font-weight: 500; }
</style>
@endpush

@push('scripts')
<script>
    (function () {
        function initDeviceWizard() {
            var modal = document.getElementById('registerDevice');
            if (!modal) { return; }
            var form = modal.querySelector('form[data-submit-guard]');
            if (!form) { return; }

            var panes = Array.prototype.slice.call(form.querySelectorAll('[data-wz-step]'));
            var stepBtns = Array.prototype.slice.call(form.querySelectorAll('.wz-steps__item'));
            var btnBack = form.querySelector('.wz-back');
            var btnNext = form.querySelector('.wz-next');
            var btnSubmit = form.querySelector('.wz-submit');
            var summary = form.querySelector('#bd-summary');
            var cardInputs = Array.prototype.slice.call(form.querySelectorAll('.wz-card__input'));
            var current = 1;
            var suppress = false;

            function paneOf(n) {
                for (var i = 0; i < panes.length; i++) {
                    if (Number(panes[i].getAttribute('data-wz-step')) === n) { return panes[i]; }
                }
                return null;
            }

            function fieldValue(name) {
                var el = form.elements.namedItem(name);
                return el ? String(el.value || '').trim() : '';
            }

            function cardLabel(name) {
                var checked = form.querySelector('input[name="' + name + '"]:checked');
                if (!checked) { return ''; }
                var card = checked.closest('.wz-card');
                var label = card && card.querySelector('.wz-card__label');
                return label ? label.textContent.trim() : checked.value;
            }

            function renderSummary() {
                if (!summary) { return; }
                var rows = [
                    [__('Название'), fieldValue('name')],
                    [__('Тип устройства'), cardLabel('device_type')],
                    [__('Направление'), cardLabel('direction')],
                    [__('Производитель'), fieldValue('vendor')]
                ];
                [[__('Модель'), 'model'], [__('Расположение'), 'location'], ['IP', 'ip'], [__('Прошивка'), 'firmware']]
                    .forEach(function (pair) {
                        var value = fieldValue(pair[1]);
                        if (value) { rows.push([pair[0], value]); }
                    });

                summary.textContent = '';
                rows.forEach(function (row) {
                    var line = document.createElement('div');
                    line.className = 'wz-sum__row';
                    var key = document.createElement('span');
                    key.className = 'wz-sum__k';
                    key.textContent = row[0];
                    var value = document.createElement('span');
                    value.className = 'wz-sum__v' + (row[1] ? '' : ' is-empty');
                    value.textContent = row[1] || '—';
                    line.appendChild(key);
                    line.appendChild(value);
                    summary.appendChild(line);
                });
            }

            function syncCards() {
                cardInputs.forEach(function (input) {
                    var card = input.closest('.wz-card');
                    if (card) { card.classList.toggle('is-checked', input.checked); }
                });
            }

            function goTo(n) {
                if (n < 1 || n > panes.length) { return; }
                current = n;
                panes.forEach(function (pane) {
                    pane.classList.toggle('d-none', Number(pane.getAttribute('data-wz-step')) !== n);
                });
                stepBtns.forEach(function (btn) {
                    var index = Number(btn.getAttribute('data-wz-goto'));
                    btn.classList.toggle('is-active', index === n);
                    btn.classList.toggle('is-done', index < n);
                    btn.disabled = index >= n;
                    if (index === n) { btn.setAttribute('aria-current', 'step'); }
                    else { btn.removeAttribute('aria-current'); }
                });
                if (btnBack) { btnBack.classList.toggle('d-none', n === 1); }
                if (btnNext) { btnNext.classList.toggle('d-none', n === panes.length); }
                if (btnSubmit) { btnSubmit.classList.toggle('d-none', n !== panes.length); }
                if (n === panes.length) { renderSummary(); }
            }

            function report(field) {
                suppress = true;
                try { field.reportValidity(); } finally { suppress = false; }
            }

            function validateStep(n) {
                var pane = paneOf(n);
                if (!pane) { return true; }
                var fields = pane.querySelectorAll('input, select, textarea');
                for (var i = 0; i < fields.length; i++) {
                    if (fields[i].disabled) { continue; }
                    if (!fields[i].checkValidity()) { report(fields[i]); return false; }
                }
                return true;
            }

            /* поле в скрытом шаге не смогло показать подсказку — открываем шаг и повторяем */
            form.addEventListener('invalid', function (event) {
                if (suppress) { return; }
                var field = event.target;
                var pane = field.closest ? field.closest('[data-wz-step]') : null;
                if (!pane) { return; }
                event.preventDefault();
                goTo(Number(pane.getAttribute('data-wz-step')));
                report(field);
            }, true);

            if (btnNext) {
                btnNext.addEventListener('click', function () {
                    if (validateStep(current)) { goTo(current + 1); }
                });
            }
            if (btnBack) {
                btnBack.addEventListener('click', function () { goTo(current - 1); });
            }
            stepBtns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var n = Number(btn.getAttribute('data-wz-goto'));
                    if (n < current) { goTo(n); }
                });
            });

            cardInputs.forEach(function (input) {
                input.addEventListener('change', syncCards);
            });

            modal.addEventListener('show.bs.modal', function () { goTo(1); });

            syncCards();
            goTo(1);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initDeviceWizard);
        } else {
            initDeviceWizard();
        }
    })();
</script>
@endpush
@endsection
