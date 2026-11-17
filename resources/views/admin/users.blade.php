@extends('layouts.tabler')

@section('title', 'Учётные записи')

@php
    $withoutCard = $users->filter(fn ($user) => $user->employee === null)->count();
    $withRoles = $users->filter(fn ($user) => isset($roleMap[$user->id]))->count();
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Система</div>
            <h2 class="page-title mt-1">Учётные записи</h2>
            <div class="text-secondary mt-1">Вход в систему, привязка к карточкам сотрудников, сброс паролей</div>
        </div>
        <div class="col-auto d-print-none">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newUser">
                <i class="ti ti-plus me-1"></i>Новая учётка
            </button>
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #0b9b72;"><i class="ti ti-key"></i>Учётных записей</div>
        <div class="crm-metric__value">{{ $users->count() }}</div>
        <div class="crm-metric__hint">максимум 300 в списке</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #4299e1;"><i class="ti ti-id-badge-2"></i>С карточкой сотрудника</div>
        <div class="crm-metric__value">{{ $users->count() - $withoutCard }}</div>
        <div class="crm-metric__hint">имеют привязку</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #f76707;"><i class="ti ti-alert-triangle"></i>Без карточки</div>
        <div class="crm-metric__value">{{ $withoutCard }}</div>
        <div class="crm-metric__hint">вход есть, сотрудника нет</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #ae3ec9;"><i class="ti ti-shield"></i>С ролями</div>
        <div class="crm-metric__value">{{ $withRoles }}</div>
        <div class="crm-metric__hint">права выданы через сотрудника</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #6c7a91;"><i class="ti ti-link"></i>Кандидатов на привязку</div>
        <div class="crm-metric__value">{{ $candidates->count() }}</div>
        <div class="crm-metric__hint">сотрудники без учётки</div>
    </div>
</div>

<div class="crm-toolbar">
    <div class="input-icon">
        <input type="search" class="form-control crm-search-input" placeholder="Фильтр по e-mail, имени, роли…"
               data-crm-filter=".users-table tbody tr" data-crm-filter-count="#users-count" aria-label="Фильтр учётных записей">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <div class="crm-toolbar__spacer"></div>
    <span class="crm-toolbar__count" id="users-count">Показано {{ $users->count() }}</span>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-vcenter card-table mb-0 crm-thead-sticky users-table">
            <thead>
                <tr>
                    <th>E-mail</th>
                    <th>Имя</th>
                    <th>Сотрудник</th>
                    <th>Роли</th>
                    <th style="width: 340px">Сброс пароля</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td>
                            <span class="crm-table__main">{{ $user->email }}</span>
                            <div class="crm-table__sub">вход с {{ $user->created_at?->format('d.m.Y') ?? '—' }}</div>
                        </td>
                        <td>{{ $user->name }}</td>
                        <td>
                            @if ($user->employee)
                                <span class="crm-table__main">{{ $user->employee->last_name }} {{ $user->employee->first_name }}</span>
                                <div class="crm-table__sub">таб.№ {{ $user->employee->employee_no }} · {{ $user->employee->department?->name ?? 'без отдела' }}</div>
                            @elseif ($employeesWithUser->contains($user->id) === false)
                                <span class="crm-chip crm-chip--orange">без карточки</span>
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
                                <span class="text-secondary">роли не выданы</span>
                            @endif
                        </td>
                        <td>
                            <form method="post" action="{{ route('users.password', $user) }}" data-submit-guard class="d-flex gap-2"
                                  onsubmit="return confirm('Сбросить пароль для {{ $user->email }}? Пользователь потеряет доступ до установки нового пароля.')">
                                @csrf
                                <input type="password" name="password" class="form-control form-control-sm" placeholder="новый пароль" title="Оставьте пустым — пароль будет сгенерирован" aria-label="Новый пароль для {{ $user->email }}">
                                <button class="btn btn-sm btn-outline-secondary text-nowrap" style="flex: none;" title="Сбросить или задать пароль">Сбросить</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            @include('partials.empty-state', ['icon' => 'key', 'title' => 'Учёток нет', 'text' => 'Создайте первую учётную запись, чтобы сотрудник мог войти в систему.'])
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
                    <h5 class="modal-title">Новая учётная запись</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">
                    <div class="crm-form__section">
                        <h3>Кто входит в систему</h3>
                        <div class="crm-form__desc">Имя отображается в журнале действий, e-mail — это логин.</div>
                        <div class="crm-form__field">
                            <label class="form-label" for="user-name">Имя <span class="req">*</span></label>
                            <input type="text" id="user-name" name="name" class="form-control" value="{{ old('name') }}" required maxlength="150" placeholder="Иванов Иван">
                        </div>
                        <div class="crm-form__field mb-0">
                            <label class="form-label" for="user-email">E-mail <span class="req">*</span></label>
                            <input type="email" id="user-email" name="email" class="form-control" value="{{ old('email') }}" required maxlength="150" placeholder="name@corpms.demo">
                            <div class="form-hint">должен быть уникальным</div>
                        </div>
                    </div>

                    <div class="crm-form__section">
                        <h3>Привязка к сотруднику</h3>
                        <div class="crm-form__desc">Роли и права выдаются карточке сотрудника — привяжите её при создании.</div>
                        <div class="crm-form__field mb-0">
                            <label class="form-label" for="user-employee">Сотрудник</label>
                            <select id="user-employee" name="employee_id" class="form-select">
                                <option value="">— без карточки —</option>
                                @foreach ($candidates as $employee)
                                    <option value="{{ $employee->id }}" @selected((string) old('employee_id') === (string) $employee->id)>{{ $employee->last_name }} {{ $employee->first_name }} ({{ $employee->employee_no }})</option>
                                @endforeach
                            </select>
                            <div class="form-hint">в списке только сотрудники, у которых ещё нет учётки</div>
                        </div>
                    </div>

                    <div class="crm-form__section mb-0">
                        <h3>Пароль</h3>
                        <div class="crm-form__desc">Можно задать сразу — минимум 12 символов.</div>
                        <div class="crm-form__field mb-0">
                            <label class="form-label" for="user-password">Пароль (опционально)</label>
                            <input type="password" id="user-password" name="password" class="form-control" minlength="12" autocomplete="new-password" placeholder="оставьте пустым — сгенерируем">
                            <div class="form-hint">если пусто, одноразовый пароль покажется в сообщении после создания</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary"><i class="ti ti-plus me-1"></i>Создать учётку</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
