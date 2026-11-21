@extends('layouts.tabler')
@section('workspace_layout', 'record')

@section('title', $employee->last_name . ' ' . $employee->first_name)

@php
    $fullName = trim($employee->last_name . ' ' . $employee->first_name . ($employee->middle_name ? ' ' . $employee->middle_name : ''));
    $initials = mb_strtoupper(mb_substr($employee->first_name, 0, 1) . mb_substr($employee->last_name, 0, 1));
    if ($initials === '') { $initials = 'CM'; }
    $statusClass = match ($employee->status->value) {
        'active' => 'crm-chip--green',
        'dismissed' => 'crm-chip--red',
        default => 'crm-chip--grey',
    };
    $statusIcon = match ($employee->status->value) {
        'active' => 'circle-check',
        'dismissed' => 'user-off',
        default => 'clock-pause',
    };
@endphp

@section('content')
{{-- ===== Шапка профиля ===== --}}
<div class="crm-profile-hero card mb-3">
    <div class="card-body d-flex flex-column flex-md-row align-items-md-center gap-3">
        @if ($employee->photo_path)
            <span class="crm-entity__logo crm-profile-hero__avatar p-0 overflow-hidden">
                <img src="/storage/{{ ltrim($employee->photo_path, '/') }}" alt="{{ $fullName }}"
                     class="w-100 h-100 d-block"
                     style="object-fit: cover; object-position: center 22%; border-radius: 18px;">
            </span>
        @else
            <span class="crm-entity__logo crm-profile-hero__avatar">{{ $initials }}</span>
        @endif
        <div class="flex-grow-1 min-w-0">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h1 class="page-title mb-0">{{ $fullName }}</h1>
                <span class="crm-chip crm-chip--grey">{{ $employee->employee_no }}</span>
                <span class="crm-chip {{ $statusClass }}"><i class="ti ti-{{ $statusIcon }} me-1"></i>{{ $crm_label('employee_status', $employee->status->value) }}</span>
            </div>
            <div class="text-secondary mt-1">
                {{ $employee->position?->name ?? __('должность не назначена') }}
                · {{ $employee->department?->name ?? __('отдел не назначен') }}
            </div>
        </div>
        <div class="d-flex gap-2 d-print-none">
            <a class="btn btn-ghost-secondary" href="{{ route('employees.index') }}"><i class="ti ti-arrow-left me-1"></i>{{ __('К списку') }}</a>
            @if ($canManage)
                <button type="button" class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#employee-editor" aria-expanded="false" aria-controls="employee-editor"><i class="ti ti-pencil me-1"></i>{{ __('Редактировать') }}</button>
                <button type="button" class="btn btn-outline-primary" data-crm-tab="#tab-roles-btn"><i class="ti ti-key me-1"></i>{{ __('Роли') }}</button>
            @endif
        </div>
    </div>
</div>

{{-- ===== Вкладки ===== --}}
<ul class="nav crm-tabs d-print-none" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-profile-btn" data-bs-toggle="tab" data-bs-target="#tab-profile" type="button" role="tab" aria-controls="tab-profile" aria-selected="true">{{ __('Профиль') }}</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-roles-btn" data-bs-toggle="tab" data-bs-target="#tab-roles" type="button" role="tab" aria-controls="tab-roles" aria-selected="false">
            {{ __('Роли') }} <span class="badge bg-secondary-lt ms-1">{{ $employee->roles->count() }}</span>
        </button>
    </li>
</ul>

<div class="tab-content mt-3">
    {{-- ===== Вкладка: профиль ===== --}}
    <div class="tab-pane fade show active" id="tab-profile" role="tabpanel" aria-labelledby="tab-profile-btn">
        <div class="row g-3">
            <div class="col-lg-8">
                {{-- Карточка фактов: всё главное — одним взглядом, без чтения строк dl --}}
                <div class="crm-form__section mb-3">
                    <h3>{{ __('Карточка сотрудника') }}</h3>
                    <div class="crm-form__desc">{{ __('Контакты и трудоустройство. Нажмите на e-mail или телефон, чтобы написать/позвонить') }}</div>

                    <div class="crm-facts">
                        <div class="crm-fact">
                            <span class="crm-fact__icon" style="background: rgba(66, 153, 225, .14); color: var(--crm-info);"><i class="ti ti-hash"></i></span>
                            <div class="min-w-0">
                                <div class="crm-fact__label">{{ __('Табельный №') }}</div>
                                <div class="crm-fact__value">{{ $employee->employee_no }}</div>
                            </div>
                        </div>

                        <div class="crm-fact">
                            <span class="crm-fact__icon" style="background: rgba(47, 179, 68, .14); color: var(--crm-success);"><i class="ti ti-{{ $statusIcon }}"></i></span>
                            <div class="min-w-0">
                                <div class="crm-fact__label">{{ __('Статус') }}</div>
                                <div class="crm-fact__value">{{ $crm_label('employee_status', $employee->status->value) }}</div>
                            </div>
                        </div>

                        <div class="crm-fact">
                            <span class="crm-fact__icon" style="background: rgba(124, 92, 224, .14); color: var(--crm-purple);"><i class="ti ti-building-community"></i></span>
                            <div class="min-w-0">
                                <div class="crm-fact__label">{{ __('Подразделение') }}</div>
                                <div class="crm-fact__value">{{ $employee->department?->name ?? __('не назначен') }}</div>
                            </div>
                        </div>

                        <div class="crm-fact">
                            <span class="crm-fact__icon" style="background: rgba(247, 103, 7, .14); color: var(--crm-warning);"><i class="ti ti-briefcase"></i></span>
                            <div class="min-w-0">
                                <div class="crm-fact__label">{{ __('Должность') }}</div>
                                <div class="crm-fact__value">{{ $employee->position?->name ?? __('не назначена') }}</div>
                            </div>
                        </div>

                        <div class="crm-fact">
                            <span class="crm-fact__icon" style="background: rgba(151, 163, 182, .16); color: var(--crm-text-secondary);"><i class="ti ti-clock-hour-4"></i></span>
                            <div class="min-w-0">
                                <div class="crm-fact__label">{{ __('График') }}</div>
                                <div class="crm-fact__value">{{ $employee->workSchedule?->name ?? '—' }}</div>
                            </div>
                        </div>

                        <div class="crm-fact">
                            <span class="crm-fact__icon" style="background: rgba(11, 155, 114, .14); color: var(--crm-success);"><i class="ti ti-calendar-event"></i></span>
                            <div class="min-w-0">
                                <div class="crm-fact__label">{{ __('Приём на работу') }}</div>
                                <div class="crm-fact__value">{{ $employee->hired_at?->format('d.m.Y') ?? '—' }}</div>
                            </div>
                        </div>

                        @if ($canViewProps)
                            <div class="crm-fact">
                                <span class="crm-fact__icon" style="background: rgba(66, 153, 225, .14); color: var(--crm-info);"><i class="ti ti-mail"></i></span>
                                <div class="min-w-0">
                                    <div class="crm-fact__label">{{ __('Рабочий e-mail') }}</div>
                                    <div class="crm-fact__value">
                                        @if ($employee->email)
                                            <a href="mailto:{{ $employee->email }}">{{ $employee->email }}</a>
                                        @else
                                            <span class="text-secondary">{{ __('не указан') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="crm-fact">
                                <span class="crm-fact__icon" style="background: rgba(47, 179, 68, .14); color: var(--crm-success);"><i class="ti ti-phone"></i></span>
                                <div class="min-w-0">
                                    <div class="crm-fact__label">{{ __('Телефон') }}</div>
                                    <div class="crm-fact__value">
                                        @if ($employee->phone)
                                            <a href="tel:{{ preg_replace('/[^\d+]/', '', $employee->phone) }}">{{ $employee->phone }}</a>
                                        @else
                                            <span class="text-secondary">{{ __('не указан') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {{-- данные из профиля (заполняет сотрудник на /profile) --}}
                            <div class="crm-fact">
                                <span class="crm-fact__icon" style="background: rgba(247, 103, 7, .14); color: var(--crm-warning);"><i class="ti ti-cake"></i></span>
                                <div class="min-w-0">
                                    <div class="crm-fact__label">{{ __('Дата рождения') }}</div>
                                    <div class="crm-fact__value">
                                        {{ $employee->birth_date?->format('d.m.Y') ?? '—' }}
                                    </div>
                                </div>
                            </div>

                            <div class="crm-fact">
                                <span class="crm-fact__icon" style="background: rgba(124, 92, 224, .14); color: var(--crm-purple);"><i class="ti ti-map-pin"></i></span>
                                <div class="min-w-0">
                                    <div class="crm-fact__label">{{ __('Адрес') }}</div>
                                    <div class="crm-fact__value">
                                        @if ($employee->address)
                                            {{ $employee->address }}
                                        @else
                                            <span class="text-secondary">{{ __('не указан') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="crm-fact">
                                <span class="crm-fact__icon" style="background: rgba(66, 153, 225, .14); color: var(--crm-info);"><i class="ti ti-brand-telegram"></i></span>
                                <div class="min-w-0">
                                    <div class="crm-fact__label">Telegram</div>
                                    <div class="crm-fact__value">
                                        @if ($employee->telegram_chat_id)
                                            {{ $employee->telegram_chat_id }}
                                        @else
                                            <span class="text-secondary">{{ __('не указан') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="crm-fact">
                                <span class="crm-fact__icon" style="background: rgba(151, 163, 182, .16); color: var(--crm-text-secondary);"><i class="ti ti-lock"></i></span>
                                <div class="min-w-0">
                                    <div class="crm-fact__label">{{ __('Контакты') }}</div>
                                    <div class="crm-fact__value text-secondary">{{ __('Реквизиты скрыты') }}</div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                @if ($canManage)
                    {{-- ===== Форма редактирования: раскрывается кнопкой «Редактировать» ===== --}}
                    <div class="collapse" id="employee-editor">
                    <form method="post" action="{{ route('employees.update', $employee) }}" class="crm-form" data-dirty-guard data-submit-guard>
                        @csrf @method('PATCH')

                        <div class="crm-form__section">
                            <h3>{{ __('Личные данные') }}</h3>
                            <div class="crm-form__desc">{{ __('Как будет показано в документах и табеле') }}</div>
                            <div class="crm-form__row crm-form__row--3">
                                <div class="crm-form__field">
                                    <label class="form-label" for="ep-last">{{ __('Фамилия') }}<span class="req">*</span></label>
                                    <input type="text" id="ep-last" name="last_name" class="form-control" value="{{ $employee->last_name }}" autocomplete="family-name" required>
                                </div>
                                <div class="crm-form__field">
                                    <label class="form-label" for="ep-first">{{ __('Имя') }}<span class="req">*</span></label>
                                    <input type="text" id="ep-first" name="first_name" class="form-control" value="{{ $employee->first_name }}" autocomplete="given-name" required>
                                </div>
                                <div class="crm-form__field">
                                    <label class="form-label" for="ep-middle">{{ __('Отчество') }}</label>
                                    <input type="text" id="ep-middle" name="middle_name" class="form-control" value="{{ $employee->middle_name }}" autocomplete="additional-name" placeholder="{{ __("если есть") }}">
                                </div>
                            </div>
                        </div>

                        <div class="crm-form__section">
                            <h3>{{ __('Контакты') }}</h3>
                            <div class="crm-form__desc">{{ __('Для уведомлений и документов. Формат зависит от страны — страна выбирается автоматически по часовому поясу компании') }}</div>
                            <div class="crm-form__row">
                                <div class="crm-form__field">
                                    <label class="form-label" for="ep-email">E-mail</label>
                                    <input type="email" id="ep-email" name="email" class="form-control" value="{{ $employee->email }}" autocomplete="email" placeholder="name@company.com">
                                </div>
                                @include('partials.phone-field', [
                                    'id' => 'ep-phone',
                                    'name' => 'phone',
                                    'value' => $employee->phone,
                                    'hint' => __('Формат зависит от страны — страна выбирается автоматически по часовому поясу компании.'),
                                    'timezone' => $companyTimezone ?? null,
                                ])                            </div>
                        </div>

                        <div class="crm-form__section">
                            <h3>{{ __('Трудоустройство') }}</h3>
                            <div class="crm-form__desc">{{ __('Табельный номер, подразделение, должность, график и статус') }}</div>
                            <div class="crm-form__row crm-form__row--3">
                                <div class="crm-form__field">
                                    <label class="form-label" for="ep-no">{{ __('Табельный №') }}<span class="req">*</span></label>
                                    <input type="text" id="ep-no" name="employee_no" class="form-control" value="{{ $employee->employee_no }}" required>
                                    <div class="form-text">{{ __('Уникален внутри компании') }}</div>
                                </div>
                                <div class="crm-form__field">
                                    <label class="form-label" for="ep-status">{{ __('Статус') }}</label>
                                    <select id="ep-status" name="status" class="form-select">
                                        @foreach (['active', 'inactive', 'dismissed'] as $status)
                                            <option value="{{ $status }}" @selected($employee->status->value === $status)>{{ $crm_label('employee_status', $status) }}</option>
                                        @endforeach
                                    </select>
                                    <label class="form-label mt-2" for="ep-fired-at">{{ __('Дата увольнения') }}</label>
                                    <input id="ep-fired-at" class="form-control" type="date" name="fired_at" value="{{ old('fired_at', $employee->fired_at?->format('Y-m-d')) }}" max="{{ today()->format('Y-m-d') }}">
                                </div>
                                <div class="crm-form__field">
                                    <label class="form-label" for="ep-schedule">{{ __('График работы') }}</label>
                                    <select id="ep-schedule" name="work_schedule_id" class="form-select">
                                        <option value="">{{ __('не назначен') }}</option>
                                        @foreach ($schedules as $schedule)
                                            <option value="{{ $schedule->id }}" @selected($employee->work_schedule_id === $schedule->id)>{{ $schedule->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="crm-form__field">
                                    <label class="form-label" for="ep-dept">{{ __('Подразделение') }}</label>
                                    <select id="ep-dept" name="department_id" class="form-select">
                                        <option value="">{{ __('не назначен') }}</option>
                                        @foreach ($departments as $department)
                                            <option value="{{ $department->id }}" @selected($employee->department_id === $department->id)>{{ $department->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="crm-form__field">
                                    <label class="form-label" for="ep-pos">{{ __('Должность') }}</label>
                                    <select id="ep-pos" name="position_id" class="form-select">
                                        <option value="">{{ __('не назначена') }}</option>
                                        @foreach ($positions as $position)
                                            <option value="{{ $position->id }}" @selected($employee->position_id === $position->id)>{{ $position->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="crm-form__footer crm-form__footer--card">
                            <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>{{ __('Сохранить изменения') }}</button>
                            <button type="button" class="btn btn-ghost-secondary" data-bs-toggle="collapse" data-bs-target="#employee-editor">{{ __('Отмена') }}</button>
                            <span class="text-secondary ms-auto small d-none d-md-inline">{{ __('Есть несохранённые правки — уход со страницы будет перехвачен') }}</span>
                        </div>
                    </form>
                    </div>
                @endif
            </div>

            {{-- Боковая колонка: доступ и быстрые действия --}}
            <div class="col-lg-4">
                <div class="crm-form__section mb-3">
                    <h3>{{ __('Доступ в системе') }}</h3>
                    <div class="crm-form__desc">{{ __('Роли определяют, какие модули доступны') }}</div>
                    <div class="d-flex flex-wrap gap-2">
                        @forelse ($employee->roles as $role)
                            <span class="crm-chip">{{ $role->name }}</span>
                        @empty
                            <span class="text-secondary">{{ __('Роли не назначены') }}</span>
                        @endforelse
                    </div>
                    <button type="button" class="btn btn-ghost-secondary w-100 justify-content-center mt-3 d-print-none" data-crm-tab="#tab-roles-btn">
                        <i class="ti ti-key me-1"></i>{{ __('Управление ролями') }}
                    </button>
                </div>

                <div class="crm-form__section">
                    <h3>{{ __('Быстрые действия') }}</h3>
                    <div class="d-flex flex-column gap-2">
                        <a class="btn btn-ghost-secondary w-100 justify-content-start" href="{{ route('employees.subordinates', $employee) }}">
                            <i class="ti ti-sitemap me-2"></i>{{ __('Прямые подчинённые') }}
                        </a>
                        <a class="btn btn-ghost-secondary w-100 justify-content-start" href="{{ route('employees.index') }}">
                            <i class="ti ti-users me-2"></i>{{ __('Все сотрудники') }}
                        </a>
                        @if ($canManage)
                            <button type="button" class="btn btn-primary w-100 justify-content-start" data-bs-toggle="collapse" data-bs-target="#employee-editor">
                                <i class="ti ti-pencil me-2"></i>{{ __('Редактировать карточку') }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===== Вкладка: роли ===== --}}
    <div class="tab-pane fade" id="tab-roles" role="tabpanel" aria-labelledby="tab-roles-btn">
        <div class="row g-3">
            <div class="col-lg-8">
                <div class="crm-form__section">
                    <h3>{{ __('Роли и доступ') }}</h3>
                    <div class="crm-form__desc">{{ __('Отметьте роли и нажмите «Обновить». Новые права применяются сразу после сохранения') }}</div>
                    @if ($canManage)
                        <form method="post" action="{{ route('employees.roles', $employee) }}" data-submit-guard>
                            @csrf @method('PUT')
                            <div class="crm-role-cards">
                                @foreach (\App\Models\Role::query()->orderBy('name')->get() as $role)
                                    <label class="crm-role-card">
                                        <input type="checkbox" class="crm-role-card__input" name="role_ids[]" value="{{ $role->id }}" @checked($employee->roles->contains('id', $role->id))>
                                        <span class="crm-role-card__check"><i class="ti ti-check"></i></span>
                                        <span class="crm-role-card__icon"><i class="ti ti-{{ in_array($role->slug, ['admin', 'superadmin', 'super_admin']) ? 'shield-check' : ($role->slug === 'manager' || str_contains($role->slug, 'manager') ? 'briefcase' : 'key') }}"></i></span>
                                        <span class="crm-role-card__name">{{ $role->name }}</span>
                                        <span class="crm-role-card__slug">{{ $role->slug }}</span>
                                        @if (!empty($role->description))
                                            <span class="crm-role-card__desc">{{ $role->description }}</span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                            <div class="crm-form__footer mt-3">
                                <button class="btn btn-primary"><i class="ti ti-key me-1"></i>{{ __('Обновить роли') }}</button>
                            </div>
                        </form>
                    @else
                        <div class="d-flex flex-wrap gap-2">
                            @forelse ($employee->roles as $role)
                                <span class="crm-chip">{{ $role->name }}</span>
                            @empty
                                <span class="text-secondary">{{ __('Роли не назначены') }}</span>
                            @endforelse
                        </div>
                    @endif
                </div>
            </div>
            <div class="col-lg-4">
                <div class="crm-form__section">
                    <h3>{{ __('Назначено сейчас') }}</h3>
                    <div class="crm-form__desc">{{ __('Актуальный набор ролей') }}</div>
                    <div class="d-flex flex-wrap gap-2" id="roles">
                        @forelse ($employee->roles as $role)
                            <span class="crm-chip">{{ $role->name }}</span>
                        @empty
                            <span class="text-secondary">{{ __('Роли не назначены') }}</span>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
    .crm-role-cards {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
        gap: .75rem;
    }
    .crm-role-card {
        position: relative;
        display: flex;
        flex-direction: column;
        gap: .15rem;
        padding: .9rem 1rem .95rem;
        border: 1px solid var(--tblr-border-color, var(--crm-border));
        border-radius: .75rem;
        background: var(--tblr-bg-surface, #fff);
        cursor: pointer;
        transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
    }
    .crm-role-card:hover {
        border-color: rgba(var(--tblr-primary-rgb, 38, 108, 242), .45);
        transform: translateY(-1px);
    }
    .crm-role-card__input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }
    .crm-role-card__check {
        position: absolute;
        top: .6rem;
        right: .65rem;
        width: 1.25rem;
        height: 1.25rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1.5px solid var(--tblr-border-color, var(--crm-border-strong));
        border-radius: 50%;
        color: transparent;
        font-size: .7rem;
        transition: all .15s ease;
    }
    .crm-role-card__input:checked ~ .crm-role-card__check {
        background: var(--tblr-primary, var(--crm-info));
        border-color: var(--tblr-primary, var(--crm-info));
        color: #fff;
    }
    .crm-role-card:has(.crm-role-card__input:checked) {
        border-color: var(--tblr-primary, var(--crm-info));
        box-shadow: 0 0 0 3px rgba(var(--tblr-primary-rgb, 38, 108, 242), .14);
    }
    .crm-role-card__icon {
        width: 2.1rem;
        height: 2.1rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: .35rem;
        border-radius: .55rem;
        background: rgba(var(--tblr-primary-rgb, 38, 108, 242), .1);
        color: var(--tblr-primary, var(--crm-info));
        font-size: 1.1rem;
    }
    .crm-role-card__input:checked ~ .crm-role-card__icon {
        background: var(--tblr-primary, var(--crm-info));
        color: #fff;
    }
    .crm-role-card__name { font-weight: 650; line-height: 1.25; }
    .crm-role-card__slug { font-size: .72rem; color: var(--tblr-secondary, var(--crm-text-secondary)); }
    .crm-role-card__desc {
        margin-top: .35rem;
        font-size: .78rem;
        line-height: 1.35;
        color: var(--tblr-secondary, var(--crm-text-secondary));
    }
    @media (max-width: 576px) {
        .crm-role-cards { grid-template-columns: 1fr; }
    }
</style>
@endpush
@endsection
