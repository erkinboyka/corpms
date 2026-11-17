<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum CrmDealStage: string
{
    case New = 'new';
    case Qualification = 'qualification';
    case Proposal = 'proposal';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';

    /** Сделка закрыта (успешно или проиграна) — попадёт в closed_at. */
    public function isClosed(): bool
    {
        return $this === self::Won || $this === self::Lost;
    }

    /** Вероятность по умолчанию при переходе в стадию. */
    public function defaultProbability(): int
    {
        return match ($this) {
            self::New => 10,
            self::Qualification => 25,
            self::Proposal => 50,
            self::Negotiation => 75,
            self::Won => 100,
            self::Lost => 0,
        };
    }

    /** @return array<int, self> */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $stage): bool => $stage->isClosed() === false));
    }
}
