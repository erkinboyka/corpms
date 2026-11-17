<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Вид номенклатуры: товар хранится на складе, услуга — нет.
 */
enum ProductKind: string
{
    case Goods = 'goods';
    case Service = 'service';

    public function isStocked(): bool
    {
        return $this === self::Goods;
    }
}
