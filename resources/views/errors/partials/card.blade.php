{{--
    Общий фрейм страниц ошибок. Стилизован под layouts/auth.
    Требования к странице ошибки:
    - не обращаться к БД (должна рендериться и при недоступной базе);
    - не вызывать auth()/сессию напрямую (могут быть не инициализированы
      на момент исключения) — вход вычисляется в try/catch;
    - не показывать сообщения исключений 404/500/503 (пути, SQL, данные
      не должны утекать наружу — ТЗ §11).
--}}
@extends('layouts.auth')

@section('title', $title)

@section('content')
    @php
        // безопасно: сессия/guard могут быть не готовы в момент ошибки
        $loggedIn = false;
        try {
            $loggedIn = auth()->check();
        } catch (\Throwable $e) {
            $loggedIn = false;
        }
    @endphp
    <div class="row justify-content-center">
        <div class="col-12">
            <div class="text-center mb-4">
                <div><span class="navbar-brand d-inline-flex align-items-center fs-2"><i class="ti ti-building-castle me-2"></i>CorpMS</span></div>
                <div class="crm-tagline">Корпоративная система управления</div>
            </div>
            <div class="card card-md">
                <div class="card-body p-4 text-center">
                    <div class="display-5 fw-bold text-secondary mb-2">{{ $code }}</div>
                    <h1 class="h3 mb-2">{{ $title }}</h1>
                    <p class="text-secondary mb-4">{{ $description }}</p>

                    @if (!empty($message))
                        <p class="text-secondary small mb-4"><code>{{ $message }}</code></p>
                    @endif

                    <div class="d-flex gap-2 justify-content-center">
                        @if (! $loggedIn)
                            <a href="{{ route('login') }}" class="btn btn-primary">
                                <i class="ti ti-login me-1"></i>Войти
                            </a>
                        @endif
                        <a href="{{ url('/') }}" class="btn btn-ghost-secondary">
                            <i class="ti ti-home me-1"></i>На главную
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
