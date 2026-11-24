<?php

declare(strict_types=1);

namespace App\Domains\Lms\Services;

use App\Models\LmsActivity;
use App\Models\LmsMessage;
use App\Models\LmsSignal;
use App\Models\LmsWhiteboardElement;
use App\Models\LmsWhiteboardPresence;
use Illuminate\Support\Carbon;

/**
 * Conservative retention for high-volume LMS data. Business records
 * (courses, assignments, submissions) are not pruned here.
 */
class LmsRetentionService
{
    /** @return array<string,int> */
    public function prune(): array
    {
        $now = Carbon::now();

        return [
            'signals' => $this->pruneSignals($now),
            'presence' => $this->prunePresence($now),
            'activities' => $this->pruneActivities($now),
            'erased_elements' => $this->pruneErasedElements($now),
            'messages' => $this->pruneMessages($now),
        ];
    }

    private function pruneSignals(Carbon $now): int
    {
        $days = config('lms.retention.signals_days');
        if ($days === null) {
            return 0;
        }

        return LmsSignal::query()
            ->where('created_at', '<', $now->copy()->subDays(max(1, (int) $days)))
            ->delete();
    }

    private function prunePresence(Carbon $now): int
    {
        $days = config('lms.retention.presence_days');
        if ($days === null) {
            return 0;
        }

        return LmsWhiteboardPresence::query()
            ->where('seen_at', '<', $now->copy()->subDays(max(1, (int) $days)))
            ->delete();
    }

    private function pruneActivities(Carbon $now): int
    {
        $days = config('lms.retention.activities_days');
        if ($days === null) {
            return 0;
        }

        return LmsActivity::query()
            ->where('created_at', '<', $now->copy()->subDays(max(1, (int) $days)))
            ->delete();
    }

    private function pruneErasedElements(Carbon $now): int
    {
        $days = config('lms.retention.erased_elements_days');
        if ($days === null) {
            return 0;
        }

        return LmsWhiteboardElement::query()
            ->where('is_erased', true)
            ->where('updated_at', '<', $now->copy()->subDays(max(1, (int) $days)))
            ->delete();
    }

    private function pruneMessages(Carbon $now): int
    {
        $days = config('lms.retention.messages_days');
        if ($days === null) {
            return 0;
        }

        return LmsMessage::query()
            ->where('created_at', '<', $now->copy()->subDays(max(1, (int) $days)))
            ->delete();
    }
}
