<?php

declare(strict_types=1);

namespace App\Support;

/**
 * AI-04 / SEC-08: адрес LLM/embeddings-провайдера не может указывать во внутреннюю сеть.
 *
 * Правила:
 *  - только http/https, без userinfo и нестандартных портов;
 *  - IP-литералы запрещены (в т.ч. loopback, private, link-local, 169.254.169.254);
 *  - если задан whitelist `corpms.ai.allowed_hosts` — хост обязан быть в нём;
 *    иначе допустим хост из installation-level `AI_BASE_URL`;
 *  - DNS-имя, резолвящееся в private/loopback/link-local, отклоняется;
 *  - нерезолвящееся имя разрешено только для зарезервированных тестовых TLD
 *    (.test/.example/.invalid) — они не маршрутизируются в Internet.
 *
 * Окончательная защита от DNS-rebinding — egress firewall на хосте; код
 * дополнительно отключает redirects и проверяет адрес перед каждым запросом.
 */
final class AiEndpointGuard
{
    /** @var array<int, string> */
    private const RESERVED_TEST_TLDS = ['test', 'example', 'invalid'];

    /** @var array<int, string> RFC 2606 — в Internet не маршрутизируются */
    private const RESERVED_EXAMPLE_DOMAINS = ['example.com', 'example.net', 'example.org'];

    public static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true)) {
            return false;
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        if ($host === '' || str_ends_with($host, '.localhost') || $host === 'localhost') {
            return false;
        }

        $allowed = self::allowedHosts();
        if ($allowed !== []) {
            // installation-level whitelist — доверенный адрес, DNS больше не нужен
            return self::hostMatches($host, $allowed);
        }

        if (self::isReservedTestHost($host)) {
            return true;
        }

        $addresses = @gethostbynamel($host);
        if (! is_array($addresses) || $addresses === []) {
            return false; // fail closed: неизвестный хост не получает api-ключ
        }

        foreach ($addresses as $address) {
            if (self::isPrivateIp((string) $address)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> */
    public static function allowedHosts(): array
    {
        $configured = config('corpms.ai.allowed_hosts', []);
        $hosts = is_string($configured)
            ? preg_split('/[\s,;]+/', $configured, -1, PREG_SPLIT_NO_EMPTY) ?: []
            : (is_array($configured) ? $configured : []);

        $global = (string) config('corpms.ai.base_url', '');
        $globalHost = is_array(parse_url($global)) ? parse_url($global)['host'] ?? '' : '';
        if ($globalHost !== '') {
            $hosts[] = strtolower(rtrim((string) $globalHost, '.'));
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $host): string => strtolower(trim((string) $host)),
            $hosts,
        ))));
    }

    /** @param array<int, string> $allowed */
    private static function hostMatches(string $host, array $allowed): bool
    {
        foreach ($allowed as $pattern) {
            $pattern = strtolower(ltrim((string) $pattern, '.'));
            if ($pattern === '') {
                continue;
            }
            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 2);
                if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                    return true;
                }
            } elseif ($host === $pattern) {
                return true;
            }
        }

        return false;
    }

    private static function isReservedTestHost(string $host): bool
    {
        foreach (self::RESERVED_TEST_TLDS as $tld) {
            if (str_ends_with($host, '.'.$tld)) {
                return true;
            }
        }

        foreach (self::RESERVED_EXAMPLE_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private static function isPrivateIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
