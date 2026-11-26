<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Ai\Jobs\ProcessBumiChannelMessage;
use App\Domains\Ai\Services\BumiAgentService;
use App\Domains\Ai\Services\BumiAutoReplyService;
use App\Domains\Ai\Services\BumiKnowledgeService;
use App\Domains\Ai\Services\AiProviderService;
use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\BumiAgent;
use App\Models\BumiChannel;
use App\Models\BumiChannelContact;
use App\Models\BumiChannelMessage;
use App\Models\BumiKnowledgeItem;
use App\Models\Employee;
use App\Models\User;
use App\Shared\Enums\BumiChannelType;
use App\Shared\Enums\BumiMessageDirection;
use App\Support\PermissionService;
use App\Support\SettingsService;
use App\Support\TenantGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Экраны настроек Bumi: агенты, каналы, автоответы, диалоги.
 *
 * Структура повторяет Velora Agents: список агентов-карточек, детальная
 * страница с вкладками (?tab=), карточки каналов и отдельная страница канала.
 * Всё на обычных Blade-формах без redesign'а основного интерфейса.
 */
class BumiSettingsController extends Controller
{
    private const TABS = ['settings', 'channels', 'knowledge', 'playground', 'conversations'];

    private const CHANNEL_ORDER = ['website', 'telegram', 'whatsapp', 'instagram', 'greenapi'];

    public function __construct(
        protected BumiAgentService $agents,
        protected BumiAutoReplyService $autoReply,
        protected BumiKnowledgeService $knowledge,
        protected AuditService $audit,
        protected SettingsService $settings,
        protected PermissionService $permissions,
        protected AiProviderService $providers,
    ) {}

    public function index(Request $request): View
    {
        $companyId = $this->companyId($request);
        $this->agents->ensureDefaults($companyId, $request->user()->id);

        $agentModels = BumiAgent::query()
            ->where('company_id', $companyId)
            ->with(['channels', 'defaultOwner'])
            ->orderBy('name')
            ->get();

        $channels = $this->orderedChannels(
            BumiChannel::query()->where('company_id', $companyId)->with('agent')->get(),
        );

        return view('settings.bumi.index', [
            'agents' => $agentModels,
            'channels' => $channels,
            'stats' => [
                'contacts' => BumiChannelContact::query()->where('company_id', $companyId)->count(),
                'messages' => BumiChannelMessage::query()->where('company_id', $companyId)->count(),
                'leads' => BumiChannelContact::query()->where('company_id', $companyId)->whereNotNull('crm_lead_id')->count(),
            ],
            'canManage' => $this->canManage($request),
            'types' => BumiChannelType::cases(),
        ]);
    }

    public function showAgent(Request $request, BumiAgent $agent): View
    {
        $this->guard($agent, $request);

        $tab = (string) $request->query('tab', 'settings');
        if (! in_array($tab, self::TABS, true)) {
            $tab = 'settings';
        }

        $this->settings->useCompany((int) $agent->company_id);
        $availableModels = $this->availableModels($agent);

        $channels = $this->orderedChannels(BumiChannel::query()->where('agent_id', $agent->id)->get());
        $channelFilter = (string) $request->query('channel', '');
        if ($channelFilter !== '' && $channels->first(fn (BumiChannel $item): bool => $item->type->value === $channelFilter) === null) {
            $channelFilter = '';
        }
        $viewMode = (string) $request->query('view', 'table') === 'cards' ? 'cards' : 'table';

        $messageQuery = BumiChannelMessage::query()
            ->where('agent_id', $agent->id)
            ->with(['channel', 'contact']);
        if ($channelFilter !== '') {
            $messageQuery->whereHas('channel', static fn ($query) => $query->where('type', $channelFilter));
        }

        return view('settings.bumi.agent', [
            'agent' => $agent->load(['defaultOwner', 'creator']),
            'availableModels' => $availableModels,
            'defaultModel' => (string) ($this->settings->get('corpms.ai.model') ?: config('corpms.ai.model', '')),
            'knowledgeItems' => BumiKnowledgeItem::query()
                ->where('company_id', $agent->company_id)
                ->where('agent_id', $agent->id)
                ->orderBy('kind')->orderBy('title')
                ->get(),
            'generatedQuestions' => array_values((array) session('bumi_generated_questions', [])),
            'sampleQuestions' => array_map(
                static fn (array $pair): string => (string) $pair['question'],
                array_slice(BumiKnowledgeService::pack(), 0, 5),
            ),
            'channels' => $channels,
            'selectedChannel' => (string) $request->query('channel', ''),
            'channelFilter' => $channelFilter,
            'viewMode' => $viewMode,
            'messages' => $messageQuery->orderByDesc('id')->limit(60)->get(),
            'employees' => Employee::query()
                ->where('company_id', $agent->company_id)
                ->where('status', 'active')
                ->orderBy('last_name')->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name']),
            'tab' => $tab,
            'tabs' => self::TABS,
            'canManage' => $this->canManage($request),
            'playgroundMessage' => (string) session('bumi_playground_message', ''),
            'playgroundReply' => (string) session('bumi_playground_reply', ''),
            'playgroundSource' => (string) session('bumi_playground_source', ''),
        ]);
    }

    public function updateAgent(Request $request, BumiAgent $agent): RedirectResponse
    {
        $this->guard($agent, $request);
        $this->assertCanManage();
        $this->settings->useCompany((int) $agent->company_id);
        $availableModels = $this->availableModels($agent);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'system_prompt' => ['required', 'string', 'max:20000'],
            'knowledge' => ['nullable', 'string', 'max:50000'],
            'greeting' => ['nullable', 'string', 'max:1000'],
            'fallback_reply' => ['nullable', 'string', 'max:1000'],
            'model' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]*$/', Rule::in($availableModels)],
            'enabled' => ['required', 'boolean'],
            'auto_reply' => ['required', 'boolean'],
            'create_leads' => ['required', 'boolean'],
            'max_replies_per_hour' => ['required', 'integer', 'min:1', 'max:200'],
            'default_owner_id' => ['nullable', 'integer'],
        ]);

        $ownerId = $this->ownerId($validated['default_owner_id'] ?? null, $agent, $request);

        $before = $agent->only($this->agentFields());

        $agent->forceFill([
            'name' => $validated['name'],
            'description' => ($validated['description'] ?? null) ?: null,
            'system_prompt' => $validated['system_prompt'],
            'knowledge' => ($validated['knowledge'] ?? null) ?: null,
            'greeting' => ($validated['greeting'] ?? null) ?: null,
            'fallback_reply' => ($validated['fallback_reply'] ?? null) ?: null,
            'model' => ($validated['model'] ?? null) ?: null,
            'enabled' => (bool) $validated['enabled'],
            'auto_reply' => (bool) $validated['auto_reply'],
            'create_leads' => (bool) $validated['create_leads'],
            'max_replies_per_hour' => (int) $validated['max_replies_per_hour'],
            'default_owner_id' => $ownerId,
        ])->save();

        $this->audit->logModel($request->user(), 'bumi.agent_updated', $agent, $before, $agent->fresh()->only($this->agentFields()));

        return redirect()->route('bumi.settings.agent', [$agent, 'tab' => 'settings'])
            ->with('status', __('Настройки Bumi сохранены'));
    }

    public function updateKnowledge(Request $request, BumiAgent $agent): RedirectResponse
    {
        $this->guard($agent, $request);
        $this->assertCanManage();

        $validated = $request->validate([
            'knowledge' => ['nullable', 'string', 'max:50000'],
        ]);

        $before = ['knowledge' => $agent->knowledge];
        $agent->forceFill(['knowledge' => ($validated['knowledge'] ?? null) ?: null])->save();
        $this->audit->logModel($request->user(), 'bumi.knowledge_updated', $agent, $before, ['knowledge' => $agent->knowledge]);

        return redirect()->route('bumi.settings.agent', [$agent, 'tab' => 'knowledge'])
            ->with('status', __('База знаний Bumi сохранена'));
    }

    public function storeKnowledgeItem(Request $request, BumiAgent $agent): RedirectResponse
    {
        $this->guard($agent, $request);
        $this->assertCanManage();

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'question' => ['required', 'string', 'max:2000'],
            'answer' => ['required', 'string', 'max:20000'],
        ]);

        $question = trim((string) $validated['question']);
        $item = BumiKnowledgeItem::query()->create([
            'company_id' => $agent->company_id,
            'agent_id' => $agent->id,
            'kind' => 'qa',
            'title' => trim((string) ($validated['title'] ?? '')) !== ''
                ? trim((string) $validated['title'])
                : Str::limit(html_entity_decode(strip_tags($question), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 255, ''),
            'question' => $question,
            'answer' => (string) $validated['answer'],
            'enabled' => true,
        ]);

        $this->audit->log($request->user(), 'bumi.knowledge_item_created', 'bumi_knowledge_item', $item->id, null, [
            'question' => Str::limit($question, 200),
        ]);

        return redirect()->route('bumi.settings.agent', [$agent, 'tab' => 'knowledge'])
            ->with('status', __('Пара вопрос/ответ добавлена в базу Bumi'));
    }

    public function updateKnowledgeItem(Request $request, BumiKnowledgeItem $item): RedirectResponse
    {
        $this->guard($item, $request);
        $this->assertCanManage();
        abort_if((string) $item->kind !== 'qa', 404, __('Редактируются только пары вопрос/ответ'));

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'question' => ['required', 'string', 'max:2000'],
            'answer' => ['required', 'string', 'max:20000'],
        ]);

        $question = trim((string) $validated['question']);
        $before = $item->only(['title', 'question', 'answer']);
        $item->forceFill([
            'title' => trim((string) ($validated['title'] ?? '')) !== ''
                ? trim((string) $validated['title'])
                : Str::limit(html_entity_decode(strip_tags($question), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 255, ''),
            'question' => $question,
            'answer' => (string) $validated['answer'],
        ])->save();

        $this->audit->log($request->user(), 'bumi.knowledge_item_updated', 'bumi_knowledge_item', $item->id, $before, $item->only(['title', 'question', 'answer']));

        return redirect()->route('bumi.settings.agent', [$item->agent_id, 'tab' => 'knowledge'])
            ->with('status', __('Пара вопрос/ответ обновлена'));
    }

    public function uploadKnowledgeFile(Request $request, BumiAgent $agent): RedirectResponse
    {
        $this->guard($agent, $request);
        $this->assertCanManage();

        $maxKilobytes = (int) ceil((int) config('corpms.ai.rag.max_file_bytes', 20 * 1024 * 1024) / 1024);
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'file' => [
                'required', 'file',
                'max:'.$maxKilobytes,
                'mimes:pdf,doc,docx,txt,csv,md,json,png,jpg,jpeg,tif,tiff,bmp,webp',
            ],
        ]);

        $employee = $request->user()->employee;
        abort_if($employee === null, 403, __('У пользователя нет активной компании'));
        TenantGuard::ensureEmployee($employee, $employee);

        $item = $this->knowledge->storeFile(
            $agent,
            $employee,
            $request->file('file'),
            isset($validated['title']) ? (string) $validated['title'] : null,
        );

        $this->audit->log($request->user(), 'bumi.knowledge_file_uploaded', 'bumi_knowledge_item', $item->id, null, [
            'filename' => $item->filename,
        ]);

        return redirect()->route('bumi.settings.agent', [$agent, 'tab' => 'knowledge'])
            ->with('status', __('Файл добавлен в базу знаний и передан в RAG-индекс'));
    }

    public function toggleKnowledgeItem(Request $request, BumiKnowledgeItem $item): RedirectResponse|JsonResponse
    {
        $this->guard($item, $request);
        $this->assertCanManage();

        $enabled = ! $item->enabled;
        $item->forceFill(['enabled' => $enabled])->save();
        $this->audit->log($request->user(), 'bumi.knowledge_item_toggled', 'bumi_knowledge_item', $item->id, null, ['enabled' => $enabled]);

        if ($request->expectsJson()) {
            /** @var BumiAgent $agent */
            $agent = $item->agent()->firstOrFail();
            $label = $enabled ? __('Включено') : __('Исключено');
            $class = $enabled ? 'crm-chip--green' : 'crm-chip--grey';

            return response()->json([
                'ok' => true,
                'kind' => 'knowledge',
                'id' => $item->id,
                'enabled' => $enabled,
                'label' => $label,
                'class' => $class,
                'message' => $enabled ? __('Запись включена') : __('Запись исключена из ответов'),
                'undo_url' => route('bumi.settings.knowledge.item.toggle', $item),
                'undo_enabled' => ! $enabled,
                'counts' => $this->knowledgeCounts($agent),
                'effects' => [[
                    'kind' => 'knowledge',
                    'id' => $item->id,
                    'enabled' => $enabled,
                    'label' => $label,
                    'class' => $class,
                ]],
            ]);
        }

        return back()->with('status', $enabled ? __('Запись включена') : __('Запись исключена из ответов'));
    }

    public function deleteKnowledgeItem(Request $request, BumiKnowledgeItem $item): RedirectResponse|JsonResponse
    {
        $this->guard($item, $request);
        $this->assertCanManage();

        $agentId = (int) $item->agent_id;
        $snapshot = $item->only([
            'id', 'company_id', 'agent_id', 'kind', 'title', 'question', 'answer',
            'filename', 'content', 'meta', 'enabled',
        ]);
        $token = Str::lower(Str::random(24));
        $request->session()->put('bumi.knowledge_trash.'.$token, $snapshot);
        $item->delete();

        $this->audit->log(
            $request->user(),
            'bumi.knowledge_item_deleted',
            'bumi_knowledge_item',
            (int) ($snapshot['id'] ?? 0),
            ['title' => $snapshot['title'] ?? null],
            null,
            null,
            (int) ($snapshot['company_id'] ?? 0),
        );

        if ($request->expectsJson()) {
            /** @var BumiAgent $agent */
            $agent = BumiAgent::query()->findOrFail($agentId);

            return response()->json([
                'ok' => true,
                'kind' => 'knowledge_delete',
                'id' => (int) ($snapshot['id'] ?? 0),
                'message' => __('Запись удалена из базы Bumi'),
                'undo_url' => route('bumi.settings.knowledge.item.restore', $token),
                'counts' => $this->knowledgeCounts($agent),
                'effects' => [[
                    'kind' => 'knowledge_delete',
                    'id' => (int) ($snapshot['id'] ?? 0),
                ]],
            ]);
        }

        return redirect()->route('bumi.settings.agent', [$agentId, 'tab' => 'knowledge'])
            ->with('status', __('Запись удалена из базы Bumi'));
    }

    public function restoreKnowledgeItem(Request $request, string $token): RedirectResponse|JsonResponse
    {
        $this->assertCanManage();

        /** @var array<string, mixed>|null $snapshot */
        $snapshot = $request->session()->get('bumi.knowledge_trash.'.$token);
        abort_if(! is_array($snapshot), 404, __('Запись больше недоступна для отката'));

        $companyId = $this->companyId($request);
        abort_if((int) ($snapshot['company_id'] ?? 0) !== $companyId, 403);

        $agent = BumiAgent::query()->where('company_id', $companyId)->findOrFail((int) ($snapshot['agent_id'] ?? 0));
        $itemId = (int) ($snapshot['id'] ?? 0);
        abort_if($itemId <= 0 || BumiKnowledgeItem::query()->whereKey($itemId)->exists(), 409, __('Запись уже восстановлена'));

        $restored = BumiKnowledgeItem::query()->create($snapshot);
        $request->session()->forget('bumi.knowledge_trash.'.$token);

        $this->audit->log(
            $request->user(),
            'bumi.knowledge_item_restored',
            'bumi_knowledge_item',
            $restored->id,
            null,
            ['title' => $restored->title],
            null,
            $companyId,
        );

        $message = __('Запись восстановлена в базе Bumi');
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'kind' => 'knowledge_restore',
                'id' => $restored->id,
                'message' => $message,
                'counts' => $this->knowledgeCounts($agent),
                'effects' => [[
                    'kind' => 'knowledge_restore',
                    'id' => $restored->id,
                    'title' => $restored->title,
                    'question' => $restored->plainQuestion(),
                    'answer' => $restored->plainAnswer(),
                    'enabled' => (bool) $restored->enabled,
                    'toggle_url' => route('bumi.settings.knowledge.item.toggle', $restored),
                    'delete_url' => route('bumi.settings.knowledge.item.delete', $restored),
                    'label_enabled' => __('В работе'),
                    'label_disabled' => __('Выключено'),
                    'confirm_message' => __('Удалить запись из базы знаний Bumi?'),
                ]],
            ]);
        }

        return redirect()->route('bumi.settings.agent', [$agent, 'tab' => 'knowledge'])->with('status', $message);
    }

    public function seedKnowledgePack(Request $request, BumiAgent $agent): RedirectResponse
    {
        $this->guard($agent, $request);
        $this->assertCanManage();

        $created = $this->knowledge->seedPack($agent);
        $this->audit->log($request->user(), 'bumi.knowledge_pack_seeded', 'bumi_agent', $agent->id, null, ['created' => $created]);

        return redirect()->route('bumi.settings.agent', [$agent, 'tab' => 'knowledge'])
            ->with('status', $created > 0
                ? __('Добавлено вопросов и ответов: ').$created
                : __('Готовый пакет уже добавлен — отредактируйте ответы в таблице'));
    }

    public function generatePlaygroundQuestions(Request $request, BumiAgent $agent): RedirectResponse
    {
        $this->guard($agent, $request);
        $this->settings->useCompany((int) $agent->company_id);

        $questions = $this->knowledge->generateQuestions($agent);

        return redirect()->route('bumi.settings.agent', [$agent, 'tab' => 'playground'])
            ->with('bumi_generated_questions', $questions);
    }

    public function toggleAgent(Request $request, BumiAgent $agent): RedirectResponse|JsonResponse
    {
        $this->guard($agent, $request);
        $this->assertCanManage();

        $enabled = $request->boolean('enabled');
        $before = ['enabled' => $agent->enabled];
        $agent->forceFill(['enabled' => $enabled])->save();

        $this->audit->logModel($request->user(), 'bumi.agent_toggled', $agent, $before, ['enabled' => $enabled]);

        if ($request->expectsJson()) {
            $label = $enabled ? __('Активен') : __('На паузе');
            $class = $enabled ? 'crm-chip--green' : 'crm-chip--grey';

            return response()->json([
                'ok' => true,
                'kind' => 'agent',
                'id' => $agent->id,
                'enabled' => $enabled,
                'label' => $label,
                'class' => $class,
                'message' => $enabled ? __('Агент Bumi включён') : __('Агент Bumi поставлен на паузу'),
                'undo_url' => route('bumi.settings.agent.toggle', $agent),
                'undo_enabled' => ! $enabled,
                'counts' => $this->statusCounts($agent),
                'effects' => [[
                    'kind' => 'agent',
                    'id' => $agent->id,
                    'enabled' => $enabled,
                    'label' => $label,
                    'class' => $class,
                ]],
            ]);
        }

        return redirect()->route('bumi.settings.agent', [$agent, 'tab' => 'settings'])
            ->with('status', $enabled ? __('Агент Bumi включён') : __('Агент Bumi поставлен на паузу'));
    }

    public function playground(Request $request, BumiAgent $agent): RedirectResponse|JsonResponse
    {
        $this->guard($agent, $request);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $this->settings->useCompany((int) $agent->company_id);
        $message = (string) $validated['message'];
        $generated = $this->autoReply->replyWithSource($agent, $message);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'reply' => $generated['reply'],
                'source' => $generated['source'],
                'source_label' => $generated['source_label'],
                'source_title' => $generated['source_title'],
            ]);
        }

        return redirect()->route('bumi.settings.agent', [$agent, 'tab' => 'playground'])
            ->with('bumi_playground_message', $message)
            ->with('bumi_playground_reply', $generated['reply'])
            ->with('bumi_playground_source', $generated['source_label']);
    }

    public function showChannel(Request $request, BumiChannel $channel): View
    {
        $this->guard($channel, $request);

        return view('settings.bumi.channel', [
            'channel' => $channel->load('agent'),
            'credentials' => $this->maskedCredentials($channel),
            'messages' => BumiChannelMessage::query()
                ->where('channel_id', $channel->id)
                ->with('contact')
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
            'contacts' => BumiChannelContact::query()->where('channel_id', $channel->id)->count(),
            'canManage' => $this->canManage($request),
        ]);
    }

    public function updateChannel(Request $request, BumiChannel $channel): RedirectResponse
    {
        $this->guard($channel, $request);
        $this->assertCanManage();

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'greeting' => ['nullable', 'string', 'max:1000'],
            'enabled' => ['required', 'boolean'],
            'auto_reply' => ['required', 'boolean'],
            'credentials' => ['nullable', 'array', 'max:20'],
        ];
        foreach ($channel->type->secretFields() as $field) {
            $rules['credentials.'.$field] = array_merge(['nullable', 'string', 'max:1000'], $this->credentialRules($field));
        }

        $validated = $request->validate($rules);
        $credentials = $this->mergeCredentials($channel, (array) ($validated['credentials'] ?? []));
        $enabled = (bool) $validated['enabled'];

        $channel->forceFill([
            'name' => $validated['name'],
            'greeting' => ($validated['greeting'] ?? null) ?: null,
            'enabled' => $enabled,
            'auto_reply' => (bool) $validated['auto_reply'],
            'credentials' => $credentials,
        ])->save();

        if ($enabled && ! $channel->isConfigured()) {
            // Не оставляем канал «включённым» без ключей: webhook всё равно
            // отклонит запрос, а админ увидит дополнительную ошибку в форме.
            $channel->forceFill(['enabled' => false])->save();

            return redirect()->route('bumi.settings.channel', $channel)
                ->withErrors(['enabled' => __('Заполните учётные данные канала перед включением')])
                ->withInput();
        }

        $this->audit->logModel($request->user(), 'bumi.channel_updated', $channel, null, [
            'name' => $channel->name,
            'enabled' => $channel->enabled,
            'auto_reply' => $channel->auto_reply,
            'credentials' => $this->maskedCredentials($channel),
        ]);

        return redirect()->route('bumi.settings.channel', $channel)->with('status', __('Канал Bumi сохранён'));
    }

    public function toggleChannel(Request $request, BumiChannel $channel): RedirectResponse|JsonResponse
    {
        $this->guard($channel, $request);
        $this->assertCanManage();

        $enabled = $request->boolean('enabled');
        if ($enabled && ! $channel->isConfigured()) {
            $error = __('Заполните учётные данные канала перед включением');

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $error], 422);
            }

            return back()->withErrors(['enabled' => $error]);
        }

        $wasConnected = $channel->enabled && $channel->isConfigured();
        $before = ['enabled' => $channel->enabled];
        $channel->forceFill(['enabled' => $enabled])->save();

        $this->audit->logModel($request->user(), 'bumi.channel_toggled', $channel, $before, ['enabled' => $enabled]);

        if ($request->expectsJson()) {
            $label = $channel->statusLabel();
            $class = $channel->statusClass();
            /** @var BumiAgent $agent */
            $agent = BumiAgent::query()->findOrFail($channel->agent_id);
            $counts = $this->statusCounts($agent);
            $connected = $enabled && $channel->isConfigured();

            return response()->json([
                'ok' => true,
                'kind' => 'channel',
                'id' => $channel->id,
                'enabled' => $enabled,
                'label' => $label,
                'class' => $class,
                'message' => $enabled ? __('Канал подключён') : __('Канал отключён'),
                'undo_url' => route('bumi.settings.channel.toggle', $channel),
                'undo_enabled' => ! $enabled,
                'connected' => $connected,
                'was_connected' => $wasConnected,
                'counts' => $counts,
                'effects' => [[
                    'kind' => 'channel',
                    'id' => $channel->id,
                    'enabled' => $enabled,
                    'label' => $label,
                    'class' => $class,
                    'connected' => $connected,
                ]],
            ]);
        }

        return back()->with('status', $enabled ? __('Канал подключён') : __('Канал отключён'));
    }

    public function regenerateToken(Request $request, BumiChannel $channel): RedirectResponse
    {
        $this->guard($channel, $request);
        $this->assertCanManage();

        $channel->forceFill(['public_token' => Str::lower(Str::random(32))])->save();
        $this->audit->logModel($request->user(), 'bumi.channel_token_regenerated', $channel, null, ['public_token' => '••••••']);

        return back()->with('status', __('Токен канала обновлён — обновите URL на стороне провайдера'));
    }

    public function retryMessage(Request $request, BumiChannelMessage $message): RedirectResponse|JsonResponse
    {
        $this->guard($message, $request);
        $this->assertCanManage();

        if (! $message->isFromClient()) {
            $error = __('Повторно можно отправить только входящее сообщение');

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $error], 422);
            }

            return back()->withErrors(['message' => $error]);
        }

        $result = DB::transaction(function () use ($message): array {
            /** @var BumiChannelMessage $locked */
            $locked = BumiChannelMessage::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'queued') {
                return ['already_queued' => true, 'removed_reply_ids' => []];
            }

            $replyQuery = BumiChannelMessage::query()->where('reply_to_id', $locked->id);
            $removedReplyIds = $replyQuery->pluck('id')->all();
            $replyQuery->delete();
            $locked->forceFill(['status' => 'queued'])->save();

            return ['already_queued' => false, 'removed_reply_ids' => $removedReplyIds];
        });

        if (! $result['already_queued']) {
            ProcessBumiChannelMessage::dispatch($message->id);
        }

        if ($request->expectsJson()) {
            $message->forceFill(['status' => 'queued']);

            return response()->json([
                'ok' => true,
                'already_queued' => $result['already_queued'],
                'message_id' => $message->id,
                'status' => 'queued',
                'status_label' => $message->statusLabel(),
                'status_class' => $message->statusClass(),
                'removed_reply_ids' => $result['removed_reply_ids'],
                'effects' => [['kind' => 'message', 'id' => $message->id, 'status' => 'queued']],
                'message' => __('Автоответ поставлен в очередь'),
            ]);
        }

        return back()->with('status', __('Автоответ поставлен в очередь'));
    }

    /** Публичная страница готового чата на сайте. */
    public function widget(BumiChannel $channel): View
    {
        abort_unless($channel->type === BumiChannelType::Website, 404);
        abort_unless($channel->enabled && $channel->agent?->enabled, 403, __('Чат на сайте отключён'));

        return view('settings.bumi.widget', [
            'channel' => $channel,
            'sampleQuestions' => array_map(
                static fn (array $pair): string => (string) $pair['question'],
                array_slice(BumiKnowledgeService::pack(), 0, 3),
            ),
        ]);
    }

    /** @param  array<string, mixed>  $input */
    protected function mergeCredentials(BumiChannel $channel, array $input): array
    {
        $current = $channel->credentials;
        $allowed = $channel->type->secretFields();

        foreach ($allowed as $field) {
            $value = $input[$field] ?? null;
            if (! is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                $current[$field] = $value;
            }
        }

        return $current;
    }

    /** @return array<string, string> */
    protected function credentialRules(string $field): array
    {
        return match ($field) {
            'bot_token' => ['regex:/^\d+:[A-Za-z0-9_-]+$/'],
            'webhook_secret' => ['min:8'],
            'app_secret' => ['min:16'],
            'verify_token' => ['min:8'],
            'phone_number_id', 'instance_id' => ['regex:/^\d{6,20}$/'],
            'api_token' => ['regex:/^[A-Za-z0-9]{20,}$/'],
            'access_token' => ['min:8'],
            'ig_user_id' => ['regex:/^[A-Za-z0-9_]{4,64}$/'],
            default => [],
        };
    }

    /** @return array<string, string> */
    protected function maskedCredentials(BumiChannel $channel): array
    {
        $masked = [];
        foreach ($channel->type->secretFields() as $field) {
            $value = $channel->credential($field);
            $masked[$field] = in_array($field, $channel->type->publicFields(), true)
                ? $value
                : ($value !== '' ? '••••••' : '');
        }

        return $masked;
    }

    /**
     * Модели, которые можно выбрать в UI: только настроенный список и пустой
     * вариант «по умолчанию». Свободный ввод модели запрещён.
     *
     * @return array<int, string>
     */
    protected function availableModels(BumiAgent $agent): array
    {
        $configured = $this->settings->get('corpms.ai.available_models');
        if (! is_array($configured)) {
            $configured = preg_split('/[\s,;]+/', (string) $configured, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $models = [''];
        foreach ($this->providers->availableModels() as $model) {
            $models[] = $model;
        }
        foreach ($configured as $model) {
            $models[] = trim((string) $model);
        }
        $models[] = (string) ($this->settings->get('corpms.ai.model') ?: config('corpms.ai.model', ''));
        $models[] = (string) $agent->model;

        return array_values(array_unique(array_filter($models, static function (string $model): bool {
            return $model === '' || preg_match('/^[A-Za-z0-9._:\/-]{1,120}$/', $model) === 1;
        })));
    }

    /** @return array<int, string> */
    protected function agentFields(): array
    {
        return [
            'name', 'description', 'system_prompt', 'knowledge', 'greeting', 'fallback_reply', 'model',
            'enabled', 'auto_reply', 'create_leads', 'max_replies_per_hour', 'default_owner_id',
        ];
    }

    protected function ownerId(mixed $value, BumiAgent $agent, Request $request): ?int
    {
        if (empty($value)) {
            return null;
        }

        $owner = Employee::query()->find((int) $value);
        if ($owner === null) {
            return null;
        }
        TenantGuard::ensureEmployee($owner, $request->user()->employee);

        return $owner->id;
    }

    /** @return array{active: int, connected: int} */
    protected function statusCounts(BumiAgent $agent): array
    {
        $channels = BumiChannel::query()->where('agent_id', $agent->id)->get();

        return [
            'active' => (int) BumiAgent::query()->where('company_id', $agent->company_id)->where('enabled', true)->count(),
            'connected' => $channels->filter(static fn (BumiChannel $channel): bool => $channel->enabled && $channel->isConfigured())->count(),
        ];
    }

    /** @return array{qa: int, qa_active: int, files: int} */
    protected function knowledgeCounts(BumiAgent $agent): array
    {
        $items = BumiKnowledgeItem::query()
            ->where('company_id', $agent->company_id)
            ->where('agent_id', $agent->id)
            ->get(['kind', 'enabled']);

        return [
            'qa' => $items->where('kind', 'qa')->count(),
            'qa_active' => $items->where('kind', 'qa')->where('enabled', true)->count(),
            'files' => $items->where('kind', 'file')->count(),
        ];
    }

    /** @param  iterable<BumiChannel>  $channels */
    protected function orderedChannels(iterable $channels): \Illuminate\Support\Collection
    {
        return collect($channels)
            ->sortBy(static fn (BumiChannel $channel): int => (int) array_search($channel->type->value, self::CHANNEL_ORDER, true))
            ->values();
    }

    protected function guard(object $model, Request $request): void
    {
        TenantGuard::ensure($model, $request->user()->employee);
    }

    protected function assertCanManage(): void
    {
        $user = auth()->user();
        \assert($user instanceof User);
        if (! $this->permissions->check($user, 'bumi.manage@company')) {
            abort(403, __('Недостаточно прав для настройки Bumi'));
        }
    }

    protected function canManage(Request $request): bool
    {
        return $this->permissions->check($request->user(), 'bumi.manage@company');
    }

    protected function companyId(Request $request): int
    {
        $companyId = (int) ($request->user()->employee?->company_id ?? 0);
        abort_if($companyId === 0, 403, __('У пользователя нет активной компании'));

        return $companyId;
    }
}
