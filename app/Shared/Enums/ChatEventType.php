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
            self::Created => 'Сообщение отправлено',
            self::Updated => 'Сообщение отредактировано',
            self::Deleted => 'Сообщение удалено',
            self::Read => 'Прочитано',
            self::Reaction => 'Реакция',
            self::MemberJoined => 'Участник добавлен',
            self::MemberLeft => 'Участник вышел',
            self::Pinned => 'Закреплено',
        };
    }
}
