@extends('layouts.tabler')
@section('workspace_layout', 'planning')

@section('title', __('Оргструктура'))

@php
    $rootNodes = $tree->whereNull('parent_id')->sortBy('name')->values();
    $totalEmployees = (int) $tree->sum('active_employees_count');
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Организация') }}</div>
            <h1 class="page-title mt-1">{{ __('Оргструктура') }}</h1>
            <div class="text-secondary mt-1">{{ __('Иерархия подразделений · нажмите на узел, чтобы развернуть детей') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('departments.index') }}"><i class="ti ti-list me-1"></i>{{ __('Списком') }}</a>
            @can('checkBoxPermission', 'departments.manage')
                <a class="btn btn-primary" href="{{ route('departments.index') }}"><i class="ti ti-plus me-1"></i>{{ __('Добавить') }}</a>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-building"></i>{{ __('Компаний') }}</div>
        <div class="crm-metric__value">{{ $companies->count() }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-sitemap"></i>{{ __('Подразделений') }}</div>
        <div class="crm-metric__value">{{ $tree->count() }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-users"></i>{{ __('Активных сотрудников') }}</div>
        <div class="crm-metric__value">{{ $totalEmployees }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-user-check"></i>{{ __('С руководителем') }}</div>
        <div class="crm-metric__value">{{ $tree->where('director_id')->count() }}<small> / {{ $tree->count() }}</small></div>
    </div>
</div>

@forelse ($companies as $company)
    @php
        $companyRoots = $rootNodes->where('company_id', $company->id);
    @endphp
    <div class="card mb-3">
        <div class="card-header">
            <span class="d-flex align-items-center gap-2">
                <span class="crm-entity__logo" style="width:34px;height:34px;font-size:13px;border-radius:10px;">{{ mb_strtoupper(mb_substr($company->name, 0, 2)) }}</span>
                <strong>{{ $company->name }}</strong>
            </span>
            <span class="crm-chip ms-auto">{{ $tree->where('company_id', $company->id)->count() }} {{ __('подразделений') }}</span>
        </div>
        <div class="card-body">
            @if ($companyRoots->isEmpty())
                @include('partials.empty-state', ['icon' => 'sitemap', 'title' => __('Структура не построена'), 'text' => __('В этой компании пока нет корневых подразделений.')])
            @else
                <ul class="crm-org">
                    @foreach ($companyRoots as $root)
                        @include('departments.orgchart-node', ['node' => $root, 'depth' => 0])
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@empty
    <div class="card">
        <div class="card-body">
            @include('partials.empty-state', ['icon' => 'building', 'title' => __('Компаний нет'), 'text' => __('Добавьте компанию, чтобы построить оргструктуру.')])
        </div>
    </div>
@endforelse
@endsection
