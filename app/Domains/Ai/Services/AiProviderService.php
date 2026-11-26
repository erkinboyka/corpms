<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Models\AiProvider;
use App\Support\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Несколько OpenAI-совместимых провайдеров, моделей и API-ключей.
 *
 * Старые `corpms.ai.*` остаются рабочим fallback-ом: если на компании нет
 * ни одной записи ai_providers, AiClient продолжает пользоваться settings.
 * Все выборки жёстко привязаны к компании из SettingsService, поэтому ключ
 * одной компании никогда не станет ключом другой.
 */
class AiProviderService
{
    public function __construct(protected SettingsService $settings) {}

    /** @return Collection<int, AiProvider> */
    public function all(): Collection
    {
        return $this->query()->orderByDesc('is_active')->orderBy('name')->get();
    }

    public function active(): ?AiProvider
    {
        return $this->query()->where('is_active', true)->orderBy('id')->first();
    }

    /** @return list<AiProvider> */
    public function providers(): array
    {
        return $this->all()->all();
    }

    /**
     * @return array{provider_id: ?int, provider_name: string, base_url: string, api_key: string, model: string, image_model: string, embedding_model: string, timeout: int}
     */
    public function credentials(?string $preferredModel = null): array
    {
        $providers = $this->all();
        $provider = null;
        $model = '';

        if ($preferredModel !== null && $this->validModel($preferredModel)) {
            foreach ($providers as $candidate) {
                if (in_array($preferredModel, $candidate->modelList(), true)) {
                    $provider = $candidate;
                    $model = $preferredModel;
                    break;
                }
            }
        }

        $provider ??= $this->active() ?? $providers->first();

        if ($provider === null) {
            return [
                'provider_id' => null,
                'provider_name' => '',
                'base_url' => rtrim((string) $this->settings->get('corpms.ai.base_url'), '/'),
                'api_key' => (string) $this->settings->get('corpms.ai.api_key'),
                'model' => (string) ($this->settings->get('corpms.ai.model') ?: 'gpt-4o-mini'),
                'image_model' => (string) $this->settings->get('corpms.ai.image_model'),
                'embedding_model' => (string) ($this->settings->get('corpms.ai.embedding_model')
                    ?: $this->settings->get('corpms.ai.embeddings_model')
                    ?: config('corpms.ai.embeddings_model', '')),
                'timeout' => max(5, (int) ($this->settings->get('corpms.ai.timeout') ?: 30)),
            ];
        }

        if ($model === '') {
            $model = (string) ($provider->default_model ?: ($provider->modelList()[0] ?? $this->settings->get('corpms.ai.model') ?: 'gpt-4o-mini'));
        }

        return [
            'provider_id' => (int) $provider->id,
            'provider_name' => (string) $provider->name,
            'base_url' => rtrim((string) $provider->base_url, '/'),
            'api_key' => (string) $provider->api_key,
            'model' => $this->validModel($model) ? $model : 'gpt-4o-mini',
            'image_model' => (string) ($provider->image_model ?: $this->settings->get('corpms.ai.image_model')),
            'embedding_model' => (string) ($provider->embedding_model ?: $this->settings->get('corpms.ai.embedding_model') ?: config('corpms.ai.embeddings_model', '')),
            'timeout' => max(5, (int) ($provider->timeout ?: $this->settings->get('corpms.ai.timeout') ?: 30)),
        ];
    }

    /**
     * Все модели, которые можно выбрать у агента Bumi: провайдеры компании,
     * общий список AI_AVAILABLE_MODELS и совместимая со старыми настройками модель.
     *
     * @return list<string>
     */
    public function availableModels(): array
    {
        $models = [];

        foreach ($this->all() as $provider) {
            foreach ($provider->modelList() as $model) {
                $models[] = $model;
            }
        }

        $configured = $this->settings->get('corpms.ai.available_models');
        if (! is_array($configured)) {
            $configured = preg_split('/[\s,;]+/', (string) $configured, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        foreach ($configured as $model) {
            $models[] = trim((string) $model);
        }
        $models[] = (string) ($this->settings->get('corpms.ai.model') ?: config('corpms.ai.model', ''));

        return array_values(array_unique(array_filter($models, fn (string $model): bool => $this->validModel($model))));
    }

    /**
     * Полная замена списка провайдеров текущей компании.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{old: array<int, array<string, mixed>>, new: array<int, array<string, mixed>>}
     */
    public function sync(array $rows, ?string $activeRef): array
    {
        return DB::transaction(function () use ($rows, $activeRef): array {
            $existing = $this->all()->keyBy('id');
            $old = $this->auditRows($existing->values()->all());
            $kept = [];
            $created = [];

            foreach ($rows as $index => $row) {
                $id = isset($row['id']) ? (int) $row['id'] : 0;
                $provider = $id > 0 ? $existing->get($id) : null;

                $models = $this->splitModels((string) ($row['models'] ?? ''));
                $default = trim((string) ($row['default_model'] ?? ''));
                if ($default === '') {
                    $default = $models[0] ?? '';
                }

                $attributes = [
                    'name' => trim((string) ($row['name'] ?? '')),
                    'base_url' => rtrim(trim((string) ($row['base_url'] ?? '')), '/'),
                    'models' => $models,
                    'default_model' => $default,
                    'image_model' => trim((string) ($row['image_model'] ?? '')),
                    'embedding_model' => trim((string) ($row['embedding_model'] ?? '')),
                    'timeout' => max(5, min(300, (int) ($row['timeout'] ?? 30))),
                    'is_active' => false,
                ];

                $apiKey = trim((string) ($row['api_key'] ?? ''));
                if ($apiKey !== '') {
                    $attributes['api_key'] = $apiKey;
                }

                if ($provider === null) {
                    $attributes['company_id'] = $this->settings->companyId();
                    $provider = AiProvider::create($attributes);
                    $created['new:'.$index] = $provider;
                } else {
                    $provider->update($attributes);
                }

                $kept[] = $provider->id;

                $ref = $activeRef ?? '';
                if ($ref === (string) $provider->id || $ref === 'new:'.$index) {
                    $provider->forceFill(['is_active' => true])->save();
                }
            }

            $removed = $existing->filter(fn (AiProvider $provider): bool => ! in_array($provider->id, $kept, true));
            foreach ($removed as $provider) {
                $provider->delete();
            }

            $final = $this->all();
            $chosen = $activeRef !== null
                ? ($created[$activeRef] ?? $final->first(fn (AiProvider $provider): bool => (string) $provider->id === $activeRef))
                : null;
            $chosen ??= $final->first(fn (AiProvider $provider): bool => $provider->is_active) ?? $final->first();

            if ($chosen !== null && ! $chosen->is_active) {
                $final->except([$chosen->id])->each(function (AiProvider $provider): void {
                    $provider->forceFill(['is_active' => false])->save();
                });
                $chosen->forceFill(['is_active' => true])->save();
            }

            return [
                'old' => $old,
                'new' => $this->auditRows($this->all()->all()),
            ];
        });
    }

    /** @return array<int, array<string, mixed>> */
    protected function auditRows(array $providers): array
    {
        return array_values(array_map(static fn (AiProvider $provider): array => [
            'id' => (int) $provider->id,
            'name' => $provider->name,
            'base_url' => $provider->base_url,
            'api_key' => $provider->api_key ? '••••••' : '',
            'models' => $provider->modelList(),
            'default_model' => $provider->default_model,
            'image_model' => $provider->image_model,
            'embedding_model' => $provider->embedding_model,
            'timeout' => (int) $provider->timeout,
            'is_active' => (bool) $provider->is_active,
        ], $providers));
    }

    /** @return list<string> */
    protected function splitModels(string $raw): array
    {
        $models = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(array_map(
            static fn (string $model): string => trim($model),
            $models,
        ), fn (string $model): bool => $this->validModel($model))));
    }

    protected function validModel(string $model): bool
    {
        return preg_match('/^[A-Za-z0-9._:\/-]{1,120}$/', $model) === 1;
    }

    /** @return Builder<AiProvider> */
    protected function query(): Builder
    {
        $companyId = $this->settings->companyId();

        return AiProvider::query()->when(
            $companyId === null,
            fn (Builder $query) => $query->whereNull('company_id'),
            fn (Builder $query) => $query->where('company_id', $companyId),
        );
    }
}
