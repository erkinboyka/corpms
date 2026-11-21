<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Заголовки безопасности (ТЗ §11): защита от clickjacking, MIME-sniffing,
 * утечки referrer, встроенных датчиков. HSTS — только по HTTPS,
 * чтобы не сломать локальную разработку по http.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // LMS video calls need camera/microphone/screen capture on this origin only.
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(self), display-capture=(self), geolocation=()');

        // Content-Security-Policy (P1-4 аудита): ресурсы — только со своих origin,
        // внешние скрипты/фреймы/объекты запрещены. 'unsafe-inline' для script/style
        // сохранён временно: инлайн-скрипты есть в layout и ~12 view; переход на nonce
        // — отдельная задача (см. ROADMAP, инфраструктура).
        //
        // connect-src: websocket к Reverb — только заданный в .env хост/порт,
        // чтобы realtime-канал не расширял список разрешённых источников.
        $connect = ["'self'"];

        $reverbHost = (string) config('broadcasting.connections.reverb.options.host');
        $reverbPort = (int) config('broadcasting.connections.reverb.options.port');
        $reverbScheme = (string) config('broadcasting.connections.reverb.options.scheme');

        if ($reverbHost !== '' && $reverbPort > 0) {
            $wsScheme = $reverbScheme === 'https' ? 'wss' : 'ws';
            $connect[] = $wsScheme.'://'.$reverbHost.($reverbPort === 80 || $reverbPort === 443 ? '' : ':'.$reverbPort);
        }

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            'connect-src '.implode(' ', $connect),
            "media-src 'self' blob:",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
        ]));

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        }

        return $response;
    }
}
