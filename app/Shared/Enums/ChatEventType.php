<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Тип события диалога в журнале message_events.
 *
 * Журнал — источник дельт для синхронизации: клиент, потерявший
 * соединение, запрашивает события с последним известным id и догоняет
 * состояние без полной перезагрузки ленты.
 */
enum ChatEventType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Read = 'read';
    case Reaction = 'reaction';
    case MemberJoined = 'member_joined';
    case MemberLeft = 'member_left';
    case Pinned = 'pinned';

    public function label(): string
    {
        return match ($this) {
            self::Created => __('Сообщение отправлено'),
            self::Updated => __('Сообщение отредактировано'),
            self::Deleted => __('Сообщение удалено'),
            self::Read => __('Прочитано'),
            self::Reaction => __('Реакция'),
            self::MemberJoined => __('Участник добавлен'),
            self::MemberLeft => __('Участник вышел'),
            self::Pinned => __('Закреплено'),
        };
    }
}
