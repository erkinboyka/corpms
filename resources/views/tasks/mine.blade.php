@extends('layouts.tabler')

@section('title', 'Мои задачи')

@php
    $prioChips = ['critical' => 'crm-chip--red', 'high' => 'crm-chip--orange', 'medium' => 'crm-chip--cyan', 'low' => 'crm-chip--grey'];
    $statusChips = [
        'completed' => 'crm-chip--green', 'overdue' => 'crm-chip--red', 'cancelled' => 'crm-chip--grey',
        'returned' => 'crm-chip--orange', 'review' => 'crm-chip--orange', 'planned' => 'crm-chip--cyan',
        'new' => 'crm-chip--grey', 'in_progress' => 'crm-chip',
    ];
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Работа</div>
            <h2 class="page-title mt-1">Мои задачи</h2>
            <div class="text-secondary mt-1">Назначены и созданы вами · {{ $assigned->count() }} назначено · {{ $written->count() }} создано</div>
        </div>
        <div class="col-auto d-print-none">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newTask">
                <i class="ti ti-plus me-1"></i>Создать задачу
            </button>
        </div>
    </div>
</div>

@if ($openLogs)
    <div class="alert alert-info d-flex align-items-center gap-2 mb-3">
        <span class="crm-live-dot"></span>
        <div>Идёт учёт времени по задаче <a href="{{ route('tasks.show', $openLogs->task) }}" class="fw-semibold">{{ $openLogs->task->title }}</a> (с {{ $openLogs->started_at->format('H:i') }}).</div>
    </div>
@endif

<div class="row g-3">
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header">
                <strong><i class="ti ti-inbox me-1"></i>Назначены мне</strong>
                <span class="crm-chip ms-auto">{{ $assigned->count() }}</span>
            </div>
            <div class="card-body py-2">
                <div class="crm-queue">
                    @forelse ($assigned as $task)
                        <div class="crm-queue__item" style="--c: {{ $task->deadline && $task->deadline->isPast() && ! in_array($task->status->value, ['completed', 'cancelled']) ? '#d63939' : '#0b9b72' }};">
                            <div class="crm-queue__body">
                                <div class="crm-queue__title">
                                    <a class="text-reset text-decoration-none" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                                </div>
                                <div class="crm-queue__sub">
                                    {{ $task->author?->last_name ?? '—' }}
                                    @if ($task->deadline)· срок <strong class="{{ $task->deadline->isPast() && ! in_array($task->status->value, ['completed', 'cancelled']) ? 'text-danger' : '' }}">{{ $task->deadline->format('d.m.Y') }}</strong>@endif
                                </div>
                            </div>
                            <span class="crm-chip {{ $prioChips[$task->priority->value] ?? 'crm-chip--grey' }} d-none d-md-inline-flex">{{ $crm_label('task_priority', $task->priority->value) }}</span>
                            <span class="crm-chip {{ $statusChips[$task->status->value] ?? 'crm-chip' }}">{{ $crm_label('task_status', $task->status->value) }}</span>
                        </div>
                    @empty
                        <div class="crm-queue__item" style="--c: #6c7a91;">
                            <div class="crm-queue__body">
                                <div class="crm-queue__title">Назначенных задач нет</div>
                                <div class="crm-queue__sub">Задачи появятся здесь после назначения.</div>
                            </div>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card">
            <div class="card-header">
                <strong><i class="ti ti-edit me-1"></i>Созданные мной</strong>
                <span class="crm-chip ms-auto">{{ $written->count() }}</span>
            </div>
            <div class="card-body py-2">
                <div class="crm-queue">
                    @forelse ($written as $task)
                        <div class="crm-queue__item" style="--c: #4299e1;">
                            <div class="crm-queue__body">
                                <div class="crm-queue__title">
                                    <a class="text-reset text-decoration-none" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                                </div>
                                <div class="crm-queue__sub">
                                    {{ $task->department?->name ?? 'без отдела' }}
                                    @if ($task->deadline)· срок {{ $task->deadline->format('d.m.Y') }}@endif
                                </div>
                            </div>
                            <span class="crm-chip {{ $statusChips[$task->status->value] ?? 'crm-chip' }}">{{ $crm_label('task_status', $task->status->value) }}</span>
                        </div>
                    @empty
                        <div class="crm-queue__item" style="--c: #6c7a91;">
                            <div class="crm-queue__body">
                                <div class="crm-queue__title">Своих задач нет</div>
                                <div class="crm-queue__sub">Создайте задачу — она появится здесь.</div>
                            </div>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal modal-blur fade" id="newTask" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('tasks.store') }}" data-submit-guard>
            @csrf
            <input type="hidden" name="type" value="task">
            <div class="modal-header"><h5 class="modal-title">Новая задача</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>Суть задачи</h3>
                    <div class="crm-form__desc">Понятный заголовок и подробности — что и к какому результату.</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nt-title">Заголовок<span class="req">*</span></label>
                        <input type="text" id="nt-title" name="title" class="form-control" value="{{ old('title') }}" placeholder="О чём задача" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nt-desc">Описание</label>
                        <textarea id="nt-desc" class="form-control" name="description" rows="3" placeholder="Подробности, критерии выполнения">{{ old('description') }}</textarea>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>Сроки и исполнители</h3>
                    <div class="crm-form__desc">Кому назначается и к какому сроку — можно сразу нескольким сотрудникам и отделам.</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="nt-priority">Приоритет</label>
                            <select id="nt-priority" name="priority" class="form-select">
                                @foreach (['low', 'medium', 'high', 'critical'] as $p)
                                    <option value="{{ $p }}" @selected(old('priority', 'medium') === $p)>{{ $crm_label('task_priority', $p) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nt-deadline">Срок</label>
                            <input type="date" id="nt-deadline" name="deadline" class="form-control" value="{{ old('deadline') }}">
                        </div>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nt-employees">Исполнители — сотрудники</label>
                        <select id="nt-employees" name="employee_ids[]" class="form-select" multiple
                                aria-describedby="nt-emp-hint" data-placeholder="поиск и выбор…">
                            @foreach (\App\Models\Employee::query()->where('status', 'active')->orderBy('last_name')->get() as $option)
                                <option value="{{ $option->id }}" @selected(in_array($option->id, (array) old('employee_ids', []), true))>{{ $option->last_name }} {{ $option->first_name }}</option>
                            @endforeach
                        </select>
                        <div class="form-text" id="nt-emp-hint">Один или несколько — выбирайте с поиском. Каждый увидит задачу в «Мои задачи».</div>
                    </div>
                    @can('checkBoxPermission', 'tasks.assign_department')
                        <div class="crm-form__field mb-0">
                            <label class="form-label" for="nt-departments">Исполнители — отделы</label>
                            <select id="nt-departments" name="department_ids[]" class="form-select" multiple
                                    aria-describedby="nt-dept-hint" data-placeholder="поиск и выбор…">
                                @foreach (\App\Models\Department::query()->orderBy('name')->get() as $option)
                                    <option value="{{ $option->id }}" @selected(in_array($option->id, (array) old('department_ids', []), true))>{{ $option->name }}</option>
                                @endforeach
                            </select>
                            <div class="form-text" id="nt-dept-hint">Задача появится у всех сотрудников отдела — можно несколько отделов. Сотрудников и отделы можно совмещать.</div>
                        </div>
                    @endcan
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary"><i class="ti ti-plus me-1"></i>Создать задачу</button>
            </div>
        </form>
    </div>
</div>
@endsection
