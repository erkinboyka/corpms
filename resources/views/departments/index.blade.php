@extends('layouts.tabler')
@section('workspace_layout', 'directory')

@section('title', __('Отделы'))

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Организация') }}</div>
            <h1 class="page-title mt-1">{{ __('Отделы') }}</h1>
            <div class="text-secondary mt-1">{{ __('Структура подразделений') }} {{ $departments->count() }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('orgchart.index') }}"><i class="ti ti-binary-tree me-1"></i>{{ __('Оргструктура') }}</a>
            @can('checkBoxPermission', 'departments.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newDepartment">
                <i class="ti ti-plus me-1"></i>{{ __('Добавить подразделение') }}
            </button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-toolbar">
    <div class="input-icon">
        <input type="search" class="form-control crm-search-input" placeholder="{{ __("Поиск: название, код, руководитель…") }}" aria-label="{{ __("Поиск отделов") }}"
               data-crm-filter="#departments-table tbody tr" data-crm-filter-count="#departments-count">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <div class="crm-toolbar__spacer"></div>
    <span class="crm-toolbar__count" id="departments-count">{{ __('Показано') }} {{ $departments->count() }}</span>
</div>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table" id="departments-table">
            <thead>
                <tr><th scope="col">{{ __('Подразделение') }}</th><th scope="col">{{ __('Родитель') }}</th><th scope="col">{{ __('Руководитель') }}</th><th scope="col">{{ __('Сотрудников') }}</th><th scope="col" class="text-end">{{ __('Действия') }}</th></tr>
            </thead>
            <tbody>
                @forelse ($departments as $department)
                    <tr>
                        <td>
                            <div class="crm-table__main">{{ $department->name }}</div>
                            <div class="crm-table__sub">{{ __('код') }} {{ $department->code }}</div>
                        </td>
                        <td>
                            @if ($department->parent)
                                <span class="crm-chip crm-chip--grey">{{ $department->parent->name }}</span>
                            @else
                                <span class="text-secondary">{{ __('корневой') }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($department->director)
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar avatar-sm avatar-rounded bg-blue-lt">{{ mb_strtoupper(mb_substr($department->director->first_name, 0, 1) . mb_strtoupper(mb_substr($department->director->last_name, 0, 1))) }}</span>
                                    <div>
                                        <div class="crm-table__main">{{ $department->director->last_name }} {{ $department->director->first_name }}</div>
                                        <div class="crm-table__sub">{{ __('зам') }} {{ $department->deputy ? $department->deputy->last_name : '—' }}</div>
                                    </div>
                                </div>
                            @else
                                <span class="text-secondary">{{ __('не назначен') }}</span>
                            @endif
                        </td>
                        <td><span class="crm-chip">{{ $department->active_employees_count }}</span></td>
                        <td class="text-end">
                            @can('checkBoxPermission', 'departments.manage')
                            <div class="d-inline-flex gap-1 align-items-center crm-row-actions">
                                <button class="btn btn-sm btn-ghost-secondary" title="{{ __("Изменить отдел") }}" data-bs-toggle="modal" data-bs-target="#editDept-{{ $department->id }}"><i class="ti ti-pencil"></i></button>
                                <form method="post" action="{{ route('departments.destroy', $department) }}" onsubmit="return confirm('{{ __('Удалить отдел') }} «{{ $department->name }}»? {{ __('Допустимо только без дочерних отделов и сотрудников') }}.')" title="{{ __("Удалить") }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-ghost-danger" title="{{ __("Удалить отдел") }}"><i class="ti ti-trash"></i></button>
                                </form>
                            </div>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'sitemap', 'title' => __('Подразделений пока нет'), 'text' => __('Создайте первое подразделение — корень оргструктуры.')])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('checkBoxPermission', 'departments.manage')
<div class="modal modal-blur fade" id="newDepartment" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('departments.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Добавить подразделение') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Подразделение') }}</h3>
                    <div class="crm-form__desc">{{ __('Название и код — как в штатной структуре и документах') }}</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nd-name">{{ __('Название подразделения') }}<span class="req">*</span></label>
                        <input type="text" id="nd-name" name="name" class="form-control" value="{{ old('name') }}" placeholder="{{ __("Отдел разработки") }}" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nd-code">{{ __('Код') }}<span class="req">*</span></label>
                        <input type="text" id="nd-code" name="code" class="form-control" value="{{ old('code') }}" placeholder="IT, HR…" required
                               maxlength="32" autocomplete="off"
                               data-crm-tags data-crm-tags-hint="{{ __("Enter — добавить тег кода") }}">
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="nd-parent">{{ __('Родительский отдел') }}</label>
                        <select id="nd-parent" name="parent_id" class="form-select">
                            <option value="">{{ __('корневой') }}</option>
                            @foreach ($departments as $d)
                                <option value="{{ $d->id }}" @selected((string) old('parent_id') === (string) $d->id)>{{ $d->name }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">{{ __('Определяет позицию в оргструктуре') }}</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Добавить') }}</button>
            </div>
        </form>
    </div>
</div>

{{-- правка — модалка на каждый отдел --}}
@foreach ($departments as $department)
<div class="modal modal-blur fade" id="editDept-{{ $department->id }}" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('departments.update', $department) }}" data-submit-guard>
            @csrf @method('PATCH')
            <div class="modal-header"><h5 class="modal-title">{{ __('Правка подразделения') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Подразделение') }}</h3>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ed{{ $department->id }}-name">{{ __('Название') }}<span class="req">*</span></label>
                            <input type="text" id="ed{{ $department->id }}-name" name="name" class="form-control" value="{{ old('name', $department->name) }}" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ed{{ $department->id }}-parent">{{ __('Родительский отдел') }}</label>
                            <select id="ed{{ $department->id }}-parent" name="parent_id" class="form-select">
                                <option value="">{{ __('корневой') }}</option>
                                @foreach ($departments as $d)
                                    @if ($d->id !== $department->id)
                                        <option value="{{ $d->id }}" @selected(old('parent_id', $department->parent_id) == $d->id)>{{ $d->name }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Руководство') }}</h3>
                    <div class="crm-form__desc">{{ __('Назначение руководителя и заместителя из сотрудников компании') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ed{{ $department->id }}-director">{{ __('Руководитель') }}</label>
                            <select id="ed{{ $department->id }}-director" name="director_id" class="form-select">
                                <option value="">{{ __('не назначен') }}</option>
                                @foreach (($employeesByCompany[$department->company_id] ?? collect()) as $e)
                                    <option value="{{ $e->id }}" @selected(old('director_id', $department->director_id) === $e->id)>{{ $e->last_name }} {{ $e->first_name }} ({{ $e->employee_no }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ed{{ $department->id }}-deputy">{{ __('Заместитель') }}</label>
                            <select id="ed{{ $department->id }}-deputy" name="deputy_id" class="form-select">
                                <option value="">{{ __('не назначен') }}</option>
                                @foreach (($employeesByCompany[$department->company_id] ?? collect()) as $e)
                                    <option value="{{ $e->id }}" @selected(old('deputy_id', $department->deputy_id) === $e->id)>{{ $e->last_name }} {{ $e->first_name }} ({{ $e->employee_no }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Сохранить') }}</button>
            </div>
        </form>
    </div>
</div>
@endforeach
@endcan
@endsection
