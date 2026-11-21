<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Chat\Services\ChatService;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageEvent;
use App\Shared\Enums\ConversationRole;
use App\Shared\Enums\ConversationType;
use App\Shared\Enums\EmployeeStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * BM Chat — веб-интерфейс внутреннего мессенджера.
 *
 * HTML-маршруты рендерят страницу, JSON-маршруты обслуживают ленту
 * и синхронизацию. Права на каждый диалог проверяются в ChatService,
 * а не только на входе в раздел.
 */
class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chat)
    {
    }

    /** Страница чатов: список диалогов + открытый диалог. */
    public function index(Request $request, ?Conversation $conversation = null): View
    {
        $me = $request->user()->employee;

        $conversations = $this->chat->listFor($me);

        if ($conversation !== null) {
            $this->chat->assertCanView($me, $conversation);
        }
        $membership = $conversation ? $this->chat->memberRow($me, $conversation) : null;

        // Граница дельт: клиент стартует с последнего события, а не с нуля,
        // чтобы первый опрос не присылал всю историю журнала.
        $lastEventId = $conversation !== null
            ? (int) MessageEvent::query()
                ->where('conversation_id', $conversation->id)
                ->max('id')
            : 0;

        return view('chat.index', [
            'conversations' => $conversations,
            'active' => $conversation,
            'members' => $conversation ? $conversation->members()->whereNull('left_at')->with('employee')->get() : collect(),
            'canManageChat' => $conversation && $this->chat->canManage($me, $conversation),
            'chatOwner' => $membership?->role === ConversationRole::Owner,
            'hasWallpaper' => $membership?->wallpaper_path !== null,
            'messages' => $conversation !== null
                ? $this->chat->messages($me, $conversation)
                : collect(),
            'lastEventId' => $lastEventId,
            'unreadTotal' => $conversations->sum('unread_count'),
        ]);
    }

    /** Лента сообщений конкретного диалога (JSON для polling). */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user()->employee;
        $before = $request->integer('before') ?: null;

        return response()->json([
            'messages' => $this->chat->messages($me, $conversation, $before),
            'unread_count' => $this->chat->unreadCount($me, $conversation),
        ]);
    }

    /** Дельты после события N — восстановление после обрыва связи. */
    public function events(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user()->employee;
        $since = $request->integer('since', 0);

        $events = $this->chat->eventsSince($me, $conversation, $since);

        return response()->json([
            'events' => $events,
            'last_event_id' => $events->max('id') ?? $since,
            'unread_count' => $this->chat->unreadCount($me, $conversation),
        ]);
    }

    /** Сводка непрочитанных по всем диалогам (бейдж в меню + синхронизация списка). */
    public function unread(Request $request): JsonResponse
    {
        $me = $request->user()->employee;
        $conversations = $this->chat->listFor($me);

        return response()->json([
            'total' => $conversations->sum('unread_count'),
            'conversations' => $conversations
                ->map(fn (Conversation $c): array => [
                    'id' => $c->id,
                    'unread_count' => (int) ($c->unread_count ?? 0),
                ])
                ->values(),
        ]);
    }

    /** Отправка сообщения. */
    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:8000'],
            'client_id' => ['nullable', 'string', 'max:64'],
            'reply_to_id' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*.name' => ['required_with:attachments', 'string', 'max:255'],
        ]);

        $message = $this->chat->send($request->user()->employee, $conversation, $data);

        return response()->json(['message' => $message], 201);
    }

    public function update(Request $request, Message $message): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:8000'],
        ]);

        $message = $this->chat->edit($request->user()->employee, $message, $data['body']);

        return response()->json(['message' => $message]);
    }

    public function destroy(Request $request, Message $message): JsonResponse
    {
        $this->chat->delete($request->user()->employee, $message);

        return response()->json(['ok' => true]);
    }

    public function react(Request $request, Message $message): JsonResponse
    {
        $data = $request->validate([
            'emoji' => ['required', 'string', 'max:16'],
            'on' => ['required', 'boolean'],
        ]);

        $this->chat->react(
            $request->user()->employee,
            $message,
            $data['emoji'],
            (bool) $data['on'],
        );

        return response()->json(['reactions' => $message->fresh()->reactions]);
    }

    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'up_to_message_id' => ['nullable', 'integer'],
        ]);

        $unread = $this->chat->markRead(
            $request->user()->employee,
            $conversation,
            isset($data['up_to_message_id']) ? (int) $data['up_to_message_id'] : null,
        );

        return response()->json(['unread_count' => $unread]);
    }

    public function subscribe(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'subscribe' => ['required', 'boolean'],
        ]);

        $member = $this->chat->toggleSubscription(
            $request->user()->employee,
            $conversation,
            (bool) $data['subscribe'],
        );

        return response()->json(['subscribed' => $member->is_subscribed]);
    }

    /** Создание диалога: личный, группа или канал. */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:direct,group,channel'],
            'title' => ['required_unless:type,direct', 'nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'employee_id' => ['nullable', 'integer'],
            'member_ids' => ['nullable', 'array', 'max:200'],
            'member_ids.*' => ['integer'],
            'visibility' => ['nullable', 'in:private,company'],
            'post_permission' => ['nullable', 'in:all,admins'],
        ]);

        $me = $request->user()->employee;
        $type = ConversationType::from($data['type']);

        $conversation = match ($type) {
            ConversationType::Direct => (function () use ($request, $me, $data): Conversation {
                if (empty($data['employee_id'])) {
                    throw ValidationException::withMessages([
                        'employee_id' => __('Выберите сотрудника'),
                    ]);
                }

                $peer = Employee::query()
                    ->whereKey((int) $data['employee_id'])
                    ->where('company_id', $me->company_id)
                    ->firstOrFail();

                return $this->chat->findOrCreateDirect($me, $peer);
            })(),

            ConversationType::Group => $this->chat->createGroup(
                $me,
                (string) ($data['title'] ?? ''),
                $data['member_ids'] ?? [],
                $data['description'] ?? null,
            ),

            ConversationType::Channel => $this->chat->createChannel($me, $data),
        };

        if ($request->expectsJson()) {
            return response()->json(['conversation' => $conversation], 201);
        }

        return redirect()->route('chat.show', $conversation);
    }

    public function updateConversation(Request $request, Conversation $conversation): RedirectResponse
    {
        $me = $request->user()->employee;
        $this->chat->assertCanManage($me, $conversation);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:4096', 'dimensions:max_width=4096,max_height=4096'],
            'reset_avatar' => ['nullable', 'boolean'],
        ]);
        $this->chat->updateConversation($me, $conversation, $data, $request->file('avatar'), $request->boolean('reset_avatar'));

        return redirect()->route('chat.show', $conversation)->with('status', __('Настройки диалога сохранены'));
    }

    public function deleteConversation(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->chat->deleteConversation($request->user()->employee, $conversation);

        return redirect()->route('chat.index')->with('status', $conversation->isDirect() ? __('Диалог скрыт из вашего списка') : __('Диалог удалён'));
    }

    public function addMembers(Request $request, Conversation $conversation): RedirectResponse
    {
        $data = $request->validate(['member_ids' => ['required', 'array', 'min:1', 'max:200'], 'member_ids.*' => ['integer', 'min:1']]);
        $this->chat->addMembers($request->user()->employee, $conversation, $data['member_ids']);

        return redirect()->route('chat.show', $conversation)->with('status', __('Участники добавлены'));
    }

    public function removeMember(Request $request, Conversation $conversation, int $employee): RedirectResponse
    {
        $this->chat->removeMember($request->user()->employee, $conversation, $employee);

        return redirect()->route('chat.show', $conversation)->with('status', __('Участник исключён'));
    }

    public function wallpaper(Request $request, Conversation $conversation): RedirectResponse
    {
        $request->validate([
            'wallpaper' => ['required_unless:reset_wallpaper,1', 'nullable', 'image', 'mimes:jpeg,png,webp', 'max:8192', 'dimensions:max_width=4096,max_height=4096'],
            'reset_wallpaper' => ['nullable', 'boolean'],
        ]);
        $this->chat->setWallpaper($request->user()->employee, $conversation, $request->file('wallpaper'));

        return redirect()->route('chat.show', $conversation)->with('status', __('Обои обновлены'));
    }

    public function image(Request $request, Conversation $conversation, string $kind): StreamedResponse
    {
        $me = $request->user()->employee;
        $this->chat->assertCanView($me, $conversation);
        $avatar = $kind === 'avatar';
        $folder = $avatar ? 'avatars' : 'wallpapers';
        $path = $avatar ? $conversation->avatar_path : $this->chat->memberRow($me, $conversation)?->wallpaper_path;
        $disk = Storage::disk('local');
        abort_unless($path && str_starts_with($path, "chat/{$conversation->id}/{$folder}/") && $disk->exists($path), 404);
        $mime = $disk->mimeType($path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return $disk->response($path, null, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Поиск сотрудников для нового диалога.
     * Скоуп — только сотрудники своей компании.
     */
    public function employees(Request $request): JsonResponse
    {
        $me = $request->user()->employee;
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'conversation' => ['nullable', 'integer']]);
        $query = trim($data['q'] ?? '');
        $conversation = isset($data['conversation']) ? Conversation::query()->findOrFail($data['conversation']) : null;
        if ($conversation !== null) {
            $this->chat->assertCanManage($me, $conversation);
            abort_if($conversation->isCompanyWide(), 403);
        }

        $employees = Employee::query()
            ->where('company_id', $me->company_id)
            ->where('status', EmployeeStatus::Active)
            ->where('id', '!=', $me->id)
            ->when($conversation, fn ($q) => $q->whereNotIn('id', $conversation->members()->whereNull('left_at')->select('employee_id')));
        foreach (preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
            $like = '%'.$term.'%';
            $employees->where(function ($inner) use ($like): void {
                $inner->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }
        $employees = $employees
            ->orderBy('last_name')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name', 'email', 'position_id']);

        return response()->json(['employees' => $employees]);
    }
}
