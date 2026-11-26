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

        // Микрофон-релей открывается в iframe с http://corpms.local, поэтому
        // для этого одного endpoint'а XFO снимается, а frame-ancestors сужается
        // до явного белого списка родительских origin (CSP replaces XFO).
        $relayParents = $this->relayParents($request);
        if ($relayParents === []) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        } else {
            $response->headers->remove('X-Frame-Options');
        }
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // LMS video calls need camera/microphone/screen capture on this origin.
        // На http://corpms.local микрофон недоступен, поэтому доверенный
        // localhost-relay получает право через явный allowlist, а не через '*'.
        $relayOrigin = $this->relayOrigin();
        $microphone = $relayOrigin !== ''
            ? 'microphone=(self '.$relayOrigin.')'
            : 'microphone=(self)';
        $response->headers->set('Permissions-Policy', 'camera=(self), '.$microphone.', display-capture=(self), geolocation=()');

        // Content-Security-Policy (P1-4 аудита): ресурсы — только со своих origin,
        // внешние скрипты/фреймы/объекты запрещены. 'unsafe-inline' для script/style
        // сохранён временно: инлайн-скрипты есть в layout и ~12 view; переход на nonce
        // — отдельная задача (см. ROADMAP, инфраструктура).
        //
        // connect-src: websocket к Reverb — только заданный в .env хост/порт,
        // чтобы realtime-канал не расширял список разрешённых источников.
        $connect = ["'self'"];
        $frameSources = ["'self'"];
        $relayUrl = $this->relayOrigin();
        if ($relayUrl !== '') {
            $frameSources[] = $relayUrl;
        }

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
            'frame-src '.implode(' ', array_values(array_unique($frameSources))),
            'frame-ancestors '.implode(' ', $relayParents !== [] ? $relayParents : ["'self'"]),
            "base-uri 'self'",
            "form-action 'self'",
        ]));

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        }

        return $response;
    }

    private function relayOrigin(): string
    {
        $url = rtrim((string) config('corpms.bumi.conversation.relay_url', ''), '/');
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * @return list<string>
     */
    private function relayParents(Request $request): array
    {
        if (! $request->is('bumi/voice/relay')) {
            return [];
        }

        $parent = (string) $request->query('parent', '');
        $allowed = (array) config('corpms.bumi.conversation.parent_origins', []);

        return in_array($parent, $allowed, true) ? [$parent] : [];
    }
}
