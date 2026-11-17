@extends('layouts.auth')

@section('title', 'Вход')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12">
            <div class="text-center mb-4">
                <div><span class="navbar-brand d-inline-flex align-items-center fs-2"><i class="ti ti-building-castle me-2"></i>CorpMS</span></div>
                <div class="crm-tagline">Корпоративная система управления</div>
            </div>
            <div class="card card-md">
                <div class="card-body p-4">
                    <h2 class="h2 mb-1">Вход в систему</h2>
                    <p class="text-secondary mb-4">Введите учётные данные вашего аккаунта</p>

                    <form method="POST" action="{{ route('login') }}">
                        @csrf

                        <div class="mb-3">
                            <label class="form-label" for="email">Email</label>
                            <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                   placeholder="you@company.local" value="{{ old('email') }}" autocomplete="username">
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-2">
                            <label class="form-label" for="password">Пароль</label>
                            <input type="password" id="password" name="password" class="form-control"
                                   autocomplete="current-password" placeholder="Ваш пароль">
                            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-2">
                            <label class="form-check form-check-inline m-0">
                                <input class="form-check-input" type="checkbox" name="remember" value="1">
                                <span class="form-check-label">Запомнить меня</span>
                            </label>
                        </div>

                        <div class="form-footer">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="ti ti-lock me-1"></i> Войти
                            </button>
                        </div>

                        <div class="text-center mt-3">
                            <a href="{{ route('password.request') }}" class="text-secondary">Забыли пароль?</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
