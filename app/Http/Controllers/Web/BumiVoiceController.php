<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Ai\Services\BumiVoiceService;
use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Support\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Throwable;

class BumiVoiceController extends Controller
{
    public function __construct(
        protected BumiVoiceService $voice,
        protected SettingsService $settings,
        protected AuditService $audit,
    ) {}

    public function transcribe(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;
        abort_if($employee === null, 403);
        $this->settings->useCompany((int) $employee->company_id);

        $validated = $request->validate([
            'audio' => [
                'required',
                'file',
                'max:'.max(1, (int) config('corpms.bumi.conversation.max_audio_bytes', 10 * 1024 * 1024) / 1024),
                'mimetypes:audio/webm,audio/ogg,audio/wav,audio/x-wav,audio/mpeg,audio/mp4,audio/x-m4a,video/webm,video/mp4',
            ],
            'lang' => ['sometimes', 'string', 'in:ru,en'],
        ]);

        try {
            $text = $this->voice->transcribe($validated['audio'], $validated['lang'] ?? 'ru');
        } catch (RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'fallback' => (bool) config('corpms.bumi.conversation.enabled', false)
                    && (string) config('corpms.bumi.conversation.stt', 'server') === 'server',
            ], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'error' => __('Диктовка временно недоступна.')], 502);
        }

        $this->audit->log($request->user(), 'bumi.voice.transcribed', 'employee', (int) $employee->id, null, [
            'lang' => $validated['lang'] ?? 'ru',
            'bytes' => $validated['audio']->getSize(),
            'text_length' => mb_strlen($text),
        ], null, (int) $employee->company_id);

        return response()->json(['ok' => true, 'text' => $text]);
    }

    public function synthesize(Request $request): Response|JsonResponse
    {
        $employee = $request->user()?->employee;
        abort_if($employee === null, 403);
        $this->settings->useCompany((int) $employee->company_id);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:1000'],
            'voice' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        try {
            $audio = $this->voice->synthesize($validated['text'], $validated['voice'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'error' => __('Озвучка временно недоступна.')], 502);
        }

        $this->audit->log($request->user(), 'bumi.voice.synthesized', 'employee', (int) $employee->id, null, [
            'text_length' => mb_strlen((string) $validated['text']),
        ], null, (int) $employee->company_id);

        return response($audio['bytes'], 200, [
            'Content-Type' => $audio['mime'],
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
