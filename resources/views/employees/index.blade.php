@extends('layouts.tabler')
@section('workspace_layout', 'directory')

@section('title', __('Сотрудники'))
@section('body_class', 'bm-people-directory')

@php
    $employeeCards = collect($employees->items())->map(static function ($e) {
        $initials = mb_strtoupper(mb_substr($e->first_name, 0, 1) . mb_substr($e->last_name, 0, 1));
        $statusClass = match ($e->status->value) {
            'active' => 'crm-chip--green',
            'dismissed' => 'crm-chip--red',
            default => 'crm-chip--grey',
        };
        return ['employee' => $e, 'initials' => $initials !== '' ? $initials : 'CM', 'statusClass' => $statusClass];
    });
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Кадры') }}</div>
            <h1 class="page-title mt-1">{{ __('Сотрудники') }}</h1>
            <div class="text-secondary mt-1">{{ __('Всего') }} {{ $employees->total() }} {{ __('показаны') }} {{ $employees->firstItem() ?? 0 }}–{{ $employees->lastItem() ?? 0 }}</div>
        </div>
        @if ($canManage)
        <div class="col-auto d-print-none">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newEmployee">
                <i class="ti ti-plus me-1"></i>{{ __('Принять сотрудника') }}
            </button>
        </div>
        @endif
    </div>
</div>

<form method="get" class="crm-toolbar" data-crm-keep>
    <div class="input-icon">
        <input type="text" name="q" value="{{ $search }}" class="form-control crm-search-input" placeholder="{{ __("Поиск ФИО/номер…") }}" aria-label="{{ __("Поиск сотрудников") }}">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <select name="department_id" class="form-select" aria-label="{{ __("Отдел") }}">
        <option value="">{{ __('отдел') }}</option>
        @foreach ($departments as $department)
            <option value="{{ $department->id }}" @selected((string) request('department_id') === (string) $department->id)>{{ $department->name }}</option>
        @endforeach
    </select>
    <select name="status" class="form-select" aria-label="{{ __("Статус") }}">
        <option value="" @selected(!request('status'))>{{ __('активные') }}</option>
        <option value="inactive" @selected(request('status') === 'inactive')">{{ __('неактивные') }}</option>
        <option value="dismissed" @selected(request('status') === 'dismissed')">{{ __('уволенные') }}</option>
    </select>
    <button class="btn btn-primary"><i class="ti ti-search me-1"></i>{{ __('Найти') }}</button>
    <div class="crm-toolbar__spacer"></div>
    <div class="crm-switch" data-crm-switch="employees" role="group" aria-label="{{ __("Вид списка") }}">
        <button type="button" data-view="list"><i class="ti ti-list"></i>{{ __('Список') }}</button>
        <button type="button" data-view="cards"><i class="ti ti-layout-grid"></i>{{ __('Карты') }}</button>
    </div>
</form>

<div data-crm-view-container="employees" data-view-default="list">
    {{-- ===== вид: таблица ===== --}}
    <div data-view-only="list">
        <div class="card">
            <div class="table-responsive">
                <table data-record-key="1" class="table table-hover table-vcenter card-table">
                    <thead><tr><th scope="col">№</th><th scope="col">{{ __('ФИО') }}</th><th scope="col">{{ __('Отдел') }}</th><th scope="col">{{ __('Должность') }}</th><th scope="col">{{ __('Роли') }}</th><th scope="col">{{ __('Статус') }}</th><th scope="col"></th></tr></thead>
                    <tbody>
                        @forelse ($employees as $employee)
                            <tr>
                                <td class="text-secondary crm-table-num">{{ $employee->employee_no }}</td>
                                <td>
                                    <a href="{{ route('employees.show', $employee) }}" class="text-reset text-decoration-none">
                                        <span class="crm-table__main">{{ $employee->last_name }} {{ $employee->first_name }}{{ $employee->middle_name ? ' ' . $employee->middle_name : '' }}</span>
                                    </a>
                                </td>
                                <td class="text-secondary">{{ $employee->department?->name ?? '—' }}</td>
                                <td class="text-secondary">{{ $employee->position?->name ?? '—' }}</td>
                                <td>
                                    @foreach ($employee->roles as $role)
                                        <span class="crm-chip crm-chip--grey me-1">{{ $role->name }}</span>
                                    @endforeach
                                </td>
                                <td>
                                    <span class="crm-chip {{ $employee->status->value === 'active' ? 'crm-chip--green' : ($employee->status->value === 'dismissed' ? 'crm-chip--red' : 'crm-chip--grey') }}">
                                        {{ $crm_label('employee_status', $employee->status->value) }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-ghost-secondary crm-row-actions" href="{{ route('employees.show', $employee) }}" title="{{ __("Профиль") }}"><i class="ti ti-chevron-right"></i></a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7">@include('partials.empty-state', ['icon' => 'users', 'title' => __('Сотрудников не найдено'), 'text' => __('Измените фильтры или примите нового сотрудника.')])</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($employees->hasPages())
                <div class="card-footer d-flex align-items-center">
                    <div class="text-secondary me-auto">{{ __('Страница') }} {{ $employees->currentPage() }} {{ __('из') }} {{ $employees->lastPage() }}</div>
                    {{ $employees->links('pagination::tabler') }}
                </div>
            @endif
        </div>
    </div>

    {{-- ===== вид: карточки директории ===== --}}
    <div data-view-only="cards">
        <div class="row row-cards g-3">
            @forelse ($employeeCards as $entry)
                @php($emp = $entry['employee'])
                <div class="col-12 col-md-6 col-xl-4">
                    <a class="crm-entity h-100" href="{{ route('employees.show', $emp) }}">
                        <div class="crm-entity__head">
                            <span class="crm-entity__logo">{{ $entry['initials'] }}</span>
                            <div class="min-w-0">
                                <div class="crm-entity__name">{{ $emp->last_name }} {{ $emp->first_name }}</div>
                                <div class="crm-entity__sub">{{ $emp->position?->name ?? __('должность не назначена') }}</div>
                            </div>
                        </div>
                        <div class="crm-entity__meta">
                            <span><i class="ti ti-sitemap me-1"></i>{{ $emp->department?->name ?? '—' }}</span>
                            <span><i class="ti ti-hash me-1"></i>{{ $emp->employee_no }}</span>
                        </div>
                        <div class="crm-entity__footer">
                            <div class="d-flex gap-1 flex-wrap">
                                <span class="crm-chip {{ $entry['statusClass'] }}">{{ $crm_label('employee_status', $emp->status->value) }}</span>
                                @foreach ($emp->roles->take(2) as $role)
                                    <span class="crm-chip crm-chip--grey">{{ $role->name }}</span>
                                @endforeach
                            </div>
                            <i class="ti ti-arrow-right-right text-secondary"></i>
                        </div>
                    </a>
                </div>
            @empty
                <div class="col-12">
                    @include('partials.empty-state', ['icon' => 'users', 'title' => __('Сотрудников не найдено'), 'text' => __('Измените фильтры или примите нового сотрудника.')])
                </div>
            @endforelse
        </div>
        @if ($employees->hasPages())
            <div class="d-flex align-items-center mt-3">
                <div class="text-secondary me-auto">{{ __('Страница') }} {{ $employees->currentPage() }} {{ __('из') }} {{ $employees->lastPage() }}</div>
                {{ $employees->links('pagination::tabler') }}
            </div>
        @endif
    </div>
</div>

@if ($canManage)
<div class="modal modal-blur fade" id="newEmployee" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('employees.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Новый сотрудник') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Личные данные') }}</h3>
                    <div class="crm-form__desc">{{ __('ФИО как в паспорте — используется в документах') }}</div>
                    <div class="crm-form__row--3 crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ne-last">{{ __('Фамилия') }}<span class="req">*</span></label>
                            <input type="text" id="ne-last" name="last_name" class="form-control" value="{{ old('last_name') }}" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ne-first">{{ __('Имя') }}<span class="req">*</span></label>
                            <input type="text" id="ne-first" name="first_name" class="form-control" value="{{ old('first_name') }}" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ne-middle">{{ __('Отчество') }}</label>
                            <input type="text" id="ne-middle" name="middle_name" class="form-control" value="{{ old('middle_name') }}">
                        </div>
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>{{ __('Трудоустройство') }}</h3>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ne-no">{{ __('Табельный номер') }}<span class="req">*</span></label>
                            <input type="text" id="ne-no" name="employee_no" class="form-control" value="{{ old('employee_no') }}" placeholder="IF-112" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ne-hired">{{ __('Дата приёма') }}</label>
                            <input type="date" id="ne-hired" name="hired_at" class="form-control" value="{{ old('hired_at', now()->toDateString()) }}">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ne-dept">{{ __('Подразделение') }}</label>
                            <select id="ne-dept" name="department_id" class="form-select">
                                <option value="">{{ __('не назначен') }}</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department->id }}" @selected((string) old('department_id') === (string) $department->id)>{{ $department->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ne-pos">{{ __('Должность') }}</label>
                            <select id="ne-pos" name="position_id" class="form-select">
                                <option value="">{{ __('не назначена') }}</option>
                                @foreach ($positions as $position)
                                    <option value="{{ $position->id }}" @selected((string) old('position_id') === (string) $position->id)>{{ $position->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ne-status">{{ __('Статус') }}<span class="req">*</span></label>
                            <select id="ne-status" name="status" class="form-select" required>
                                <option value="active" @selected(old('status', 'active') === 'active')>{{ $crm_label('employee_status', 'active') }}</option>
                                <option value="inactive" @selected(old('status') === 'inactive')>{{ $crm_label('employee_status', 'inactive') }}</option>
                            </select>
                            <div class="form-text">{{ __('Работает» — сотрудник принят и виден в списках') }}</div>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Контакты') }}</h3>
                    <div class="crm-form__row">
                        @include('partials.phone-field', [
                            'id' => 'ne-phone',
                            'name' => 'phone',
                            'value' => old('phone'),
                            'hint' => __('Формат зависит от страны — страна выбирается автоматически по часовому поясу компании.'), 'timezone' => $companyTimezone ?? null,
                        ])
                        <div class="crm-form__field">
                            <label class="form-label" for="ne-email">{{ __('Служебный e-mail') }}</label>
                            <input type="email" id="ne-email" name="email" class="form-control" value="{{ old('email') }}">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ne-address">{{ __('Адрес проживания') }}</label>
                            <input type="text" id="ne-address" name="address" class="form-control" maxlength="255"
                                   value="{{ old('address') }}" placeholder="{{ __("г. Душанбе, ул. Рудаки, 1") }}">
                            <div class="form-text">{{ __('Нужен при оформлении кадровых документов') }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Оформить приём') }}</button>
            </div>
        </form>
    </div>
</div>
@endif
@endsection
