<?php

declare(strict_types=1);

namespace App\Domains\Ai\Jobs;

use App\Domains\Ai\Services\AiAssistantService;
use App\Domains\Ai\Services\AiPanelService;
use App\Models\AiConversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * D3: повторный LLM-вызов для диалога, который не успел/не смог ответить
 * синхронно (потолок corpms.ai.sync_timeout). Пользователь уже получил
 * локальный ответ, job обновляет answer и grounded, когда LLM доступен.
 */
class RetryAiAnswer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int LLM может быть временно недоступен — даём несколько попыток. */
    public int $tries = 3;

    /** @var array<int, int> Паузы между попытками, сек. */
    public array $backoff = [15, 60];

    public function __construct(
        public int $conversationId,
        public bool $panel = false,
    ) {}

    public function handle(): void
    {
        $conversation = AiConversation::query()->find($this->conversationId);

        if ($conversation === null || (bool) $conversation->grounded) {
            return; // запись удалена или уже отвечена LLM
        }

        $service = $this->panel
            ? app(AiPanelService::class)
            : app(AiAssistantService::class);

        if ($service->retryLlm($conversation)) {
            return;
        }

        // временный сбой (LLM недоступен) — job уйдёт в повтор с backoff
        throw new RuntimeException('LLM не ответил повторно, диалог #'.$this->conversationId);
    }
}
