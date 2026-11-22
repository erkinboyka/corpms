<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Атомарный single-flight lock поверх cache.
 *
 * Cache::add() atomically sets the key only when it is absent, so concurrent
 * requests never both acquire the lease. The stored token prevents a late
 * release from deleting somebody else's lease.
 */
final class SingleFlightLock
{
    private const PREFIX = 'singleflight:';

    private const LEASE_TTL_MINUTES = 10;

    public static function acquire(string $key): ?string
    {
        $stateKey = self::stateKey($key);
        $token = Str::random(32);

        return Cache::add($stateKey, $token, now()->addMinutes(self::LEASE_TTL_MINUTES))
            ? $token
            : null;
    }

    public static function release(string $key, string $token): void
    {
        $stateKey = self::stateKey($key);

        if (Cache::get($stateKey) === $token) {
            Cache::forget($stateKey);
        }
    }

    private static function stateKey(string $key): string
    {
        return self::PREFIX.sha1($key);
    }
}
