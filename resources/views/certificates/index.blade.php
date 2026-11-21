@extends('layouts.tabler')
@section('title', __('Доверенные сертификаты'))
@section('workspace_layout', 'configuration')
@section('content')
@php
    $typeLabels = [
        'company' => __('Компании'),
        'state' => __('Государственный'),
    ];
    $companyCount = $items->where('type', 'company')->count();
    $stateCount = $items->where('type', 'state')->count();
    $activeCount = $items->where('is_active', true)->count();
@endphp
<div class="container-xl py-4">
    <div class="page-header d-print-none mb-3">
        <div class="row align-items-center g-2">
            <div class="col">
                <div class="crm-eyebrow">{{ __('Администрирование') }}</div>
                <h1 class="page-title mt-1">{{ __('Доверенные сертификаты') }}</h1>
                <div class="text-secondary mt-1">{{ __('Доверенные сертификаты (компания/государство)') }}</div>
            </div>
        </div>
    </div>

    <div class="crm-metrics mb-3">
        <div class="crm-metric">
            <div class="crm-metric__label" style="color: var(--crm-accent);"><i class="ti ti-certificate"></i>{{ __('Всего') }}</div>
            <div class="crm-metric__value">{{ $items->count() }}</div>
        </div>
        <div class="crm-metric">
            <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-building"></i>{{ __('Компании') }}</div>
            <div class="crm-metric__value">{{ $companyCount }}</div>
        </div>
        <div class="crm-metric">
            <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-flag"></i>{{ __('Государственный') }}</div>
            <div class="crm-metric__value">{{ $stateCount }}</div>
        </div>
        <div class="crm-metric">
            <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-shield-check"></i>{{ __('Активные') }}</div>
            <div class="crm-metric__value">{{ $activeCount }}</div>
        </div>
    </div>

    <div class="card mb-3 crm-add-banner">
        <div class="card-body d-flex align-items-center gap-3 flex-wrap">
            <span class="crm-add-banner__icon"><i class="ti ti-certificate"></i></span>
            <div class="flex-fill">
                <h2 class="crm-add-banner__title mb-1">{{ __('Доверенные сертификаты') }}</h2>
                <p class="crm-add-banner__text mb-0">{{ __('Добавьте сертификат компании или государства для проверки подписей') }}</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#certificateModal">
                <i class="ti ti-plus me-1"></i>{{ __('Добавить сертификат') }}
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title mb-0">{{ __('Доверенные сертификаты') }}</h2>
            <span class="crm-chip crm-chip--green">{{ __('Всего') }}: {{ $items->count() }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-vcenter card-table crm-thead-sticky">
                <thead>
                    <tr>
                        <th scope="col">{{ __('Название') }}</th>
                        <th scope="col">{{ __('Тип') }}</th>
                        <th scope="col">{{ __('Субъект') }}</th>
                        <th scope="col">{{ __('Издатель') }}</th>
                        <th scope="col">{{ __('Отпечаток') }}</th>
                        <th scope="col" class="text-end">{{ __('Удалить') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $c)
                        <tr>
                            <td class="fw-semibold">{{ $c->name }}</td>
                            <td>
                                <span class="crm-chip crm-chip--{{ $c->type === 'state' ? 'orange' : 'cyan' }}">
                                    {{ $typeLabels[$c->type] ?? $c->type }}
                                </span>
                            </td>
                            <td class="small text-secondary">{{ $c->subject }}</td>
                            <td class="small text-secondary">{{ $c->issuer }}</td>
                            <td class="small"><code>{{ substr((string) $c->fingerprint, 0, 16) }}…</code></td>
                            <td class="text-end">
                                <form method="post" action="{{ route('certificates.destroy', $c) }}" class="d-inline">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" type="submit" title="{{ __('Удалить') }}" aria-label="{{ __('Удалить') }}">
                                        <i class="ti ti-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                @include('partials.empty-state', ['icon' => 'shield-off', 'title' => __('Нет сертификатов')])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ===== Модалка добавления сертификата ===== --}}
<div class="modal modal-blur fade" id="certificateModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content">
            <form method="post" action="{{ route('certificates.store') }}">
                @csrf
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-0"><i class="ti ti-certificate me-2"></i>{{ __('Добавить сертификат') }}</h5>
                        <div class="text-secondary mt-1">{{ __('Добавьте сертификат компании или государства для проверки подписей') }}</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Закрыть') }}"></button>
                </div>
                <div class="modal-body">
                    <div class="crm-form__section">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="certificate-type">{{ __('Тип') }}</label>
                                <select id="certificate-type" name="type" class="form-select">
                                    <option value="company">{{ __('Компании') }}</option>
                                    <option value="state">{{ __('Государственный') }}</option>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="certificate-name">{{ __('Название') }}</label>
                                <input id="certificate-name" type="text" name="name" class="form-control" placeholder="{{ __('Название') }}" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="certificate-pem">{{ __('PEM сертификат') }}</label>
                                <textarea id="certificate-pem" name="pem" class="form-control" rows="7" spellcheck="false" placeholder="-----BEGIN CERTIFICATE-----" required></textarea>
                                <div class="form-text">{{ __('Вставьте сертификат в формате PEM') }}</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                    <button class="btn btn-primary" type="submit"><i class="ti ti-plus me-1"></i>{{ __('Добавить') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
