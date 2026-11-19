<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Видимость диалога внутри компании.
 *
 * private  — видят только участники (личные переписки и закрытые группы);
 * company  — видят все активные сотрудники компании (каналы объявлений,
 *            HR, IT и т.п.). Право на отправку в такой канал проверяется
 *            отдельно от права на просмотр.
 */
enum ConversationVisibility: string
{
    case Private = 'private';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Только участники',
            self::Company => 'Вся компания',
        };
    }
}
