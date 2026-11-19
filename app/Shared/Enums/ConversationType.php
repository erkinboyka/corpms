<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Тип диалога BM Chat.
 *
 * direct  — личная переписка двух сотрудников;
 * group   — закрытая группа: вход только по приглашению;
 * channel — канал компании: видимость задаёт ConversationVisibility,
 *           участники не приглашаются по одному, а определяются правом.
 */
enum ConversationType: string
{
    case Direct = 'direct';
    case Group = 'group';
    case Channel = 'channel';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Личные',
            self::Group => 'Группы',
            self::Channel => 'Каналы',
        };
    }
}
