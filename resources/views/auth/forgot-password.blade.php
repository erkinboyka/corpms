@extends('layouts.auth')

@section('title', 'Восстановление пароля')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12">
            <div class="text-center mb-4">
                <div><span class="navbar-brand d-inline-flex align-items-center fs-2"><i class="ti ti-building-castle me-2"></i>CorpMS</span></div>
                <div class="crm-tagline">Корпоративная система управления</div>
            </div>
            <div class="card card-md">
                <div class="card-body p-4">
                    <h2 class="h2 mb-1">Забыли пароль?</h2>
                    <p class="text-secondary mb-4">Укажите email учётной записи — мы отправим ссылку для сброса.</p>

                    @if (session('status'))
                        <div class="alert alert-success" role="status">{{ session('status') }}</div>
                    @endif

                    <form method="POST" action="{{ route('password.email') }}">
                        @csrf

                        <div class="mb-3">
                            <label class="form-label" for="email">Email</label>
                            <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                   placeholder="you@company.local" value="{{ old('email') }}" required autocomplete="username">
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
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
        </div>
    </div>
@endsection
