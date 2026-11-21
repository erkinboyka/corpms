@extends('layouts.tabler')
@section('title', __('Двухфакторная аутентификация'))
@section('content')
<div class="container-xl py-4">
    <div class="page-header d-print-none mb-3">
        <div class="row align-items-center g-2">
            <div class="col">
                <div class="crm-eyebrow">{{ __('Безопасность') }}</div>
                <h1 class="page-title mt-1">{{ __('Двухфакторная аутентификация') }}</h1>
                <div class="text-secondary mt-1">{{ __('Защитите вход в систему шестизначным кодом из приложения') }}</div>
            </div>
        </div>
    </div>

    {{-- Статус --}}
    @if ($user->two_factor_confirmed_at)
        <div class="twofa-status twofa-status--on">
            <span class="twofa-status__icon"><i class="ti ti-shield-check" aria-hidden="true"></i></span>
            <div>
                <strong>{{ __('2FA включена') }}</strong>
                <small>{{ __('Ваша учётная запись защищена дополнительным кодом') }}</small>
            </div>
        </div>
    @else
        <div class="twofa-status twofa-status--off">
            <span class="twofa-status__icon"><i class="ti ti-shield-off" aria-hidden="true"></i></span>
            <div>
                <strong>{{ __('2FA отключена') }}</strong>
                <small>{{ __('Включите её, чтобы защитить вход от несанкционированного доступа') }}</small>
            </div>
        </div>
    @endif

    @if ($user->two_factor_confirmed_at)
        {{-- Управление включённой защитой --}}
        <div class="twofa-grid">
            <div class="twofa-panel">
                <h2 class="twofa-panel__title"><i class="ti ti-lock"></i>{{ __('Защита активна') }}</h2>
                <ol class="twofa-steps">
                    <li><strong>{{ __('Код доступа') }}</strong>{{ __('При входе система запросит код из приложения-аутентификатора') }}</li>
                    <li><strong>{{ __('Резервный доступ') }}</strong>{{ __('Сохраните доступ к приложению — без него восстановление потребует помощи администратора') }}</li>
                    <li><strong>{{ __('Отключение') }}</strong>{{ __('Для отключения потребуется текущий пароль учётной записи') }}</li>
                </ol>
            </div>

            <div class="twofa-danger">
                <h3><i class="ti ti-alert-triangle me-1"></i>{{ __('Отключить двухфакторную защиту') }}</h3>
                <p>{{ __('После отключения вход будет производиться только по паролю. Это снижает уровень безопасности учётной записи') }}</p>
                <form method="post" action="{{ route('profile.2fa.disable') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="twofa-password">{{ __('Пароль для подтверждения') }}</label>
                        <input type="password" id="twofa-password" name="password" class="form-control" autocomplete="current-password" required>
                        @error('password') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                    <button class="btn btn-danger" type="submit"><i class="ti ti-shield-off me-1"></i>{{ __('Отключить 2FA') }}</button>
                </form>
            </div>
        </div>
    @else
        {{-- Настройка --}}
        <div class="twofa-grid">
            <div class="twofa-panel">
                <h2 class="twofa-panel__title"><i class="ti ti-qrcode"></i>{{ __('QR-код для приложения') }}</h2>
                <div class="twofa-qr">
                    {!! $qrSvg !!}
                </div>
                <p class="twofa-qr__hint">{{ __('Отсканируйте код приложением-аутентификатором') }}</p>
                <p class="twofa-qr__manual">{{ __('Не работает сканирование?') }} <a href="{{ $qr }}">{{ __('Открыть ссылку для приложения') }}</a></p>

                <div class="twofa-secret">
                    <span class="twofa-secret__label">{{ __('Секретный ключ') }}</span>
                    <div class="twofa-secret__value">
                        <span id="twofa-secret-value">{{ $secret }}</span>
                        <button type="button" class="btn btn-sm btn-ghost-secondary" id="twofa-copy" aria-label="{{ __('Скопировать секрет') }}">
                            <i class="ti ti-copy"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="twofa-panel">
                <h2 class="twofa-panel__title"><i class="ti ti-list-numbers"></i>{{ __('Как настроить') }}</h2>
                <ol class="twofa-steps">
                    <li><strong>{{ __('Установите приложение') }}</strong>{{ __('Используйте Google Authenticator, Microsoft Authenticator или другое приложение с поддержкой TOTP') }}</li>
                    <li><strong>{{ __('Добавьте аккаунт') }}</strong>{{ __('Отсканируйте QR-код или введите секретный ключ вручную') }}</li>
                    <li><strong>{{ __('Подтвердите код') }}</strong>{{ __('Введите шестизначный код из приложения ниже, чтобы включить защиту') }}</li>
                </ol>

                <form method="post" action="{{ route('profile.2fa.confirm') }}" class="twofa-code-form">
                    @csrf
                    <label class="form-label" for="twofa-code">{{ __('Код из приложения') }}</label>
                    <input type="text" id="twofa-code" name="code" class="form-control @error('code') is-invalid @enderror"
                           inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
                    @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <button class="btn btn-primary mt-3" type="submit"><i class="ti ti-check me-1"></i>{{ __('Подтвердить и включить') }}</button>
                </form>
            </div>
        </div>
    @endif
</div>

@if (! $user->two_factor_confirmed_at)
<script>
    (function () {
        var button = document.getElementById('twofa-copy');
        var value = document.getElementById('twofa-secret-value');
        if (!button || !value) { return; }
        button.addEventListener('click', function () {
            var text = value.textContent.trim();
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    button.innerHTML = '<i class="ti ti-check"></i>';
                    setTimeout(function () { button.innerHTML = '<i class="ti ti-copy"></i>'; }, 1600);
                });
            }
        });
    })();
</script>
@endif
@endsection
