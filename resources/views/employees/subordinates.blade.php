@extends('layouts.tabler')
@section('workspace_layout', 'record')

@section('title', 'Подчинённые — ' . $employee->last_name)

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">Сотрудники</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('employees.show', $employee) }}">{{ $employee->last_name }} {{ $employee->first_name }}</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Подчинённые</li>
                </ol>
            </nav>
            <h1 class="page-title mt-1">Прямые подчинённые</h1>
            <div class="text-secondary mt-1">{{ $employee->last_name }} {{ $employee->first_name }} · {{ $employee->position?->name ?? 'должность не назначена' }}</div>
        </div>
        <div class="col-auto d-print-none">
            <span class="crm-chip">{{ $direct->count() }} чел.</span>
        </div>
    </div>
</div>

<div class="row row-cards g-3">
    @forelse ($direct as $subordinate)
        <div class="col-12 col-md-6 col-xl-4">
            <a class="crm-entity h-100" href="{{ route('employees.show', $subordinate) }}">
                <div class="crm-entity__head">
                    <span class="crm-entity__logo">{{ mb_strtoupper(mb_substr($subordinate->first_name, 0, 1) . mb_substr($subordinate->last_name, 0, 1)) }}</span>
                    <div class="min-w-0">
                        <div class="crm-entity__name">{{ $subordinate->last_name }} {{ $subordinate->first_name }}</div>
                        <div class="crm-entity__sub">{{ $subordinate->position?->name ?? 'должность не назначена' }}</div>
                    </div>
                </div>
                <div class="crm-entity__meta">
                    <span><i class="ti ti-sitemap me-1"></i>{{ $subordinate->department?->name ?? '—' }}</span>
                    <span><i class="ti ti-hash me-1"></i>{{ $subordinate->employee_no }}</span>
                </div>
                <div class="crm-entity__footer">
                    <span class="crm-chip {{ $subordinate->status->value === 'active' ? 'crm-chip--green' : 'crm-chip--grey' }}">{{ $crm_label('employee_status', $subordinate->status->value) }}</span>
                    <i class="ti ti-arrow-right-right text-secondary"></i>
                </div>
            </a>
        </div>
    @empty
        <div class="col-12">
            @include('partials.empty-state', ['icon' => 'users', 'title' => 'Подчинённых нет', 'text' => 'У этого сотрудника нет прямых подчинённых в вашем scope.'])
        </div>
    @endforelse
</div>
@endsection
