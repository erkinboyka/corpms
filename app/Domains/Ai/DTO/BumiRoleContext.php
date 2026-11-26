<?php

declare(strict_types=1);

namespace App\Domains\Ai\DTO;

use App\Shared\Enums\BumiRole;

/**
 * Контекст роли Bumi для конкретной страницы. Сервер решает, кем Bumi будет,
 * и отдаёт в UI только безопасные подсказки: роль, инструменты и краткий
 * контекст страницы. Секреты и чужие данные сюда не попадают.
 */
final class BumiRoleContext
{
    /** @param list<array{label: string, icon: string, prompt: string, mode?: string}> $tools */
    public function __construct(
        public BumiRole $role,
        public string $scope,
        public string $context,
        public string $scenario = '',
        public array $tools = [],
    ) {
        $this->tools = array_values(array_slice($tools, 0, 4));
        $this->context = mb_substr(trim(preg_replace('/\s+/u', ' ', $context) ?? $context), 0, 1500);
        $this->scenario = mb_substr(trim(preg_replace('/\s+/u', ' ', $scenario) ?? $scenario), 0, 2000);
    }

    public function title(): string
    {
        return $this->role->title();
    }

    public function greeting(): string
    {
        return $this->role->greeting();
    }

    /** Инструкция для LLM: роль и сценарий владельца курса важнее общих фраз. */
    public function instructions(): string
    {
        $lines = [
            'Ты — Bumi, корпоративный помощник. Текущая роль: '.$this->role->label().'.',
            'Работай только по контексту текущей страницы. Если данных нет — задай уточняющий вопрос, не выдумывай.',
            'Отвечай по-русски, 2–4 коротких предложения, без markdown.',
        ];

        if ($this->scenario !== '') {
            $lines[] = 'Сценарий, заданный владельцем курса (обязательный): '.$this->scenario;
        }
        if ($this->context !== '') {
            $lines[] = 'Контекст страницы: '.$this->context;
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'role' => $this->role->value,
            'label' => $this->role->label(),
            'title' => $this->title(),
            'icon' => $this->role->icon(),
            'scope' => $this->scope,
            'context' => $this->context,
            'scenario' => $this->scenario,
            'greeting' => $this->greeting(),
            'tools' => $this->tools,
        ];
    }
}
