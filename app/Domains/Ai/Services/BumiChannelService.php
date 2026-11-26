<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Ai\Jobs\ProcessBumiChannelMessage;
use App\Domains\Audit\Services\AuditService;
use App\Models\BumiChannel;
use App\Models\BumiChannelContact;
use App\Models\BumiChannelMessage;
use App\Models\CrmLead;
use App\Models\Employee;
use App\Shared\Enums\BumiMessageDirection;
use App\Shared\Enums\CrmLeadStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Приём входящих сообщений из внешних каналов.
 *
 * Всё пишется в транзакции: контакт, сообщение и (при первом обращении) лид CRM.
 * Повторная доставка webhook'а не создаёт дубликат благодаря external_message_id.
 */
class BumiChannelService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function receive(
        BumiChannel $channel,
        string $externalId,
        string $text,
        array $payload = [],
        ?string $displayName = null,
        ?string $phone = null,
        ?string $email = null,
    ): ?BumiChannelMessage {
        if (! $channel->enabled || ! $channel->agent || ! $channel->agent->enabled) {
            return null;
        }

        $externalId = Str::limit(trim($externalId) !== '' ? trim($externalId) : 'unknown', 255, '');
        $text = trim(Str::limit($text, 8000, ''));
        if ($text === '') {
            return null;
        }

        $externalMessageId = trim((string) ($payload['external_message_id'] ?? ''));
        if ($externalMessageId === '') {
            $externalMessageId = (string) Str::ulid();
        }
        $externalMessageId = Str::limit($externalMessageId, 255, '');

        $message = DB::transaction(function () use (
            $channel, $externalId, $text, $payload, $displayName, $phone, $email, $externalMessageId,
        ): ?BumiChannelMessage {
            $existing = BumiChannelMessage::query()
                ->where('channel_id', $channel->id)
                ->where('direction', BumiMessageDirection::In->value)
                ->where('external_message_id', $externalMessageId)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $contact = BumiChannelContact::query()->firstOrCreate([
                'channel_id' => $channel->id,
                'external_id' => $externalId,
            ], [
                'company_id' => $channel->company_id,
                'display_name' => $displayName,
                'phone' => $phone,
                'email' => $email,
                'metadata' => [],
                'last_seen_at' => now(),
            ]);

            $contact->fill([
                'display_name' => $displayName ?: $contact->display_name,
                'phone' => $phone ?: $contact->phone,
                'email' => $email ?: $contact->email,
                'last_seen_at' => now(),
            ])->save();

            $autoReply = $channel->auto_reply && $channel->agent->auto_reply;

            $created = BumiChannelMessage::query()->create([
                'company_id' => $channel->company_id,
                'channel_id' => $channel->id,
                'agent_id' => $channel->agent_id,
                'contact_id' => $contact->id,
                'external_message_id' => $externalMessageId,
                'direction' => BumiMessageDirection::In,
                'text' => $text,
                'payload' => $payload,
                'status' => $autoReply ? 'queued' : 'received',
            ]);

            $this->ensureLead($contact, $text);

            return $created;
        });

        if ($message !== null && $message->status === 'queued') {
            ProcessBumiChannelMessage::dispatch($message->id);
        }

        return $message;
    }

    /** Первое обращение контакта становится лидом CRM с источником канала. */
    protected function ensureLead(BumiChannelContact $contact, string $text): void
    {
        $channel = $contact->channel;
        $agent = $channel?->agent;

        if ($channel === null || $agent === null || ! $agent->create_leads || $contact->crm_lead_id !== null) {
            return;
        }

        try {
            $ownerId = null;
            if ($agent->default_owner_id !== null) {
                $ownerId = Employee::query()
                    ->where('id', $agent->default_owner_id)
                    ->where('company_id', $channel->company_id)
                    ->value('id');
            }

            $lead = CrmLead::query()->create([
                'company_id' => $channel->company_id,
                'title' => Str::limit($contact->displayLabel().' — '.$channel->type->label(), 255, ''),
                'source' => $channel->type->leadSource(),
                'contact_name' => $contact->display_name,
                'phone' => $contact->phone,
                'email' => $contact->email,
                'status' => CrmLeadStatus::New,
                'owner_id' => $ownerId,
                'notes' => Str::limit(__('Первое обращение: ').$text, 2000, ''),
                'created_by' => $agent->created_by,
            ]);

            $contact->forceFill(['crm_lead_id' => $lead->id])->save();

            $this->audit->log(
                user: null,
                action: 'crm.lead_created',
                objectType: 'crm_lead',
                objectId: $lead->id,
                newValue: [
                    'title' => $lead->title,
                    'source' => $lead->source->value,
                    'bumi_channel' => $channel->type->value,
                ],
                companyId: (int) $channel->company_id,
            );
        } catch (Throwable) {
            // сбой создания лида не должен ронять приём сообщения — диалог важнее.
        }
    }
}
