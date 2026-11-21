@extends('layouts.auth')

@section('title', __('Подпись документа'))

@push('styles')
    @include('signatures.partials.qr-styles')
@endpush

@section('content')
    <div class="card signature-qr-card">
        <div class="card-body p-4">
            <div class="signature-qr-icon"><i class="ti ti-signature" aria-hidden="true"></i></div>
            <h1 class="h2 mb-1">{{ __('Подпись документа') }}</h1>
            <p class="text-secondary mb-4">{{ __('Проверьте документ и подтвердите подпись') }}</p>

            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <div class="signature-qr-doc">
                <div class="text-secondary small">{{ __('Документ') }}</div>
                <div class="fw-semibold">{{ $document->title }}</div>
                @if ($document->document_number)
                    <div class="text-secondary small">№ {{ $document->document_number }}</div>
                @endif
            </div>

            <dl class="signature-qr-meta">
                <div>
                    <dt>{{ __('Подписант') }}</dt>
                    <dd>{{ trim(($signer->last_name ?? '').' '.($signer->first_name ?? '')) ?: $signer->user?->name }}</dd>
                </div>
                <div>
                    <dt>{{ __('Версия') }}</dt>
                    <dd>{{ $version?->version ?? '—' }}</dd>
                </div>
                <div>
                    <dt>SHA-256</dt>
                    <dd><code>{{ Str::limit($version?->sha256 ?? '—', 26, '…') }}</code></dd>
                </div>
                <div>
                    <dt>{{ __('Код действителен до') }}</dt>
                    <dd>{{ $expiresAt?->format('d.m.Y H:i') }}</dd>
                </div>
            </dl>

            <form method="POST" action="{{ route('signature.qr.sign', $token) }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="qr-otp">{{ __('Код подтверждения') }}</label>
                    <input type="text" id="qr-otp" name="otp_code" class="form-control form-control-lg text-center"
                           inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}"
                           placeholder="000000" required autofocus>
                    <div class="form-text">{{ __('Код отправлен подписанту в уведомлении') }}</div>
                </div>
                <button type="submit" class="btn btn-primary btn-lg w-100">
                    <i class="ti ti-signature me-2" aria-hidden="true"></i>{{ __('Подписать') }}
                </button>
            </form>
            <p class="text-secondary text-center signature-qr-hint mt-3 mb-0">
                {{ __('Нажимая кнопку, вы подтверждаете свою подпись под этим документом') }}
            </p>
        </div>
    </div>
@endsection
