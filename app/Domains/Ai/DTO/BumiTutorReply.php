<?php

declare(strict_types=1);

namespace App\Domains\Ai\DTO;

/**
 * Ответ Bumi-репетитора: текст для экрана, отдельная речь для TTS и причина
 * эмоции. Речь всегда чище текста (без markdown и служебных пометок).
 */
final readonly class BumiTutorReply
{
    public function __construct(
        public string $text,
        public string $mood,
        public string $reason,
        public string $mode,
        public bool $fromLlm,
        public int $hintNumber = 0,
        public int $tokens = 0,
    ) {}

    public function speech(): string
    {
        $text = preg_replace('/[#*`>_\[\]]/', '', $this->text) ?? $this->text;
        $text = preg_replace('/\s*[\r\n]+\s*/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'answer' => $this->text,
            'speech' => $this->speech(),
            'mood' => $this->mood,
            'reason' => $this->reason,
            'mode' => $this->mode,
            'hint_number' => $this->hintNumber,
            'source' => $this->fromLlm ? 'llm' : 'local',
        ];
    }
}
