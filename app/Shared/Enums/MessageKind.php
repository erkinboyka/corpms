<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Служебный тип сообщения.
 *
 * text   — обычное сообщение;
 * file   — сообщение-оболочка для вложений (текст может быть пустым);
 * system — служебная запись состава/настроек: «X добавил Y»,
 *          «Сообщение отредактировано» и т.п. Пользовательский ввод
 *          в таких сообщениях запрещён.
 */
enum MessageKind: string
{
    case Text = 'text';
    case File = 'file';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Текст',
            self::File => 'Файл',
            self::System => 'Служебное',
        };
    }
}
