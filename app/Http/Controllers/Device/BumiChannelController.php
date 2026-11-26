<?php

declare(strict_types=1);

namespace App\Http\Controllers\Device;

use App\Domains\Ai\Services\BumiChannelService;
use App\Domains\Ai\Services\BumiWebhookVerifier;
use App\Http\Controllers\Controller;
use App\Models\BumiChannel;
use App\Models\BumiChannelMessage;
use App\Shared\Enums\BumiChannelType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Публичные точки входа внешних каналов Bumi.
 *
 * Канал identifies по публичному токену из URL; провайдер дополнительно
 * подтверждает запрос своим секретом/подписью. Внутренние ID компаний в URL
 * не участвуют — провайдер не может перебрать tenant по номеру.
 */
class BumiChannelController extends Controller
{
    public function __construct(
        protected BumiChannelService $messages,
        protected BumiWebhookVerifier $verifier,
    ) {}

    /** Чат на сайте: клиент забирает свою переписку по visitor-ключу. */
    public function websiteMessages(Request $request, BumiChannel $channel): JsonResponse
    {
        $this->channelFor($channel, BumiChannelType::Website);
        $visitor = $this->visitor($request);
        $validated = $request->validate(['since' => ['sometimes', 'integer', 'min:0']]);
        $since = (int) ($validated['since'] ?? 0);

        $items = BumiChannelMessage::query()
            ->where('channel_id', $channel->id)
            ->where('id', '>', $since)
            ->whereHas('contact', static fn ($query) => $query->where('external_id', $visitor))
            ->with('contact:id,external_id')
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(static fn (BumiChannelMessage $message): array => [
                'id' => $message->id,
                'direction' => $message->direction->value,
                'text' => $message->text,
                'status' => $message->status,
                'status_label' => $message->statusLabel(),
                'client_message_id' => $message->clientMessageId(),
                'created_at' => $message->created_at?->toIso8601String(),
            ]);

        return response()->json(['ok' => true, 'messages' => $items]);
    }

    /** Чат на сайте: новое сообщение клиента и постановка автоответа в очередь. */
    public function websiteMessage(Request $request, BumiChannel $channel): JsonResponse
    {
        $this->channelFor($channel, BumiChannelType::Website);
        $visitor = $this->visitor($request);
        $this->assertEnabled($channel);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'client_message_id' => ['nullable', 'string', 'max:255'],
        ]);

        $clientId = trim((string) ($validated['client_message_id'] ?? ''));
        $payload = [
            'source' => 'website',
            'external_message_id' => $clientId !== '' ? $visitor.':'.$clientId : '',
        ];

        $message = $this->messages->receive(
            $channel,
            $visitor,
            (string) $validated['message'],
            $payload,
            isset($validated['name']) ? (string) $validated['name'] : null,
            isset($validated['phone']) ? (string) $validated['phone'] : null,
            isset($validated['email']) ? (string) $validated['email'] : null,
        );

        return response()->json([
            'ok' => $message !== null,
            'message_id' => $message?->id,
            'client_message_id' => $clientId !== '' ? $clientId : null,
            'status' => $message?->status,
            'status_label' => $message?->statusLabel(),
        ], $message === null ? 422 : 201);
    }

    public function telegram(Request $request, BumiChannel $channel): JsonResponse
    {
        $this->channelFor($channel, BumiChannelType::Telegram);
        if (! $this->verifier->telegram($channel, $request)) {
            return response()->json(['ok' => false], 403);
        }
        $this->assertEnabled($channel);

        $update = $request->validate([
            'update_id' => ['required', 'integer'],
            'message' => ['sometimes', 'array'],
            'message.message_id' => ['required_with:message', 'integer'],
            'message.chat' => ['required_with:message', 'array'],
            'message.chat.id' => ['required_with:message', 'integer'],
            'message.text' => ['required_with:message', 'string', 'max:8000'],
            'message.from' => ['sometimes', 'array'],
            'message.from.first_name' => ['sometimes', 'string', 'max:255'],
            'message.from.last_name' => ['sometimes', 'string', 'max:255'],
            'message.from.username' => ['sometimes', 'string', 'max:255'],
        ]);

        $incoming = $update['message'] ?? null;
        if ($incoming === null) {
            return response()->json(['ok' => true, 'handled' => false]);
        }

        $chatId = (string) $incoming['chat']['id'];
        if (preg_match('/^-?\d{1,20}$/', $chatId) !== 1) {
            return response()->json(['ok' => true, 'handled' => false]);
        }

        $from = $incoming['from'] ?? [];
        $name = trim(((string) ($from['first_name'] ?? '')).' '.((string) ($from['last_name'] ?? '')));
        if ($name === '') {
            $name = (string) ($from['username'] ?? '');
        }

        $message = $this->messages->receive(
            $channel,
            $chatId,
            (string) $incoming['text'],
            [
                'source' => 'telegram',
                'update_id' => (int) $update['update_id'],
                'external_message_id' => 'tg:'.$incoming['message_id'],
            ],
            $name !== '' ? $name : null,
        );

        return response()->json(['ok' => true, 'handled' => $message !== null]);
    }

    public function whatsAppVerify(Request $request, BumiChannel $channel): JsonResponse
    {
        $this->channelFor($channel, BumiChannelType::WhatsApp);
        $challenge = $this->verifier->metaChallenge($channel, $request);

        return $challenge === null
            ? response()->json(['ok' => false], 403)
            : response()->json(['ok' => true, 'challenge' => $challenge]);
    }

    public function whatsApp(Request $request, BumiChannel $channel): JsonResponse
    {
        $this->channelFor($channel, BumiChannelType::WhatsApp);
        if (! $this->verifier->meta($channel, $request)) {
            return response()->json(['ok' => false], 403);
        }
        $this->assertEnabled($channel);

        $data = $request->json()->all();
        $handled = 0;

        foreach (is_array($data['entry'] ?? null) ? array_slice($data['entry'], 0, 10) : [] as $entry) {
            foreach (is_array($entry['changes'] ?? null) ? array_slice($entry['changes'], 0, 10) : [] as $change) {
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                $contacts = is_array($value['contacts'] ?? null) ? $value['contacts'] : [];
                $messages = is_array($value['messages'] ?? null) ? $value['messages'] : [];

                foreach (array_slice($messages, 0, 10) as $item) {
                    if (! is_array($item) || ($item['type'] ?? '') !== 'text') {
                        continue;
                    }
                    $text = trim((string) ($item['text']['body'] ?? ''));
                    $from = (string) ($item['from'] ?? '');
                    if ($text === '' || preg_match('/^\d{6,20}$/', $from) !== 1) {
                        continue;
                    }

                    $name = null;
                    foreach ($contacts as $contact) {
                        if (is_array($contact) && (string) ($contact['wa_id'] ?? '') === $from) {
                            $name = trim((string) ($contact['profile']['name'] ?? '')) ?: null;
                            break;
                        }
                    }

                    $message = $this->messages->receive(
                        $channel,
                        $from,
                        $text,
                        [
                            'source' => 'whatsapp',
                            'external_message_id' => 'wa:'.(string) ($item['id'] ?? (string) Str::ulid()),
                        ],
                        $name,
                    );
                    if ($message !== null) {
                        $handled++;
                    }
                }
            }
        }

        return response()->json(['ok' => true, 'handled' => $handled]);
    }

    public function instagramVerify(Request $request, BumiChannel $channel): JsonResponse
    {
        $this->channelFor($channel, BumiChannelType::Instagram);
        $challenge = $this->verifier->metaChallenge($channel, $request);

        return $challenge === null
            ? response()->json(['ok' => false], 403)
            : response()->json(['ok' => true, 'challenge' => $challenge]);
    }

    public function instagram(Request $request, BumiChannel $channel): JsonResponse
    {
        $this->channelFor($channel, BumiChannelType::Instagram);
        if (! $this->verifier->meta($channel, $request)) {
            return response()->json(['ok' => false], 403);
        }
        $this->assertEnabled($channel);

        $data = $request->json()->all();
        $handled = 0;

        foreach (is_array($data['entry'] ?? null) ? array_slice($data['entry'], 0, 10) : [] as $entry) {
            foreach (is_array($entry['messaging'] ?? null) ? array_slice($entry['messaging'], 0, 10) : [] as $event) {
                if (! is_array($event) || ($event['message']['is_echo'] ?? false) === true) {
                    continue;
                }
                $senderId = (string) ($event['sender']['id'] ?? '');
                $text = trim((string) ($event['message']['text'] ?? ''));
                if (preg_match('/^[A-Za-z0-9_]{3,64}$/', $senderId) !== 1 || $text === '') {
                    continue;
                }

                $message = $this->messages->receive(
                    $channel,
                    $senderId,
                    $text,
                    [
                        'source' => 'instagram',
                        'external_message_id' => 'ig:'.(string) ($event['message']['mid'] ?? $event['id'] ?? (string) Str::ulid()),
                    ],
                    isset($event['sender']['name']) ? (string) $event['sender']['name'] : null,
                );
                if ($message !== null) {
                    $handled++;
                }
            }
        }

        return response()->json(['ok' => true, 'handled' => $handled]);
    }

    public function greenApiVerify(Request $request, BumiChannel $channel): JsonResponse
    {
        $this->channelFor($channel, BumiChannelType::GreenApi);

        $challenge = (string) $request->query('challenge', $request->query('hub_challenge', ''));
        if ($challenge !== '' && $this->verifier->greenApi($channel, $request)) {
            return response()->json(['ok' => true, 'challenge' => mb_substr($challenge, 0, 255)]);
        }

        return response()->json(['ok' => false], 403);
    }

    public function greenApi(Request $request, BumiChannel $channel): JsonResponse
    {
        $this->channelFor($channel, BumiChannelType::GreenApi);
        if (! $this->verifier->greenApi($channel, $request)) {
            return response()->json(['ok' => false], 403);
        }
        $this->assertEnabled($channel);

        $data = $request->json()->all();

        // Токен подтверждает запрос, но webhook ещё должен принадлежать именно
        // тому экземпляру Green API, который подключён к каналу.
        $configuredInstance = preg_replace('/\D+/', '', (string) $channel->credential('instance_id')) ?? '';
        $providedInstance = (string) ($data['instanceData']['idInstance'] ?? '');
        if ($configuredInstance !== '' && ! hash_equals($configuredInstance, $providedInstance)) {
            return response()->json(['ok' => false], 403);
        }

        $kind = (string) ($data['typeWebhook'] ?? '');
        if (! in_array($kind, ['incomingMessageReceived', 'incomingMessageReceivedCallback'], true)) {
            return response()->json(['ok' => true, 'handled' => false]);
        }

        $chatId = (string) ($data['senderData']['chatId'] ?? '');
        $text = trim((string) ($data['messageData']['textMessageData']['textMessage'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_@.\-]+$/', $chatId) !== 1 || $text === '') {
            return response()->json(['ok' => true, 'handled' => false]);
        }

        $message = $this->messages->receive(
            $channel,
            $chatId,
            $text,
            [
                'source' => 'greenapi',
                'external_message_id' => 'ga:'.(string) ($data['idMessage'] ?? (string) Str::ulid()),
            ],
            isset($data['senderData']['senderName']) ? (string) $data['senderData']['senderName'] : null,
            isset($data['senderData']['sender']) ? (string) $data['senderData']['sender'] : null,
        );

        return response()->json(['ok' => true, 'handled' => $message !== null]);
    }

    protected function channelFor(BumiChannel $channel, BumiChannelType $type): BumiChannel
    {
        abort_unless($channel->type === $type, 404);

        return $channel;
    }

    protected function assertEnabled(BumiChannel $channel): void
    {
        abort_unless($channel->enabled && $channel->agent?->enabled, 403, __('Канал Bumi отключён'));
    }

    protected function visitor(Request $request): string
    {
        $visitor = trim((string) $request->input('visitor', $request->query('visitor', '')));
        abort_unless(preg_match('/^[A-Za-z0-9_-]{16,64}$/', $visitor) === 1, 422, __('Некорректный visitor-ключ'));

        return $visitor;
    }
}
