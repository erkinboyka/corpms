<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Ai\DTO\BumiTutorReply;
use App\Domains\Audit\Services\AuditService;
use App\Domains\DashboardGen\Services\AiClient;
use App\Models\BumiAgent;
use App\Models\BumiTutorMessage;
use App\Models\Employee;
use App\Models\LmsActivity;
use App\Models\LmsLesson;
use App\Models\User;
use App\Support\SettingsService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bumi как репетитор и коуч внутри урока LMS.
 *
 * Принципы (по мотивам голосовых репетиторов вроде Preppy на gopreg.gg):
 *  - объясняет только материал урока и не выдумывает факты;
 *  - в режиме coach сначала даёт подсказку и вопрос, а не готовый ответ;
 *  - каждый ответ имеет причину эмоции и отдельную «чистую» речь для TTS;
 *  - диалог и событие сохраняются в БД, поэтому Pulse/дашборд обновляются.
 */
class BumiTutorService
{
    private const MODES = ['explain', 'hint', 'check'];

    public function __construct(
        protected AiClient $ai,
        protected SettingsService $settings,
        protected AuditService $audit,
    ) {}

    public function ask(User $user, Employee $student, LmsLesson $lesson, string $question, string $mode): BumiTutorReply
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'explain';
        $question = trim(preg_replace('/\s+/u', ' ', $question) ?? $question);
        if ($question === '') {
            $question = $mode === 'hint' ? __('Дай подсказку') : __('Объясни урок');
        }
        $question = mb_substr($question, 0, 2000);

        $this->settings->useCompany((int) $lesson->company_id);

        $history = BumiTutorMessage::query()
            ->where('company_id', $lesson->company_id)
            ->where('lesson_id', $lesson->id)
            ->where('employee_id', $student->id)
            ->latest()
            ->limit(8)
            ->get()
            ->reverse()
            ->values()
            ->all();

        $hintNumber = 0;
        foreach ($history as $message) {
            if ($message->role === 'bumi' && $message->mode === 'hint') {
                $hintNumber++;
            }
        }
        $hintNumber++;

        if (! (bool) config('corpms.bumi.tutor.enabled', true)) {
            return new BumiTutorReply(
                __('Репетитор Bumi выключен в конфигурации. Включите BUMI_TUTOR_ENABLED=true.'),
                'sleepy',
                'tutor_paused',
                $mode,
                false,
            );
        }

        $reply = $this->llmReply($student, $lesson, $question, $mode, $history, $hintNumber)
            ?? $this->localReply($lesson, $question, $mode, $hintNumber);

        DB::transaction(function () use ($user, $student, $lesson, $question, $mode, $reply): void {
            BumiTutorMessage::create([
                'company_id' => $lesson->company_id,
                'lesson_id' => $lesson->id,
                'employee_id' => $student->id,
                'role' => 'student',
                'mode' => $mode,
                'content' => $question,
            ]);
            BumiTutorMessage::create([
                'company_id' => $lesson->company_id,
                'lesson_id' => $lesson->id,
                'employee_id' => $student->id,
                'role' => 'bumi',
                'mode' => $mode,
                'content' => $reply->text,
                'mood' => $reply->mood,
                'reason' => $reply->reason,
                'tokens' => $reply->tokens,
            ]);

            LmsActivity::create([
                'company_id' => $lesson->company_id,
                'course_id' => $lesson->course_id,
                'employee_id' => $student->id,
                'type' => 'bumi_tutor',
                'label' => __('Bumi помог по уроку «:lesson»', ['lesson' => $lesson->title]),
                'meta' => [
                    'lesson_id' => (int) $lesson->id,
                    'mode' => $mode,
                    'source' => $reply->fromLlm ? 'llm' : 'local',
                    'hint_number' => $reply->hintNumber,
                ],
                'created_at' => now(),
            ]);

            $this->audit->log($user, 'bumi.tutor.asked', 'lms_lesson', (int) $lesson->id, null, [
                'mode' => $mode,
                'source' => $reply->fromLlm ? 'llm' : 'local',
                'question_length' => mb_strlen($question),
                'tokens' => $reply->tokens,
            ], null, (int) $lesson->company_id);
        });

        return $reply;
    }

    /**
     * @param  array<int, BumiTutorMessage>  $history
     */
    protected function llmReply(
        Employee $student,
        LmsLesson $lesson,
        string $question,
        string $mode,
        array $history,
        int $hintNumber,
    ): ?BumiTutorReply {
        if (! $this->ai->enabled()) {
            return null;
        }

        $model = BumiAgent::query()
            ->where('company_id', $lesson->company_id)
            ->where('enabled', true)
            ->value('model');

        $system = implode("\n", [
            'Ты — Bumi, репетитор и коуч корпоративной LMS. Отвечай по-русски, без markdown, 2–4 коротких предложения.',
            'Используй только материал урока из контекста. Если данных нет, задай уточняющий вопрос — не выдумывай.',
            (bool) config('corpms.bumi.tutor.mode', 'coach') === 'tutor'
                ? 'Режим репетитора: объясняй новое простыми словами и сразу проверяй понимание.'
                : 'Режим коуча: сначала дай подсказку или наводящий вопрос и только потом, если студент просит, полный ответ.',
            (bool) config('corpms.bumi.tutor.reveal_answer', false)
                ? 'Полный ответ разрешён после подсказок.'
                : 'Не выдавай готовый ответ, пока студент сам не сформулировал решение.',
        ]);

        $scenario = trim(strip_tags((string) ($lesson->course?->bumi_scenario ?? '')));
        if ($scenario !== '') {
            $system[] = 'Сценарий, заданный владельцем курса (обязательный): '.mb_substr($scenario, 0, 2000);
        }

        $context = $this->context($lesson);
        $dialog = '';
        foreach ($history as $message) {
            $dialog .= ($message->role === 'bumi' ? 'Bumi' : 'Студент').': '.mb_substr($message->content, 0, 500)."\n";
        }

        $instruction = match ($mode) {
            'hint' => 'Дай подсказку №'.$hintNumber.'. Не раскрывай ответ целиком.',
            'check' => 'Проверь понимание: задай один точный вопрос по материалу и объясни, почему он важен.',
            default => 'Объясни материал урока и задай один вопрос для проверки понимания.',
        };

        $prompt = implode("\n\n", [
            'Курс: '.(string) ($lesson->course?->title ?? ''),
            'Урок: '.$lesson->title,
            'Материал урока:',
            $context,
            $dialog !== '' ? 'Предыдущий диалог:'."\n".$dialog : '',
            'Студент спрашивает: '.$question,
            $instruction,
        ]);

        try {
            $result = $this->ai->chatText($prompt, $system, 500, is_string($model) ? $model : null);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($result['error'] !== null || ! is_string($result['content']) || trim($result['content']) === '') {
            return null;
        }

        $text = trim($result['content']);
        [$mood, $reason] = match ($mode) {
            'hint' => ['thinking', 'tutor_hint'],
            'check' => ['curious', 'tutor_check'],
            default => ['happy', 'tutor_explain'],
        };

        return new BumiTutorReply(
            mb_substr($text, 0, 3000),
            $mood,
            $reason,
            $mode,
            true,
            $mode === 'hint' ? $hintNumber : 0,
            (int) ($result['tokens'] ?? 0),
        );
    }

    /**
     * Детерминированный репетитор без LLM: работает даже без ключа провайдера.
     */
    protected function localReply(LmsLesson $lesson, string $question, string $mode, int $hintNumber): BumiTutorReply
    {
        $source = trim(strip_tags((string) ($lesson->summary ?: $lesson->content ?: $lesson->title)));
        $source = preg_replace('/\s+/u', ' ', $source) ?? $source;
        $sentences = preg_split('/(?<=[.!?])\s+/u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [$source];
        $key = mb_substr(trim((string) array_shift($sentences)), 0, 160);

        [$mood, $reason] = match ($mode) {
            'hint' => ['thinking', 'tutor_hint'],
            'check' => ['curious', 'tutor_check'],
            default => ['happy', 'tutor_explain'],
        };

        $text = match ($mode) {
            'hint' => $this->hintText($lesson, $key, $hintNumber),
            'check' => __('Проверь себя по уроку «:lesson»: :key Можешь объяснить это своими словами и привести пример?', [
                'lesson' => $lesson->title,
                'key' => $key,
            ]),
            default => __('По уроку «:lesson» главное: :key Давай разберём по шагам — что здесь происходит первым?', [
                'lesson' => $lesson->title,
                'key' => $key,
            ]),
        };

        // Без LLM сценарий владельца всё равно работает: Bumi держит его в
        // памяти как следующий шаг, а не просто отвечает по названию урока.
        $scenario = trim(strip_tags((string) ($lesson->course?->bumi_scenario ?? '')));
        if ($scenario !== '') {
            $text .= ' '.mb_substr($scenario, 0, 300);
        }

        return new BumiTutorReply($text, $mood, $reason, $mode, false, $mode === 'hint' ? $hintNumber : 0);
    }

    protected function hintText(LmsLesson $lesson, string $key, int $hintNumber): string
    {
        $limit = max(1, (int) config('corpms.bumi.tutor.hint_limit', 3));

        if ($hintNumber > $limit && (bool) config('corpms.bumi.tutor.reveal_answer', false)) {
            return __('Полный ответ: :key', ['key' => $key]);
        }

        return match (min($hintNumber, $limit)) {
            1 => __('Подсказка 1. Посмотри на начало урока «:lesson»: :key Что здесь главное?', [
                'lesson' => $lesson->title,
                'key' => $key,
            ]),
            2 => __('Подсказка 2. Ключевая мысль — «:key». Попробуй пересказать её одним предложением.', ['key' => $key]),
            default => __('Подсказка 3. Свяжи «:key» с заданием урока и скажи, где это пригодится на практике.', ['key' => $key]),
        };
    }

    protected function context(LmsLesson $lesson): string
    {
        $limit = max(500, (int) config('corpms.bumi.tutor.context_chars', 6000));
        $text = trim(strip_tags((string) $lesson->content));
        $summary = trim(strip_tags((string) $lesson->summary));

        $context = $summary !== '' ? __('Кратко: :summary', ['summary' => $summary])."\n" : '';
        $context .= $text;

        return mb_substr($context, 0, $limit);
    }
}
