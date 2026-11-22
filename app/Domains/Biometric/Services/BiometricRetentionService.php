<?php

declare(strict_types=1);

namespace App\Domains\Biometric\Services;

use App\Models\BiometricEvent;
use Illuminate\Support\Carbon;

/**
 * SEC-09: журнал событий хранится, а исходный payload очищается по истечении срока.
 */
class BiometricRetentionService
{
    /** Очистить raw_payload у событий старше срока хранения. */
    public function pruneRawPayloads(?int $retentionDays = null): int
    {
        $days = max(1, $retentionDays ?? (int) config('corpms.biometric.raw_payload_retention_days', 30));
        $cutoff = Carbon::now()->subDays($days);

        return BiometricEvent::query()
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('raw_payload')
            ->update(['raw_payload' => []]);
    }
}
