<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Начальные буквы для псевдо-аватаров (без внешних сервисов изображений).
 */
final class AvatarInitials
{
    public static function of(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return '?';
        }

        $parts = preg_split('/\s+/u', $name, 4) ?: [];
        $first = $parts[0] ?? '';
        $second = $parts[1] ?? '';

        $initials = mb_strtoupper(mb_substr($first, 0, 1));

        if ($second !== '') {
            $initials .= mb_strtoupper(mb_substr($second, 0, 1));
        }

        return $initials !== '' ? $initials : '?';
    }
}
