<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Алгоритм компонента: как из данных периода получается сумма строки.
 * Детерминированные правила — ИИ в расчётах не участвует.
 */
enum PayrollCalculation: string
{
    case Fixed = 'fixed';       // фиксированная сумма из оклада сотрудника
    case Hourly = 'hourly';     // отработанные часы × ставка
    case Overtime = 'overtime'; // часы переработки × ставка
    case Percent = 'percent';   // процент от оклада
    case Manual = 'manual';     // сумма задаётся ставкой компонента (ручная)
}
