<?php

declare(strict_types=1);

namespace App\Support\Queue;

use Illuminate\Support\Facades\Cache;

/**
 * Heartbeat пулов воркеров.
 *
 * Отмечается на каждом обработанном задании (Queue::before) и canary-заданием
 * worker:selfcheck. По расписанию canary приходит в каждый пул раз в 5 минут,
 * поэтому свежий heartbeat означает, что воркер реально жив и крутит текущий код.
 */
final class WorkerHeartbeat
{
    public function beat(string $pool, ?string $version = null): void
    {
        $ttl = now()->addMinutes(max(1, (int) config('worker.heartbeat_ttl_minutes', 10)));

        Cache::put($this->key($pool), [
            'pool' => $pool,
            'at' => now()->toIso8601String(),
            'version' => $version ?? $this->version(),
        ], $ttl);
    }

    /**
     * @return array{fresh: bool, at: ?string, age_seconds: ?int, version: ?string}
     */
    public function status(string $pool): array
    {
        $state = Cache::get($this->key($pool));

        if (! is_array($state) || ! isset($state['at'])) {
            return ['fresh' => false, 'at' => null, 'age_seconds' => null, 'version' => null];
        }

        $at = \Illuminate\Support\Carbon::parse((string) $state['at']);
        $age = (int) $at->diffInSeconds(now());
        $ttl = max(1, (int) config('worker.heartbeat_ttl_minutes', 10)) * 60;

        return [
            'fresh' => $age <= $ttl,
            'at' => (string) $state['at'],
            'age_seconds' => $age,
            'version' => isset($state['version']) ? (string) $state['version'] : null,
        ];
    }

    /** @return array<string, string> pool => queue */
    public function pools(): array
    {
        $out = [];

        foreach ((array) config('worker.pools', []) as $pool => $settings) {
            if (is_array($settings) && isset($settings['queue']) && is_string($pool)) {
                $out[$pool] = (string) $settings['queue'];
            }
        }

        return $out;
    }

    public function poolForQueue(string $queue): string
    {
        foreach ($this->pools() as $pool => $configuredQueue) {
            if ($configuredQueue === $queue) {
                return $pool;
            }
        }

        return 'default';
    }

    /** Короткий отпечаток кода: чтобы увидеть, что воркер не на старой версии. */
    public function version(): string
    {
        $lock = base_path('composer.lock');
        $seed = is_file($lock) ? (string) md5_file($lock) : (string) config('app.key');

        return substr(sha1($seed.'|'.(string) config('app.env')), 0, 12);
    }

    private function key(string $pool): string
    {
        return 'ops.worker.heartbeat:'.preg_replace('/[^a-z0-9_-]/i', '', $pool);
    }
}
