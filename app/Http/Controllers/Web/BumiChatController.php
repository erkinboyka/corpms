<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Ai\Services\AiPanelService;
use App\Domains\Ai\Services\BumiTutorService;
use App\Domains\Lms\Services\LmsService;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LmsLesson;
use App\Support\AssistantMarkdown;
use App\Support\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Единая точка сообщений для Bumi Mini.
 *
 * На уроке Bumi работает как репетитор по сценарию владельца курса
 * (BumiTutorService), на остальных страницах — как обычный помощник.
 * Внешний контракт ответа совпадает с ai.message, чтобы чат не раздваивался.
 */
class BumiChatController extends Controller
{
    public function __construct(
        protected AiPanelService $panel,
        protected BumiTutorService $tutor,
        protected LmsService $lms,
        protected PermissionService $permissions,
    ) {}

    public function ask(Request $request): JsonResponse
    {
        $me = $request->user()?->employee;
        abort_unless($me instanceof Employee, 403);

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:2000'],
            'lesson_id' => ['sometimes', 'integer', 'min:1'],
            'mode' => ['sometimes', 'string', 'in:explain,hint,check'],
        ]);

        $lessonId = (int) ($validated['lesson_id'] ?? 0);
        if ($lessonId > 0) {
            abort_unless($this->permissions->check($request->user(), 'lms.view'), 403);

            /** @var LmsLesson $lesson */
            $lesson = LmsLesson::query()->findOrFail($lessonId);
            $this->lms->assertTenant($me, (int) $lesson->company_id);
            $reply = $this->tutor->ask(
                $request->user(),
                $me,
                $lesson,
                (string) $validated['question'],
                (string) ($validated['mode'] ?? 'explain'),
            );

            return $this->json(
                (string) $reply->text,
                [
                    [
                        'type' => 'actions',
                        'context' => __('Продолжить урок'),
                        'items' => [[
                            'label' => __('Открыть урок'),
                            'url' => route('lms.lessons.show', $lesson),
                            'icon' => 'ti-book',
                        ]],
                    ],
                ],
                [
                    'speech' => $reply->speech(),
                    'mood' => $reply->mood,
                    'reason' => $reply->reason,
                    'mode' => $reply->mode,
                    'hint_number' => $reply->hintNumber,
                    'source' => $reply->fromLlm ? 'llm' : 'local',
                ],
            );
        }

        abort_unless($this->permissions->check($request->user(), 'analytics.ai.use'), 403);

        $payload = $this->panel->respond($me, (string) $validated['question']);

        return $this->json(
            (string) $payload['reply'],
            array_values($payload['blocks']),
            ['source' => 'panel'],
            (bool) $payload['ok'],
        );
    }

    /** @param array<int, array<string, mixed>> $blocks @param array<string, mixed> $extra */
    private function json(string $reply, array $blocks, array $extra = [], bool $ok = true): JsonResponse
    {
        return response()->json([
            'ok' => $ok,
            'reply' => $reply,
            'reply_html' => AssistantMarkdown::render($reply),
            'blocks' => $blocks,
            'blocks_html' => view('ai.blocks', ['blocks' => $blocks, 'assignees' => []])->render(),
        ] + $extra);
    }
}
