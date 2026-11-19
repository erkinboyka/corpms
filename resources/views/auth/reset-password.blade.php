@extends('layouts.auth')

@section('title', 'Новый пароль')

@section('content')
    <div class="card card-md">
        <div class="card-body p-4">
            <h1 class="h2 mb-1">Новый пароль</h1>
            <p class="text-secondary mb-4">Придумайте пароль — минимум 12 символов.</p>

            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">


                <div class="mb-3">
                    <label class="form-label" for="email">Рабочая почта</label>
                    <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                           value="{{ old('email', $email) }}" required autocomplete="username">
                    @error('email') <div class="invalid-feedback" id="email-error">{{ $message }}</div> @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label" for="password">Новый пароль</label>
                    <div class="bm-password">
                        <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                           required autocomplete="new-password" minlength="12">
                        <button type="button" class="bm-password-toggle" aria-label="Показать пароль" aria-controls="password" aria-pressed="false" hidden>
                            <i class="ti ti-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    @error('password') <div class="invalid-feedback" id="password-error">{{ $message }}</div> @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label" for="password_confirmation">Повторите пароль</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" class="form-control"
                           required autocomplete="new-password" minlength="12">
                </div>

                <div class="form-footer">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="ti ti-key me-1"></i> Сохранить пароль
                    </button>
                </div>
            </form>

            <div class="text-center text-secondary mt-3">
                <a href="{{ route('login') }}" class="text-reset">Вернуться ко входу</a>
            </div>
        </div>
    </div>
@endsection
