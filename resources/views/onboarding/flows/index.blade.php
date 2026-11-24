@extends('layouts.tabler')
@section('workspace_layout', 'record')

@section('title', __('Онбординг найма'))

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Люди · Онбординг') }}</div>
            <h1 class="page-title mt-1">{{ __('Онбординг найма') }}</h1>
            <div class="text-secondary mt-1">{{ __('Профиль → документы → обучение → задачи → доступы') }}</div>
        </div>
        @if ($canManage && $employees->isNotEmpty())
        <div class="col-auto d-print-none">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newOnboardingFlow"><i class="ti ti-user-plus me-1"></i>{{ __('Запустить онбординг') }}</button>
        </div>
        @endif
    </div>
</div>

@if ($canManage && $employees->isNotEmpty())
<div class="modal modal-blur fade" id="newOnboardingFlow" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('onboarding.flows.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Запустить онбординг') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <div class="crm-form__field">
                        <label class="form-label" for="onb-employee">{{ __('Сотрудник') }}<span class="req">*</span></label>
                        <select id="onb-employee" name="employee_id" class="form-select" required>
                            <option value="">{{ __('Выберите сотрудника') }}</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected(old('employee_id') == $employee->id)>{{ $employee->last_name }} {{ $employee->first_name }} · {{ $employee->employee_no }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__section">
                    <div class="crm-form__field">
                        <label class="form-label">{{ __('Курсы адаптации') }}</label>
                        <div class="crm-check-list" role="group" aria-label="{{ __('Курсы адаптации') }}">
                            @forelse ($courses as $course)
                                <label class="crm-check-list__item">
                                    <input class="form-check-input" type="checkbox" name="course_ids[]" value="{{ $course->id }}" @checked(in_array($course->id, old('course_ids', [])))>
                                    <span>{{ $course->title }}</span>
                                </label>
                            @empty
                                <span class="text-secondary">{{ __('Курсов пока нет') }}</span>
                            @endforelse
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="onb-task">{{ __('Заголовок задачи') }}</label>
                        <input id="onb-task" name="task_title" type="text" maxlength="255" class="form-control" value="{{ old('task_title') }}" placeholder="{{ __('Онбординг: ознакомление с регламентами') }}">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button class="btn btn-primary"><i class="ti ti-user-plus me-1"></i>{{ __('Запустить цепочку') }}</button>
            </div>
        </form>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header"><div class="card-title">{{ __('Потоки') }}</div></div>
    <div class="table-responsive">
        <table class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th>{{ __('Сотрудник') }}</th>
                    <th>{{ __('Статус') }}</th>
                    <th>{{ __('Шагов') }}</th>
                    <th>{{ __('Создан') }}</th>
                    <th class="text-end">{{ __('Действия') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($flows as $flow)
                    <tr>
                        <td>
                            <div class="crm-table__main">{{ $flow->employee?->last_name }} {{ $flow->employee?->first_name }}</div>
                            <div class="crm-table__sub">{{ $flow->employee?->employee_no }}</div>
                        </td>
                        <td><span class="crm-chip {{ $flow->status->value === 'completed' ? 'crm-chip--green' : ($flow->status->value === 'failed' ? 'crm-chip--red' : '') }}">{{ $crm_label('onboarding_flow_status', $flow->status->value) }}</span></td>
                        <td>{{ $flow->steps->filter(fn ($step) => $step->status->value === 'completed')->count() }}/{{ $flow->steps->count() }}</td>
                        <td>{{ $flow->created_at?->format('d.m.Y H:i') ?? '—' }}</td>
                        <td class="text-end"><a class="btn btn-sm btn-ghost-secondary" href="{{ route('onboarding.flows.show', $flow) }}">{{ __('Открыть') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'user-plus', 'title' => __('Потоков нет'), 'text' => __('Запустите онбординг для нового сотрудника.')])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">{{ $flows->links() }}</div>
</div>
@endsection
