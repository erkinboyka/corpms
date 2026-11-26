<?php

declare(strict_types=1);

namespace App\Domains\DashboardGen\Services;

use App\Models\BiDashboard;

/**
 * Контекст инструментального вызова Bumi внутри BI-дашборда.
 *
 * Дашборд фиксируется маршрутом и не принимается от модели в аргументах —
 * это убирает возможность подменить ID чужого дашборда. Сюда же инструменты
 * пишут undo-снимки: сервис чата сохраняет их в bi_chat_messages.payload.
 */
final class BiToolContext
{
    /** @var list<array<string, mixed>> */
    public array $undo = [];

    public function __construct(public readonly BiDashboard $dashboard) {}

    /** @param array<string, mixed> $snapshot */
    public function capture(array $snapshot): void
    {
        $this->undo[] = $snapshot;
    }
}
