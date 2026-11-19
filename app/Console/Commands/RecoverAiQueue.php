<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Ai\Jobs\RetryAiAnswer;
use App\Domains\Audit\Services\AuditService;
use App\Models\AiConversation;
use App\Support\PermissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class RecoverAiQueue extends Command
{
    protected $signature = 'corpms:ai-recover {--limit=20} {--dry-run}';

    protected $description = 'Повторить только задания AI, сохранив зашифрованную историю разрешённых сбоев';

    public function handle(): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $resolved = 0;
        $errors = 0;
        foreach (['failed_jobs', 'jobs'] as $table) {
            $rows = DB::table($table)->when($table === 'jobs', fn ($q) => $q->whereNull('reserved_at'))->orderBy('id')->limit($limit)->get();
            foreach ($rows as $row) {
                $payload = json_decode($row->payload, true);
                if (($payload['displayName'] ?? null) !== RetryAiAnswer::class) {
                    continue;
                }
                $job = @unserialize($payload['data']['command'] ?? '', ['allowed_classes' => [RetryAiAnswer::class]]);
                if (! $job instanceof RetryAiAnswer) {
                    $errors++;

                    continue;
                }
                if ($this->option('dry-run')) {
                    $this->line($table.' #'.$row->id.': RetryAiAnswer');

                    continue;
                }
                try {
                    DB::transaction(function () use ($row, $table, $job): void {
                        $current = DB::table($table)->where('id', $row->id)->lockForUpdate()->first();
                        if ($current === null || ($table === 'jobs' && $current->reserved_at !== null) || $current->payload !== $row->payload) {
                            return;
                        }
                        $conversation = AiConversation::find($job->conversationId);
                        $user = $conversation?->employee?->user;
                        $allowed = $user !== null && app(PermissionService::class)->check($user, 'analytics.ai.use');
                        $outcome = $allowed ? 'recovered' : 'cancelled_access_revoked';
                        if ($allowed) {
                            $job->handle();
                            if (! $conversation->fresh()?->grounded) {
                                throw new \RuntimeException('Provider did not upgrade the local answer');
                            }
                        }
                        $archive = 'queue-recovery/'.$table.'-'.$row->id.'-'.hash('sha256', $current->payload).'.json.enc';
                        $snapshot = json_encode(['resolved_at' => now()->toIso8601String(), 'outcome' => $outcome, 'original' => $current], JSON_THROW_ON_ERROR);
                        $encrypted = Crypt::encryptString($snapshot);
                        if (! Storage::disk('local')->put($archive, $encrypted)
                            || Storage::disk('local')->get($archive) !== $encrypted) {
                            throw new \RuntimeException('Recovery archive could not be verified');
                        }
                        app(AuditService::class)->log($user, 'ai.queue_resolved', 'ai_conversation', $conversation?->id,
                            null, ['outcome' => $outcome, 'queue_table' => $table, 'queue_id' => $row->id, 'archive' => $archive]);
                        DB::table($table)->where('id', $row->id)->delete();
                    });
                    $resolved++;
                } catch (Throwable $exception) {
                    $errors++;
                    $this->warn($table.' #'.$row->id.': '.get_class($exception).'; задание сохранено');
                }
            }
        }
        $this->info('Обработано: '.$resolved.'; ошибок: '.$errors);

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
