<?php

declare(strict_types=1);

namespace App\Domains\DashboardGen\Services;

use Illuminate\Support\Facades\Log;

/**
 * Сборка спецификации одного виджета: генерация SQL → валидация → ремонт
 * (перенос WidgetSpecGenerator из Datavue, SQL-путь без builder-режима).
 */
class WidgetSpecGenerator
{
    public function __construct(
        private AiClient $ai,
        private string $driver,
        /** @var array<string, array<string, array{type: string, samples?: list<string>}>> */
        private array $schema,
        private ?int $companyId = null,
        /** @var array<string, array<string, array{type: string, samples?: list<string>}>> */
        private array $validationSchema = [],
    ) {
    }

    /**
     * @return array{ok: bool, spec: ?array, error: ?string, attempts: int, tokens: int}
     */
    public function generate(string $instruction, string $family, ?string $type): array
    {
        $maxAttempts = max(1, (int) config('dashboard_gen.max_attempts', 3));

        $queryAi = new WidgetQueryAi($this->ai, $this->driver);
        $validationSchema = $this->validationSchema !== [] ? $this->validationSchema : $this->schema;

        $validator = new WidgetSpecValidator(
            new WidgetQueryRunner(companyId: $this->companyId),
            $validationSchema,
            array_keys($validationSchema),
        );

        $spec = null;
        $error = null;
        $tokens = 0;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $isRepair = $error !== null && $spec !== null;

            $answer = $isRepair
                ? $queryAi->repair($instruction, $family, $type, $this->schema, $spec, $error)
                : $queryAi->generate($instruction, $family, $type, $this->schema);

            $tokens += $answer['tokens'];

            if ($answer['spec'] === null) {
                $error = $answer['error'] ?? 'Модель не вернула запрос.';

                Log::info('WidgetSpecGenerator: модель не вернула спецификацию', [
                    'family' => $family,
                    'attempt' => $attempt,
                    'error' => $error,
                ]);

                continue;
            }

            $spec = $answer['spec'];
            $spec['shape'] = WidgetShapeMapper::shapeFor($family);

            $check = $validator->validate($spec, $family, $type);

            if ($check['ok']) {
                return [
                    'ok' => true,
                    'spec' => $spec,
                    'error' => null,
                    'attempts' => $attempt,
                    'tokens' => $tokens,
                ];
            }

            $error = $check['errors'][0] ?? 'Запрос не прошёл проверку.';

            Log::info('WidgetSpecGenerator: запрос не прошёл проверку', [
                'family' => $family,
                'attempt' => $attempt,
                'error' => $error,
            ]);
        }

        return [
            'ok' => false,
            'spec' => $spec,
            'error' => $error ?? 'Не удалось построить запрос.',
            'attempts' => $maxAttempts,
            'tokens' => $tokens,
        ];
    }
}
