@extends('layouts.tabler')
@section('workspace_layout', 'record')

@section('title', __('Онбординг') . ' #' . $flow->id)

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Люди · Онбординг') }}</div>
            <h1 class="page-title mt-1">{{ __('Поток') }} #{{ $flow->id }}</h1>
            <div class="text-secondary mt-1">
                {{ $flow->employee?->last_name }} {{ $flow->employee?->first_name }} · {{ $flow->employee?->employee_no }}
            </div>
        </div>
        <div class="col-auto">
            <a class="btn btn-ghost-secondary" href="{{ route('onboarding.flows.index') }}">{{ __('К списку') }}</a>
            @if ($canManage)
                @if ($flow->status->value !== 'cancelled')
                    <form method="post" action="{{ route('onboarding.flows.run', $flow) }}" class="d-inline">@csrf
                        <button class="btn btn-primary"><i class="ti ti-player-play me-1"></i>{{ __('Выполнить шаги') }}</button>
                    </form>
                    <form method="post" action="{{ route('onboarding.flows.revoke', $flow) }}" class="d-inline"
                          onsubmit="return confirm('{{ __('Отозвать доступы, выданные этим потоком?') }}')">@csrf
                        <button class="btn btn-ghost-danger">{{ __('Отозвать доступы') }}</button>
                    </form>
                @endif
            @endif
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">{{ __('Состояние') }}</div>
        <span class="crm-chip {{ $flow->status->value === 'completed' ? 'crm-chip--green' : ($flow->status->value === 'failed' || $flow->status->value === 'cancelled' ? 'crm-chip--red' : '') }}">
            {{ $crm_label('onboarding_flow_status', $flow->status->value) }}
        </span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3"><div class="crm-metric__label">{{ __('Начат') }}</div><div class="crm-metric__value">{{ $flow->started_at?->format('d.m.Y H:i') ?? '—' }}</div></div>
            <div class="col-md-3"><div class="crm-metric__label">{{ __('Завершён') }}</div><div class="crm-metric__value">{{ $flow->completed_at?->format('d.m.Y H:i') ?? '—' }}</div></div>
            <div class="col-md-3"><div class="crm-metric__label">{{ __('Отозван') }}</div><div class="crm-metric__value">{{ $flow->cancelled_at?->format('d.m.Y H:i') ?? '—' }}</div></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-title">{{ __('Шаги') }}</div></div>
    <div class="table-responsive">
        <table class="table table-hover table-vcenter card-table">
            <thead><tr>
                <th>{{ __('Шаг') }}</th>
                <th>{{ __('Статус') }}</th>
                <th>{{ __('Попыток') }}</th>
                <th>{{ __('Ошибка') }}</th>
                @if ($canManage)<th class="text-end">{{ __('Действия') }}</th>@endif
            </tr></thead>
            <tbody>
                @forelse ($flow->steps as $step)
                    <tr>
                        <td>{{ $crm_label('onboarding_step', $step->key) }}</td>
                        <td><span class="crm-chip {{ $step->status->value === 'completed' ? 'crm-chip--green' : ($step->status->value === 'failed' ? 'crm-chip--red' : '') }}">{{ $crm_label('onboarding_step_status', $step->status->value) }}</span></td>
                        <td>{{ $step->attempts }}</td>
                        <td class="text-secondary">{{ $step->error ? \Illuminate\Support\Str::limit($step->error, 120) : '—' }}</td>
                        @if ($canManage)
                            <td class="text-end">
                                @if ($step->status->value === 'failed' && $flow->status->value !== 'cancelled')
                                    <form method="post" action="{{ route('onboarding.flows.steps.retry', [$flow, $step->key]) }}" class="d-flex gap-2 align-items-end justify-content-end">@csrf
                                        @if ($step->key === 'access')
                                            <select name="role_slugs[]" class="form-select form-select-sm" multiple title="{{ __('Роли (если нужно исправить)') }}">
                                                @foreach ($roles as $role)
                                                    <option value="{{ $role->slug }}">{{ $role->name }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                        <button class="btn btn-sm btn-ghost-secondary">{{ __('Повторить') }}</button>
                                    </form>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'list', 'title' => __('Шагов нет'), 'text' => __('Перезапустите поток.')])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($flow->grants->isNotEmpty())
<div class="card">
    <div class="card-header"><div class="card-title">{{ __('Выданные роли') }}</div></div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr><th>{{ __('Роль') }}</th><th>{{ __('Выдана') }}</th><th>{{ __('Отозвана') }}</th></tr></thead>
            <tbody>
                @foreach ($flow->grants as $grant)
                    <tr>
                        <td>{{ $grant->role?->name ?? '—' }}</td>
                        <td>{{ $grant->granted_at?->format('d.m.Y H:i') ?? '—' }}</td>
                        <td>{{ $grant->revoked_at?->format('d.m.Y H:i') ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
