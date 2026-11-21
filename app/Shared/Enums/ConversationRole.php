<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Роль участника диалога BM Chat.
 *
 * member — обычный участник: читает и пишет;
 * admin  — управляет составом и настройками группы/канала;
 * owner  — создатель, роль администратора не отзывается у него.
 */
enum ConversationRole: string
{
    case Member = 'member';
    case Admin = 'admin';
    case Owner = 'owner';

    public function label(): string
    {
        return match ($this) {
            self::Member => __('Участник'),
            self::Admin => __('Администратор'),
            self::Owner => __('Создатель'),
        };
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin || $this === self::Owner;
    }
}
