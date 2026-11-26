<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Настроения Bumi.
 *
 * Значения совпадают с CSS-атрибутами data-bumi-mood / data-mood, поэтому
 * один и тот же список используется сервером, чатом, мини-ассистентом,
 * страницей входа и публичной витриной.
 */
enum BumiMood: string
{
    case Happy = 'happy';
    case Sleepy = 'sleepy';
    case Sluggish = 'sluggish';
    case Sad = 'sad';
    case Angry = 'angry';
    case Thinking = 'thinking';
    case Surprised = 'surprised';
    case Wink = 'wink';
    case Curious = 'curious';
    case Proud = 'proud';

    public function label(): string
    {
        return match ($this) {
            self::Happy => __('радостный'),
            self::Sleepy => __('сонный'),
            self::Sluggish => __('вялый'),
            self::Sad => __('грустный'),
            self::Angry => __('злой'),
            self::Thinking => __('задумчивый'),
            self::Surprised => __('удивлённый'),
            self::Wink => __('подмигивает'),
            self::Curious => __('любопытный'),
            self::Proud => __('гордый'),
        };
    }

    public static function safe(?string $value, self $default = self::Happy): self
    {
        return self::tryFrom((string) $value) ?? $default;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
