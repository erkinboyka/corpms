@extends('layouts.auth')
@section('title', __('Двухфакторная аутентификация'))

@section('content')
    <div class="card totp-card">
        <div class="card-body p-4">
            <div class="totp-badge"><i class="ti ti-shield-lock" aria-hidden="true"></i></div>
            <h1>{{ __('Введите код из приложения') }}</h1>
            <p class="totp-card__lead">{{ __('Откройте приложение-аутентификатор и введите шестизначный код для подтверждения входа') }}</p>

            @if ($errors->any())
                <div class="alert alert-danger d-flex align-items-center gap-2 text-start" role="alert">
                    <i class="ti ti-alert-circle fs-5" aria-hidden="true"></i>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="post" action="{{ route('login.2fa.verify') }}" id="totp-form">
                @csrf
                <input type="hidden" name="code" id="otp-hidden" value="{{ old('code') }}">
                <div class="otp-boxes" role="group" aria-label="{{ __('Код (6 цифр)') }}">
                    @for ($i = 0; $i < 6; $i++)
                        <input type="text"
                               class="otp-box @error('code') is-invalid @enderror"
                               inputmode="numeric"
                               autocomplete="one-time-code"
                               maxlength="1"
                               aria-label="{{ __('Цифра') }} {{ $i + 1 }}"
                               data-index="{{ $i }}">
                    @endfor
                </div>
                <p class="otp-hint"><i class="ti ti-info-circle" aria-hidden="true"></i>{{ __('Код обновляется каждые 30 секунд') }}</p>
                <button class="btn btn-primary w-100" type="submit">
                    <i class="ti ti-check me-1" aria-hidden="true"></i> {{ __('Подтвердить') }}
                </button>
            </form>

            <div class="totp-card__footer">
                {{ __('Нет доступа к приложению?') }} <a href="{{ route('login') }}">{{ __('Вернуться ко входу') }}</a>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var boxes = Array.prototype.slice.call(document.querySelectorAll('.otp-box'));
            var hidden = document.getElementById('otp-hidden');
            var form = document.getElementById('totp-form');
            if (!boxes.length || !hidden || !form) { return; }

            var initial = (hidden.value || '').replace(/\D/g, '').slice(0, 6);
            initial.split('').forEach(function (digit, i) { if (boxes[i]) { boxes[i].value = digit; } });

            function sync() {
                hidden.value = boxes.map(function (b) { return b.value.replace(/\D/g, ''); }).join('');
                boxes.forEach(function (b) {
                    b.classList.toggle('is-filled', /\d/.test(b.value));
                    if (hidden.value.length === 6) { b.classList.remove('is-invalid'); }
                });
            }

            boxes.forEach(function (box, i) {
                box.addEventListener('input', function () {
                    box.value = box.value.replace(/\D/g, '').slice(-1);
                    if (box.value && i < boxes.length - 1) { boxes[i + 1].focus(); }
                    sync();
                });
                box.addEventListener('keydown', function (e) {
                    if (e.key === 'Backspace' && !box.value && i > 0) { boxes[i - 1].focus(); }
                    if (e.key === 'ArrowLeft' && i > 0) { e.preventDefault(); boxes[i - 1].focus(); }
                    if (e.key === 'ArrowRight' && i < boxes.length - 1) { e.preventDefault(); boxes[i + 1].focus(); }
                });
                box.addEventListener('paste', function (e) {
                    var text = ((e.clipboardData || window.clipboardData).getData('text') || '').replace(/\D/g, '').slice(0, 6);
                    if (!text) { return; }
                    e.preventDefault();
                    text.split('').forEach(function (digit, j) { if (boxes[i + j]) { boxes[i + j].value = digit; } });
                    boxes[Math.min(i + text.length, boxes.length - 1)].focus();
                    sync();
                });
            });

            form.addEventListener('submit', function (e) {
                sync();
                if (hidden.value.length !== 6) {
                    e.preventDefault();
                    boxes.forEach(function (b) { b.classList.add('is-invalid'); });
                    boxes[0].focus();
                }
            });

            sync();
            if (!initial.length) { boxes[0].focus(); }
        })();
    </script>
@endsection
