@extends('layouts.tabler')
@section('workspace_layout', 'record')

@section('title', __('Проект') . ' — ' . $project->name)

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Проекты · Проект') }}</div>
            <h1 class="page-title mt-1">{{ $project->name }}</h1>
            <div class="text-secondary mt-1 d-flex align-items-center gap-2 flex-wrap">
                <span class="crm-chip crm-chip--grey">{{ $project->code }}</span>
                <span class="crm-chip {{ $project->status->value === 'active' ? 'crm-chip--green' : ($project->status->value === 'cancelled' ? 'crm-chip--red' : ($project->status->value === 'on_hold' ? 'crm-chip--orange' : 'crm-chip--cyan')) }}">{{ $crm_label('project_status', $project->status->value) }}</span>
                @if ($project->manager)<span><i class="ti ti-user me-1"></i>{{ $project->manager->last_name }} {{ $project->manager->first_name }}</span>@endif
                <span class="{{ $project->isOverdue() ? 'text-danger fw-semibold' : '' }}"><i class="ti ti-calendar me-1"></i>{{ $project->start_date?->format('d.m.Y') ?? '—' }} → {{ $project->due_date?->format('d.m.Y') ?? '—' }}</span>
            </div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('projects.index') }}"><i class="ti ti-arrow-left me-1"></i>{{ __('К портфелю') }}</a>
            @can('checkBoxPermission', 'projects.manage')
            <form method="post" action="{{ route('projects.status', $project) }}" class="d-inline me-1">
                @csrf
                <select name="status" class="form-select d-inline-block w-auto" aria-label="{{ __("Статус проекта") }}" onchange="this.form.submit()">
                    @foreach ($statuses as $statusOption)
                        <option value="{{ $statusOption->value }}" @selected($project->status === $statusOption)>{{ $crm_label('project_status', $statusOption->value) }}</option>
                    @endforeach
                </select>
            </form>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editProject"><i class="ti ti-pencil me-1"></i>{{ __('Изменить') }}</button>
            @endcan
        </div>
    </div>
</div>

<section class="bm-project-progress" aria-labelledby="project-progress-title">
    <div>
        <h2 id="project-progress-title">{{ __('Выполнение задач') }}</h2>
        <p>{{ $progress['completed'] }} {{ __('из') }} {{ $progress['total'] }} {{ __('завершено') }}</p>
    </div>
    @if ($progress['total'] > 0)
        <progress aria-labelledby="project-progress-title" value="{{ $progress['completed'] }}" max="{{ $progress['total'] }}">{{ $progress['percent'] }}%</progress>
        <strong>{{ $progress['percent'] }}%</strong>
    @endif
</section>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-wallet"></i>{{ __('Бюджет') }}</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $budget['planned'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ __('план проекта') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-danger);"><i class="ti ti-cash"></i>{{ __('Факт расходов') }}</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $budget['spent'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ __('из финансов · поступления') }} {{ number_format((float) $budget['received'], 0, ',', ' ') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-scale"></i>{{ __('Остаток') }}</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $budget['remaining'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">{{ __('исполнение') }} {{ $budget['percent'] === null ? '—' : $budget['percent'].'%' }}</div>
    </div>

</div>

@if ($project->photo_path || filled($project->description))
    <div class="card mb-3">
        <div class="card-header"><div class="card-title">{{ __('О проекте') }}</div></div>
        <div class="card-body d-flex flex-wrap gap-3">
            @if ($project->photo_path)
                @include('partials.entity-photo-display', ['photoPath' => $project->photo_path])
            @endif
            <div class="flex-fill" style="min-width: 240px;">
                @include('partials.rich-text', ['value' => $project->description, 'class' => 'text-secondary'])
            </div>
        </div>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">
                <div class="card-title">{{ __('Задачи проекта') }}</div>
                <div class="card-actions text-secondary">{{ $progress['total'] }}</div>
            </div>
            @can('checkBoxPermission', 'projects.manage')
            <div class="card-body py-2 border-bottom">
                <form method="post" action="{{ route('projects.tasks.attach', $project) }}" class="d-flex gap-2 align-items-end">
                    @csrf
                    <div class="flex-fill">
                        <label class="form-label" for="pt-task">{{ __('Привязать задачу') }}</label>
                        <select id="pt-task" name="task_id" class="form-select" required>
                            <option value="">{{ __('выберите задачу') }}</option>
                            @foreach ($attachableTasks as $taskOption)
                                <option value="{{ $taskOption->id }}">#{{ $taskOption->id }} · {{ $taskOption->title }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn btn-ghost-secondary"><i class="ti ti-link me-1"></i>{{ __('Привязать') }}</button>
                </form>
            </div>
            @endcan
            <div class="table-responsive">
                <table data-record-key="0" class="table table-hover table-vcenter card-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Задача') }}</th>
                            <th scope="col">{{ __('Статус') }}</th>
                            <th scope="col">{{ __('Срок') }}</th>
                            <th scope="col" class="text-end">{{ __('Действия') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($project->tasks as $task)
                            <tr>
                                <td>
                                    <a class="crm-table__main text-decoration-none" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                                    <div class="crm-table__sub">#{{ $task->id }}</div>
                                </td>
                                <td><span class="crm-chip {{ $task->status->value === 'completed' ? 'crm-chip--green' : 'crm-chip--grey' }}">{{ $crm_label('task_status', $task->status->value) }}</span></td>
                                <td class="{{ $task->deadline && $task->deadline->isPast() && $task->status->value !== 'completed' ? 'text-danger' : 'text-secondary' }} small">{{ $task->deadline?->format('d.m.Y') ?? '—' }}</td>
                                <td class="text-end">
                                    @can('checkBoxPermission', 'projects.manage')
                                    <form method="post" action="{{ route('projects.tasks.detach', [$project, $task]) }}"
                                          onsubmit="return confirm('{{ __('Отвязать задачу') }} «{{ $task->title }}» {{ __('от проекта') }}?')">
                                        @csrf
                                        @method('delete')
                                        <button class="btn btn-sm btn-ghost-danger" title="{{ __("Отвязать") }}"><i class="ti ti-unlink"></i></button>
                                    </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4">@include('partials.empty-state', ['icon' => 'list-check', 'title' => __('Задач нет'), 'text' => __('Привяжите существующую задачу или создайте её в разделе «Задачи».')])</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header">
                <div class="card-title">{{ __('Ресурсы') }}</div>
                <div class="card-actions text-secondary">{{ $project->members->count() }}</div>
            </div>
            @can('checkBoxPermission', 'projects.manage')
            <div class="card-body py-2 border-bottom">
                <form method="post" action="{{ route('projects.members.store', $project) }}">
                    @csrf
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="pm-employee">{{ __('Сотрудник') }}</label>
                            <select id="pm-employee" name="employee_id" class="form-select" required>
                                <option value="">{{ __('выберите') }}</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}">{{ $employee->last_name }} {{ $employee->first_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="pm-role">{{ __('Роль') }}</label>
                            <select id="pm-role" name="role" class="form-select">
                                @foreach ($roles as $roleOption)
                                    <option value="{{ $roleOption->value }}" @selected($roleOption->value === 'member')>{{ $crm_label('project_member_role', $roleOption->value) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="pm-allocation">{{ __('Загрузка, %') }}</label>
                            <input type="number" id="pm-allocation" name="allocation_percent" class="form-control" value="100" min="0" max="100">
                        </div>
                    </div>
                    <button class="btn btn-ghost-secondary btn-sm mt-1"><i class="ti ti-user-plus me-1"></i>{{ __('Добавить участника') }}</button>
                </form>
            </div>
            @endcan
            <div class="list-group list-group-flush">
                @forelse ($project->members as $member)
                    <div class="list-group-item d-flex align-items-center">
                        <span class="avatar avatar-sm me-2">{{ mb_strtoupper(mb_substr($member->employee?->first_name ?? '?', 0, 1)) }}</span>
                        <div class="flex-fill">
                            <div class="fw-semibold">{{ $member->employee ? $member->employee->last_name.' '.$member->employee->first_name : '—' }}</div>
                            <div class="text-secondary small">{{ $crm_label('project_member_role', $member->role->value) }} {{ __('загрузка') }} {{ $member->allocation_percent }}%</div>
                        </div>
                        @can('checkBoxPermission', 'projects.manage')
                        <form method="post" action="{{ route('projects.members.destroy', [$project, $member]) }}"
                              onsubmit="return confirm(__('Удалить участника из проекта?'))">
                            @csrf
                            @method('delete')
                            <button class="btn btn-sm btn-ghost-danger" title="{{ __("Удалить") }}"><i class="ti ti-trash"></i></button>
                        </form>
                        @endcan
                    </div>
                @empty
                    <div class="list-group-item text-secondary">{{ __('Участников пока нет') }}</div>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title">{{ __('Вехи') }}</div>
                <div class="card-actions text-secondary">{{ $project->milestones->whereNotNull('completed_at')->count() }}/{{ $project->milestones->count() }}</div>
            </div>
            @can('checkBoxPermission', 'projects.manage')
            <div class="card-body py-2 border-bottom">
                <form method="post" action="{{ route('projects.milestones.store', $project) }}" class="d-flex gap-2 align-items-end">
                    @csrf
                    <div class="flex-fill">
                        <label class="form-label" for="pm-title">{{ __('Веха') }}</label>
                        <input type="text" id="pm-title" name="title" class="form-control" placeholder="{{ __("Сдача этапа") }}" required>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="pm-due">{{ __('Срок') }}</label>
                        <input type="date" id="pm-due" name="due_date" class="form-control">
                    </div>
                    <button class="btn btn-ghost-secondary"><i class="ti ti-plus"></i></button>
                </form>
            </div>
            @endcan
            <div class="list-group list-group-flush">
                @forelse ($project->milestones->sortBy('due_date') as $milestone)
                    <div class="list-group-item d-flex align-items-center">
                        <div class="flex-fill">
                            <div class="fw-semibold {{ $milestone->isCompleted() ? 'text-decoration-line-through text-secondary' : '' }}">{{ $milestone->title }}</div>
                            <div class="small {{ $milestone->isOverdue() ? 'text-danger fw-semibold' : 'text-secondary' }}">
                                {{ $milestone->due_date?->format('d.m.Y') ?? __('без срока') }}
                                @if ($milestone->isCompleted()) · {{ __('выполнена') }} @endif
                            </div>
                        </div>
                        @can('checkBoxPermission', 'projects.manage')
                        <form method="post" action="{{ route('projects.milestones.toggle', [$project, $milestone]) }}" class="d-inline">
                            @csrf
                            <button class="btn btn-sm btn-ghost-secondary" title="{{ $milestone->isCompleted() ? __('Вернуть в работу') : __('Выполнено') }}">
                                <i class="ti ti-{{ $milestone->isCompleted() ? 'rotate' : 'check' }}"></i>
                            </button>
                        </form>
                        <form method="post" action="{{ route('projects.milestones.destroy', [$project, $milestone]) }}" class="d-inline"
                              onsubmit="return confirm('{{ __('Удалить веху') }} «{{ $milestone->title }}»?')">
                            @csrf
                            @method('delete')
                            <button class="btn btn-sm btn-ghost-danger" title="{{ __("Удалить") }}"><i class="ti ti-trash"></i></button>
                        </form>
                        @endcan
                    </div>
                @empty
                    <div class="list-group-item text-secondary">{{ __('Вехи пока не заданы') }}</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

@can('checkBoxPermission', 'projects.manage')
<div class="modal modal-blur fade" id="editProject" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('projects.update', $project) }}" enctype="multipart/form-data" data-submit-guard>
            @csrf
            @method('patch')
            <div class="modal-header"><h5 class="modal-title">{{ __('Изменить проект') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Проект') }}</h3>
                    <div class="crm-form__desc">{{ __('Название, менеджер и отдел — выровнено с формой создания') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ep-name">{{ __('Название') }}<span class="req">*</span></label>
                            <input type="text" id="ep-name" name="name" class="form-control" value="{{ $project->name }}" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ep-manager">{{ __('Менеджер') }}</label>
                            <select id="ep-manager" name="manager_id" class="form-select">
                                <option value="">{{ __('не назначен') }}</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}" @selected((int) $project->manager_id === (int) $employee->id)>{{ $employee->last_name }} {{ $employee->first_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ep-department">{{ __('Отдел') }}</label>
                            <select id="ep-department" name="department_id" class="form-select">
                                <option value="">{{ __('не указан') }}</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department->id }}" @selected((int) $project->department_id === (int) $department->id)>{{ $department->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>{{ __('Сроки и бюджет') }}</h3>
                    <div class="crm-form__desc">{{ __('Старт, срок и бюджет в одной строке') }}</div>
                    <div class="crm-form__row crm-form__row--3">
                        <div class="crm-form__field">
                            <label class="form-label" for="ep-start">{{ __('Начало') }}</label>
                            <input type="date" id="ep-start" name="start_date" class="form-control" value="{{ $project->start_date?->toDateString() }}">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ep-due">{{ __('Срок') }}</label>
                            <input type="date" id="ep-due" name="due_date" class="form-control" value="{{ $project->due_date?->toDateString() }}">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ep-budget">{{ __('Бюджет') }}</label>
                            <input type="number" id="ep-budget" name="budget" class="form-control" value="{{ (float) $project->budget }}" step="0.01" min="0">
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Описание') }}</h3>
                    <div class="crm-form__desc">{{ __('Цели, границы и критерии успеха проекта') }}</div>
                    <div class="crm-form__field mb-0">
                        <textarea data-crm-rich id="ep-description" name="description" class="form-control" rows="3" maxlength="5000">{{ $project->description }}</textarea>
                    </div>
                </div>
                @include('partials.entity-photo-field', ['id' => 'ep-photo', 'photoPath' => $project->photo_path])
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Сохранить') }}</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
