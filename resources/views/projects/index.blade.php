@extends('layouts.tabler')

@section('title', 'Проекты')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Работа</div>
            <h2 class="page-title mt-1">Проекты</h2>
            <div class="text-secondary mt-1">Портфель · всего {{ $projects->total() }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('tasks.index') }}"><i class="ti ti-list-check me-1"></i>Задачи</a>
            @can('checkBoxPermission', 'projects.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newProject"><i class="ti ti-plus me-1"></i>Новый проект</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #4299e1;"><i class="ti ti-folders"></i>В работе</div>
        <div class="crm-metric__value">{{ $metrics['open'] }}</div>
        <div class="crm-metric__hint">из {{ $metrics['projects'] }} проектов</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #0b9b72;"><i class="ti ti-player-play"></i>Активных</div>
        <div class="crm-metric__value">{{ $metrics['active'] }}</div>
        <div class="crm-metric__hint">завершено: {{ $metrics['completed'] }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #e5697a;"><i class="ti ti-alarm"></i>Просрочено</div>
        <div class="crm-metric__value">{{ $metrics['overdue'] }}</div>
        <div class="crm-metric__hint">сроки прошли</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #ae3ec9;"><i class="ti ti-wallet"></i>Бюджет</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $metrics['budget'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">факт: {{ number_format((float) $metrics['spent'], 0, ',', ' ') }}</div>
    </div>
</div>

<form method="get" class="crm-toolbar">
    <div class="input-icon">
        <input type="search" name="q" class="form-control crm-search-input" value="{{ $search }}" placeholder="Название или код…" aria-label="Поиск проектов">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <select name="status" class="form-select" aria-label="Статус" onchange="this.form.submit()">
        <option value="open" @selected($status === 'open')>В работе</option>
        @foreach ($statuses as $statusOption)
            <option value="{{ $statusOption->value }}" @selected($status === $statusOption->value)>{{ $crm_label('project_status', $statusOption->value) }}</option>
        @endforeach
        <option value="all" @selected($status === 'all')>Все</option>
    </select>
    <button class="btn btn-ghost-secondary"><i class="ti ti-search me-1"></i>Найти</button>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th>Проект</th>
                    <th>Статус</th>
                    <th>Менеджер</th>
                    <th>Сроки</th>
                    <th class="text-end">Бюджет</th>
                    <th class="text-center">Ресурсы</th>
                    <th class="text-center">Задачи</th>
                    <th class="text-end">Действия</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($projects as $project)
                    <tr>
                        <td>
                            <a class="crm-table__main text-decoration-none" href="{{ route('projects.show', $project) }}">{{ $project->name }}</a>
                            <div class="crm-table__sub">код {{ $project->code }}@if ($project->department) · {{ $project->department->name }}@endif</div>
                        </td>
                        <td>
                            @php
                                $statusChip = match ($project->status->value) {
                                    'active' => 'crm-chip--green',
                                    'on_hold' => 'crm-chip--orange',
                                    'completed' => 'crm-chip--cyan',
                                    'cancelled' => 'crm-chip--red',
                                    default => 'crm-chip--grey',
                                };
                            @endphp
                            <span class="crm-chip {{ $statusChip }}">{{ $crm_label('project_status', $project->status->value) }}</span>
                        </td>
                        <td class="text-secondary">{{ $project->manager ? $project->manager->last_name.' '.$project->manager->first_name : '—' }}</td>
                        <td class="{{ $project->isOverdue() ? 'text-danger fw-semibold' : 'text-secondary' }} small">
                            {{ $project->start_date?->format('d.m.Y') ?? '—' }} → {{ $project->due_date?->format('d.m.Y') ?? '—' }}
                        </td>
                        <td class="text-end">{{ number_format((float) $project->budget, 0, ',', ' ') }}</td>
                        <td class="text-center">{{ $project->members_count }}</td>
                        <td class="text-center">{{ $project->tasks_count }}</td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-ghost-secondary" href="{{ route('projects.show', $project) }}" title="Открыть"><i class="ti ti-eye"></i></a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8">@include('partials.empty-state', ['icon' => 'folders', 'title' => 'Проектов нет', 'text' => 'Создайте проект, задайте бюджет и сроки, привяжите задачи.'])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($projects->hasPages())
        <div class="card-footer">{{ $projects->links('pagination::tabler') }}</div>
    @endif
</div>

@can('checkBoxPermission', 'projects.manage')
<div class="modal modal-blur fade" id="newProject" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('projects.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новый проект</h5></div>
            <div class="modal-body">
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="np-code">Код<span class="req">*</span></label>
                        <input type="text" id="np-code" name="code" class="form-control" value="{{ old('code') }}" placeholder="PRJ-001" maxlength="32" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="np-name">Название<span class="req">*</span></label>
                        <input type="text" id="np-name" name="name" class="form-control" value="{{ old('name') }}" placeholder="Внедрение CRM" required>
                    </div>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="np-manager">Менеджер</label>
                        <select id="np-manager" name="manager_id" class="form-select">
                            <option value="">— не назначен —</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected((string) old('manager_id') === (string) $employee->id)>{{ $employee->last_name }} {{ $employee->first_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="np-department">Отдел</label>
                        <select id="np-department" name="department_id" class="form-select">
                            <option value="">— не указан —</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected((string) old('department_id') === (string) $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="np-status">Статус</label>
                        <select id="np-status" name="status" class="form-select">
                            @foreach ($statuses as $statusOption)
                                <option value="{{ $statusOption->value }}" @selected(old('status', 'planned') === $statusOption->value)>{{ $crm_label('project_status', $statusOption->value) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="np-start">Начало</label>
                        <input type="date" id="np-start" name="start_date" class="form-control" value="{{ old('start_date') }}">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="np-due">Срок</label>
                        <input type="date" id="np-due" name="due_date" class="form-control" value="{{ old('due_date') }}">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="np-budget">Бюджет</label>
                        <input type="number" id="np-budget" name="budget" class="form-control" value="{{ old('budget', 0) }}" step="0.01" min="0">
                    </div>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="np-description">Описание</label>
                    <textarea id="np-description" name="description" class="form-control" rows="2" maxlength="5000">{{ old('description') }}</textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Создать проект</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
