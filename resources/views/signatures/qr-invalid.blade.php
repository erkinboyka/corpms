@extends('layouts.auth')

@section('title', __('QR-код недействителен'))

@push('styles')
    @include('signatures.partials.qr-styles')
@endpush

@section('content')
    <div class="card signature-qr-card">
        <div class="card-body p-4 text-center">
            <div class="signature-qr-icon signature-qr-icon--error"><i class="ti ti-qr-off" aria-hidden="true"></i></div>
            <h1 class="h2 mb-1">{{ __('QR-код недействителен') }}</h1>
            <p class="text-secondary mb-4">
                {{ __('Ссылка устарела или уже была использована. Вернитесь к документу и сгенерируйте новый QR-код') }}
            </p>
            <a href="{{ route('welcome') }}" class="btn btn-outline-primary w-100">{{ __('На главную') }}</a>
        </div>
    </div>
@endsection
