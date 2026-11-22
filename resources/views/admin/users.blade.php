@extends('layouts.tabler')
@section('workspace_layout', 'directory')

@section('title', __('Учётные записи'))

@php
    $withoutCard = $users->filter(fn ($user) => $user->employee === null)->count();
    $withRoles = $users->filter(fn ($user) => isset($roleMap[$user->id]))->count();
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Система') }}</div>
            <h1 class="page-title mt-1">{{ __('Учётные записи') }}</h1>
            <div class="text-secondary mt-1">{{ __('Вход в систему, привязка к карточкам сотрудников, сброс паролей') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newUser">
                <i class="ti ti-plus me-1"></i>{{ __('Новая учётка') }}
            </button>
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-key"></i>{{ __('Учётных записей') }}</div>
        <div class="crm-metric__value">{{ $users->count() }}</div>
        <div class="crm-metric__hint">{{ __('максимум 300 в списке') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-id-badge-2"></i>{{ __('С карточкой сотрудника') }}</div>
        <div class="crm-metric__value">{{ $users->count() - $withoutCard }}</div>
        <div class="crm-metric__hint">{{ __('имеют привязку') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-alert-triangle"></i>{{ __('Без карточки') }}</div>
        <div class="crm-metric__value">{{ $withoutCard }}</div>
        <div class="crm-metric__hint">{{ __('вход есть, сотрудника нет') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-purple);"><i class="ti ti-shield"></i>{{ __('С ролями') }}</div>
        <div class="crm-metric__value">{{ $withRoles }}</div>
        <div class="crm-metric__hint">{{ __('права выданы через сотрудника') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-text-secondary);"><i class="ti ti-link"></i>{{ __('Кандидатов на привязку') }}</div>
        <div class="crm-metric__value">{{ $candidates->count() }}</div>
        <div class="crm-metric__hint">{{ __('сотрудники без учётки') }}</div>
    </div>
</div>

<div class="crm-toolbar">
    <div class="input-icon">
        <input type="search" class="form-control crm-search-input" placeholder="{{ __("Фильтр по e-mail, имени, роли…") }}"
               data-crm-filter=".users-table tbody tr" data-crm-filter-count="#users-count" aria-label="{{ __("Фильтр учётных записей") }}">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <div class="crm-toolbar__spacer"></div>
    <span class="crm-toolbar__count" id="users-count">{{ __('Показано') }} {{ $users->count() }}</span>
</div>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="1" class="table table-hover table-vcenter card-table mb-0 crm-thead-sticky users-table">
            <thead>
                <tr>
                    <th scope="col">E-mail</th>
                    <th scope="col">{{ __('Имя') }}</th>
                    <th scope="col">{{ __('Сотрудник') }}</th>
                    <th scope="col">{{ __('Роли') }}</th>
                    <th scope="col" class="text-nowrap">{{ __('Сброс пароля') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td>
                            <span class="crm-table__main">{{ $user->email }}</span>
                            <div class="crm-table__sub">{{ __('вход с') }} {{ $user->created_at?->format('d.m.Y') ?? '—' }}</div>
                        </td>
                        <td>{{ $user->name }}</td>
                        <td>
                            @if ($user->employee)
                                <span class="crm-table__main">{{ $user->employee->last_name }} {{ $user->employee->first_name }}</span>
                                <div class="crm-table__sub">{{ __('таб.№') }} {{ $user->employee->employee_no }} · {{ $user->employee->department?->name ?? __('без отдела') }}</div>
                            @elseif ($employeesWithUser->contains($user->id) === false)
                                <span class="crm-chip crm-chip--orange">{{ __('без карточки') }}</span>
                            @else
                                <span class="text-secondary">—</span>
                            @endif
                        </td>
                        <td class="small">
                            @if (isset($roleMap[$user->id]))
                                <div class="d-flex gap-1 flex-wrap">
                                    @foreach ($roleMap[$user->id] as $roleName)
                                        <span class="crm-chip crm-chip--cyan">{{ $roleName }}</span>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-secondary">{{ __('роли не выданы') }}</span>
                            @endif
                        </td>
                        <td>
                            <form method="post" action="{{ route('users.password', $user) }}" data-submit-guard class="d-flex gap-2"
                                  onsubmit="return confirm('{{ __('Сбросить пароль для                   ? Пользователь потеряет доступ до установки нового пароля') }}.')">
                                @csrf
                                <input type="password" name="password" class="form-control form-control-sm" placeholder="{{ __("новый пароль") }}" title="{{ __("Оставьте пустым — пароль будет сгенерирован") }}" aria-label="{{ __('Новый пароль для') }} {{ $user->email }}">
                                <button class="btn btn-sm btn-outline-secondary text-nowrap" style="flex: none;" title="{{ __("Сбросить или задать пароль") }}">{{ __('Сбросить') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            @include('partials.empty-state', ['icon' => 'key', 'title' => __('Учёток нет'), 'text' => __('Создайте первую учётную запись, чтобы сотрудник мог войти в систему.')])
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- ===== Новая учётная запись ===== --}}
<div class="modal modal-blur fade" id="newUser" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form method="post" action="{{ route('users.store') }}" data-submit-guard>
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Новая учётная запись') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __("Закрыть") }}"></button>
                </div>
                <div class="modal-body">
                    <div class="crm-form__section">
                        <h3>{{ __('Кто входит в систему') }}</h3>
                        <div class="crm-form__desc">{{ __('Имя отображается в журнале действий, e-mail — это логин') }}</div>
                        <div class="crm-form__field">
                            <label class="form-label" for="user-name">{{ __('Имя') }} <span class="req">*</span></label>
                            <input type="text" id="user-name" name="name" class="form-control" value="{{ old('name') }}" required maxlength="150" placeholder="{{ __("Иванов Иван") }}">
                        </div>
                        <div class="crm-form__field mb-0">
                            <label class="form-label" for="user-email">E-mail <span class="req">*</span></label>
                            <input type="email" id="user-email" name="email" class="form-control" value="{{ old('email') }}" required maxlength="150" placeholder="name@corpms.demo">
                            <div class="form-hint">{{ __('должен быть уникальным') }}</div>
                        </div>
                    </div>

                    <div class="crm-form__section">
                        <h3>{{ __('Привязка к сотруднику') }}</h3>
                        <div class="crm-form__desc">{{ __('Роли и права выдаются карточке сотрудника — привяжите её при создании') }}</div>
                        <div class="crm-form__field mb-0">
                            <label class="form-label" for="user-employee">{{ __('Сотрудник') }}</label>
                            <select id="user-employee" name="employee_id" class="form-select">
                                <option value="">{{ __('без карточки') }}</option>
                                @foreach ($candidates as $employee)
                                    <option value="{{ $employee->id }}" @selected((string) old('employee_id') === (string) $employee->id)>{{ $employee->last_name }} {{ $employee->first_name }} ({{ $employee->employee_no }})</option>
                                @endforeach
                            </select>
                            <div class="form-hint">{{ __('в списке только сотрудники, у которых ещё нет учётки') }}</div>
                        </div>
                    </div>

                    <div class="crm-form__section mb-0">
                        <h3>{{ __('Пароль') }}</h3>
                        <div class="crm-form__desc">{{ __('Можно задать сразу — минимум 12 символов') }}</div>
                        <div class="crm-form__field mb-0">
                            <label class="form-label" for="user-password">{{ __('Пароль (опционально)') }}</label>
                            <input type="password" id="user-password" name="password" class="form-control" minlength="12" autocomplete="new-password" placeholder="{{ __("оставьте пустым — сгенерируем") }}">
                            <div class="form-hint">{{ __('если пусто, одноразовый пароль покажется в сообщении после создания') }}</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                    <button type="submit" class="btn btn-primary"><i class="ti ti-plus me-1"></i>{{ __('Создать учётку') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
