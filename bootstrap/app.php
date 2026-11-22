<?php

use App\Http\Middleware\DeviceTokenAuth;
use App\Http\Middleware\EnsureActiveEmployee;
use App\Http\Middleware\EnsureOnboarded;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureTenantIsolation;
use App\Http\Middleware\NoCacheHtml;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        api: __DIR__.'/../routes/device.php',
        apiPrefix: '',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Доверяем только IP-адресу и схеме/proto от reverse proxy. Host/Prefix
        // не доверяем: они позволяют подменить URL редиректов и referrer.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PORT
        );

        $middleware->alias([
            'permission' => EnsurePermission::class,
            'platform' => EnsurePlatformAdmin::class,
            'device.token' => DeviceTokenAuth::class,
        ]);

        // Заголовки безопасности на все ответы (ТЗ §11)
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            NoCacheHtml::class,
            EnsureActiveEmployee::class,
            SetLocale::class,
            EnsureOnboarded::class,
            // Межтенантная изоляция: после SubstituteBindings (ТЗ, анти-IDOR)
            EnsureTenantIsolation::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->isJson()
                || $request->expectsJson()
                // BE-03: этот роут вызывается fetch без Accept: application/json.
                || $request->routeIs('ai.message')
                // BE-07: поиск также должен отдавать 422 JSON, а не редирект формы.
                || $request->routeIs('search.index', 'search.hint', 'chat.employees'),
        );
    })->create();
