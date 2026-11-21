<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Ai\Services\AiAssistantService;
use App\Domains\Ai\Services\AiPanelService;
use App\Domains\Ai\Services\BumiFiles;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Shared\Enums\PermissionScope;
use App\Support\AssistantMarkdown;
use App\Support\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AI-ассистент аналитики (п.68 ТЗ).
 */
class AiController extends Controller
{
    public function __construct(
        protected AiAssistantService $assistant,
        protected AiPanelService $panel,
    ) {}

    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $history = $this->assistant->historyFor($me);
        $requests = $history->flatMap(fn ($item) => collect($item->query_used['blocks'] ?? [])
            ->where('type', 'signature')->pluck('request'));
        $signatureStates = \App\Models\DocumentSignatureRequest::query()->where('signer_id', $me->id)
            ->whereIn('id', $requests)->whereHas('document', fn ($q) => $q->where('company_id', $me->company_id))
            ->get(['id', 'status'])->mapWithKeys(fn ($row) => [$row->id => $row->status->value]);
        $assignees = $this->taskAssignees($me, $history->contains(fn ($item) => collect($item->query_used['blocks'] ?? [])->contains('type', 'task_draft')));
        return view('ai.index', compact('history', 'signatureStates', 'assignees'));
    }

    public function ask(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->assistant->ask($me, $validated['question']);

        return redirect()
            ->route('ai.index')
            ->with('status', __('Вопрос обработан'));
    }

    /**
     * JSON-сообщение для боковой AI-панели: {ok, reply, reply_html, blocks}.
     * Ошибки валидации — 422 (Laravel), сбои сервиса — 200 {ok:false}, не 500.
     */
    public function message(Request $request): JsonResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $payload = $this->panel->respond($me, $validated['question']);

        return response()->json([
            'ok' => (bool) $payload['ok'],
            'reply' => (string) $payload['reply'],
            'reply_html' => AssistantMarkdown::render((string) $payload['reply']),
            'blocks' => array_values($payload['blocks']),
            'blocks_html' => view('ai.blocks', [
                'blocks' => $payload['blocks'],
                'assignees' => $this->taskAssignees($me, collect($payload['blocks'])->contains('type', 'task_draft')),
            ])->render(),
        ]);
    }

    private function taskAssignees(Employee $me, bool $needed): array
    {
        if (! $needed) {
            return [];
        }
        return app(\App\Domains\Ai\Services\BumiActions::class)->taskAssignees($me);
    }

    public function download(Request $request, string $file, BumiFiles $files): StreamedResponse
    {
        $me = $request->user()->employee;
        abort_unless($me !== null, 403);
        $disk = Storage::disk('local');
        $path = $files->directory($me).'/'.$file;
        abort_unless($disk->exists($path), 404);
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"];
        if ($extension === 'png' && $request->boolean('preview')) {
            return $disk->response($path, 'bumi.png', ['Content-Type' => 'image/png'] + $headers);
        }
        return $disk->download($path, 'bumi-'.substr($file, 0, 10).'.'.$extension, $headers);
    }
}
