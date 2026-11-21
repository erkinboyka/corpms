@extends('layouts.auth')

@section('title', __('Документ подписан'))

@push('styles')
    @include('signatures.partials.qr-styles')
@endpush

@section('content')
    <div class="card signature-qr-card">
        <div class="card-body p-4 text-center">
            <div class="signature-qr-icon signature-qr-icon--done"><i class="ti ti-circle-check" aria-hidden="true"></i></div>
            <h1 class="h2 mb-1">{{ __('Документ подписан') }}</h1>
            <p class="text-secondary mb-4">{{ __('Подпись зафиксирована в журнале') }}</p>

            <div class="signature-qr-doc text-start">
                <div class="text-secondary small">{{ __('Документ') }}</div>
                <div class="fw-semibold">{{ $signature->document?->title }}</div>
                <div class="text-secondary small">№ {{ $signature->signature_id }}</div>
            </div>

            <dl class="signature-qr-meta text-start">
                <div>
                    <dt>{{ __('Подписант') }}</dt>
                    <dd>{{ trim(($signature->signer?->last_name ?? '').' '.($signature->signer?->first_name ?? '')) ?: '—' }}</dd>
                </div>
                <div>
                    <dt>{{ __('Когда') }}</dt>
                    <dd>{{ $signature->signed_at?->format('d.m.Y H:i') }}</dd>
                </div>
                <div>
                    <dt>{{ __('Статус') }}</dt>
                    <dd>{{ $crm_label('signature_status', $signature->status->value) }}</dd>
                </div>
            </dl>

            <a href="{{ route('welcome') }}" class="btn btn-outline-primary w-100">{{ __('На главную') }}</a>
        </div>
    </div>
@endsection
