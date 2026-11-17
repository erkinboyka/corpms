<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Ai\Services\AiAssistantService;
use App\Domains\Ai\Services\AiPanelService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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

        return view('ai.index', [
            'history' => $this->assistant->historyFor($me),
        ]);
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
            ->with('status', 'Вопрос обработан');
    }

    /**
     * JSON-сообщение для боковой AI-панели: {ok, reply, blocks}.
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
            'blocks' => array_values($payload['blocks']),
        ]);
    }
}
