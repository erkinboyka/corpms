<?php

declare(strict_types=1);

namespace App\Domains\Ai\Jobs;

use App\Domains\Ai\Services\BumiAutoReplyService;
use App\Domains\Ai\Services\BumiChannelSender;
use App\Domains\Audit\Services\AuditService;
use App\Models\BumiChannelMessage;
use App\Shared\Enums\BumiMessageDirection;
use App\Support\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/** Генерация и доставка автоответа Bumi во внешний канал. */
class ProcessBumiChannelMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 90;

    public function __construct(protected int $messageId) {}

    public function handle(
        SettingsService $settings,
        BumiAutoReplyService $autoReply,
        BumiChannelSender $sender,
        AuditService $audit,
    ): void {
        $incoming = BumiChannelMessage::query()->with(['channel.agent', 'contact'])->find($this->messageId);

        if ($incoming === null || ! $incoming->isFromClient()) {
            return;
        }

        $channel = $incoming->channel;
        $agent = $channel?->agent;
        $contact = $incoming->contact;

        if ($channel === null || $agent === null || $contact === null
            || ! $channel->enabled || ! $channel->auto_reply
            || ! $agent->enabled || ! $agent->auto_reply) {
            return;
        }

        $settings->useCompany((int) $channel->company_id);

        $alreadyReplied = BumiChannelMessage::query()
            ->where('channel_id', $channel->id)
            ->where('reply_to_id', $incoming->id)
            ->exists();
        if ($alreadyReplied) {
            return;
        }

        $hourly = BumiChannelMessage::query()
            ->where('channel_id', $channel->id)
            ->where('contact_id', $contact->id)
            ->where('direction', BumiMessageDirection::Out->value)
            ->where('created_at', '>=', now()->subHour())
            ->count();
        if ($hourly >= max(1, (int) $agent->max_replies_per_hour)) {
            $incoming->forceFill(['status' => 'rate_limited'])->save();

            return;
        }

        try {
            $reply = $autoReply->reply($agent, (string) $incoming->text, $this->history($incoming));
        } catch (Throwable $e) {
            $reply = trim((string) ($agent->fallback_reply ?: __('Попробуйте повторить вопрос позже.')));
        }

        if ($reply === '') {
            $reply = __('Попробуйте повторить вопрос позже.');
        }

        $outgoing = BumiChannelMessage::query()->create([
            'company_id' => $channel->company_id,
            'channel_id' => $channel->id,
            'agent_id' => $agent->id,
            'contact_id' => $contact->id,
            'external_message_id' => (string) Str::ulid(),
            'direction' => BumiMessageDirection::Out,
            'text' => $reply,
            'payload' => ['source' => 'auto_reply'],
            'status' => 'pending',
            'reply_to_id' => $incoming->id,
        ]);

        $delivered = $sender->send($channel, $contact->external_id, $reply);

        $outgoing->forceFill(['status' => $delivered ? 'sent' : 'failed'])->save();
        $incoming->forceFill(['status' => $delivered ? 'answered' : 'delivery_failed'])->save();

        $audit->log(
            user: null,
            action: 'ai.bumi_auto_replied',
            objectType: 'bumi_channel_message',
            objectId: $outgoing->id,
            newValue: [
                'channel' => $channel->type->value,
                'agent' => $agent->slug,
                'delivered' => $delivered,
            ],
            companyId: (int) $channel->company_id,
        );
    }

    /** @return array<int, array{role: string, content: string}> */
    protected function history(BumiChannelMessage $incoming): array
    {
        $items = BumiChannelMessage::query()
            ->where('channel_id', $incoming->channel_id)
            ->where('contact_id', $incoming->contact_id)
            ->where('id', '<', $incoming->id)
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->reverse();

        $history = [];
        foreach ($items as $item) {
            $history[] = [
                'role' => $item->isFromClient() ? 'user' : 'assistant',
                'content' => (string) $item->text,
            ];
        }

        return $history;
    }
}
