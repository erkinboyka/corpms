@extends('layouts.auth')

@section('title', 'Восстановление пароля')

@section('content')
    <div class="card card-md">
        <div class="card-body p-4">
            <h1 class="h2 mb-1">Забыли пароль?</h1>
            <p class="text-secondary mb-4">Укажите email учётной записи — мы отправим ссылку для сброса.</p>

            @if (session('status'))
                <div class="alert alert-success" role="status">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('password.email') }}">
                @csrf

                <div class="mb-3">
                    <label class="form-label" for="email">Рабочая почта</label>
                    <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                           placeholder="name@company.ru" value="{{ old('email') }}" required autocomplete="username">
                    @error('email') <div class="invalid-feedback" id="email-error">{{ $message }}</div> @enderror
                </div>

                <div class="form-footer">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="ti ti-mail me-1"></i> Отправить ссылку
                    </button>
                </div>
            </form>

            <div class="text-center text-secondary mt-3">
                <a href="{{ route('login') }}" class="text-reset">Вернуться ко входу</a>
            </div>
        </div>
    </div>
@endsection
