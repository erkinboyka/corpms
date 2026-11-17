@extends('layouts.auth')

@section('title', 'Новый пароль')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12">
            <div class="text-center mb-4">
                <div><span class="navbar-brand d-inline-flex align-items-center fs-2"><i class="ti ti-building-castle me-2"></i>CorpMS</span></div>
                <div class="crm-tagline">Корпоративная система управления</div>
            </div>
            <div class="card card-md">
                <div class="card-body p-4">
                    <h2 class="h2 mb-1">Новый пароль</h2>
                    <p class="text-secondary mb-4">Придумайте пароль — минимум 12 символов.</p>

                    <form method="POST" action="{{ route('password.update') }}">
                        @csrf
                        <input type="hidden" name="token" value="{{ $token }}">
                        <input type="hidden" name="email" value="{{ old('email', $email) }}">

                        <div class="mb-3">
                            <label class="form-label" for="email">Email</label>
                            <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                   value="{{ old('email', $email) }}" required autocomplete="username">
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="password">Новый пароль</label>
                            <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror"
                                   required autocomplete="new-password" minlength="12">
                            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
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
        </div>
    </div>
@endsection
