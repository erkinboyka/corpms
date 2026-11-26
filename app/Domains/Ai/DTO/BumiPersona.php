<?php

declare(strict_types=1);

namespace App\Domains\Ai\DTO;

use App\Shared\Enums\BumiMood;

/**
 * Состояние персонажа, которое сервер отдаёт в Blade.
 *
 * Причина обязательна: настроение Bumi всегда привязано к событию
 * (ошибка валидации, успешное сохранение, поздний час, пустой чат и т.д.),
 * а не выбирается случайным таймером.
 */
final class BumiPersona
{
    public function __construct(
        public readonly BumiMood $mood,
        public readonly string $line,
        public readonly string $reason,
    ) {
    }

    public static function make(BumiMood $mood, string $reason, string $line): self
    {
        return new self($mood, $line, $reason);
    }

    /** @return array{mood: string, line: string, reason: string} */
    public function toArray(): array
    {
        return [
            'mood' => $this->mood->value,
            'line' => $this->line,
            'reason' => $this->reason,
        ];
    }
}
