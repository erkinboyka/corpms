@extends('layouts.auth')

@section('title', __('Вход'))

@section('content')
    <div class="card">
        <div class="card-body p-4">
            <h1 class="h2 mb-1">{{ __('Вход в систему') }}</h1>
            <p class="text-secondary mb-4">{{ __('Войдите с рабочей почтой и паролем') }}</p>

            <form method="POST" action="{{ route('login') }}">
                @csrf

                <div class="mb-3">
                    <label class="form-label" for="email">{{ __('Рабочая почта') }}</label>
                    <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                           placeholder="name@company.ru" value="{{ old('email') }}" autocomplete="username" required
                           @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email') <div class="invalid-feedback" id="email-error">{{ $message }}</div> @enderror
                </div>

                <div class="mb-2">
                    <label class="form-label" for="password">{{ __('Пароль') }}</label>
                    <div class="bm-password">
                        <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror"
                           autocomplete="current-password" required
                           @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                        <button type="button" class="bm-password-toggle" aria-label="{{ __("Показать пароль") }}" aria-controls="password" aria-pressed="false" hidden>
                            <i class="ti ti-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    @error('password') <div class="invalid-feedback" id="password-error">{{ $message }}</div> @enderror
                </div>

                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <label class="form-check form-check-inline m-0">
                        <input class="form-check-input" type="checkbox" name="remember" value="1">
                        <span class="form-check-label">{{ __('Запомнить меня') }}</span>
                    </label>
                    <a href="{{ route('password.request') }}" class="small">{{ __('Забыли пароль?') }}</a>
                </div>

                <div class="form-footer">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="ti ti-lock me-1"></i> {{ __('Войти') }}
                    </button>
                </div>
            </form>
            <div class="text-center mt-3">
                <a href="{{ route('google.redirect') }}" class="btn btn-outline-secondary w-100">
                    <i class="ti ti-brand-google me-1"></i> {{ __('Войти через Google') }}
                </a>
            </div>
        </div>
    </div>
@endsection
