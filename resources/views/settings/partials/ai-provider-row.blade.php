@php
    $isTemplate = ($template ?? false) === true;
    $provider = $provider ?? null;
    $suffix = $isTemplate ? '__INDEX__' : (string) $index;
    $name = $provider?->name ?? '';
    $baseUrl = $provider?->base_url ?? '';
    $models = $provider ? implode(', ', $provider->modelList()) : '';
    $defaultModel = $provider?->default_model ?? '';
    $imageModel = $provider?->image_model ?? '';
    $embeddingModel = $provider?->embedding_model ?? '';
    $timeout = $provider?->timeout ?? 30;
    $active = $provider && ((int) ($activeId ?? 0) === (int) $provider->id);
@endphp
<div class="ai-provider-row" data-ai-provider-row @if($isTemplate) hidden data-ai-provider-template @endif>
    @if($provider)
        <input type="hidden" name="ai_providers[{{ $suffix }}][id]" value="{{ $provider->id }}">
    @endif
    <div class="ai-provider-row__head">
        <label class="form-check form-check-inline m-0">
            <input class="form-check-input" type="radio" name="ai_active_provider"
                   value="{{ $provider ? $provider->id : 'new:'.$suffix }}" @checked($active) @disabled(!$canManage)>
            <span class="form-check-label">{{ __('активный') }}</span>
        </label>
        @if($canManage)
            <button type="button" class="btn btn-ghost-danger btn-sm" data-ai-provider-remove
                    aria-label="{{ __('Удалить провайдера') }}"><i class="ti ti-trash"></i></button>
        @endif
    </div>
    <div class="row g-2">
        <div class="col-md-4">
            <label class="form-label mb-1">{{ __('Название') }}</label>
            <input type="text" class="form-control" name="ai_providers[{{ $suffix }}][name]" value="{{ $name }}"
                   placeholder="Groq / OpenRouter" @disabled(!$canManage)>
        </div>
        <div class="col-md-8">
            <label class="form-label mb-1">{{ __('Адрес API') }}</label>
            <input type="url" class="form-control" name="ai_providers[{{ $suffix }}][base_url]" value="{{ $baseUrl }}"
                   placeholder="https://api.groq.com/openai/v1" @disabled(!$canManage)>
        </div>
        <div class="col-md-6">
            <label class="form-label mb-1">{{ __('API-ключ') }}</label>
            <input type="password" class="form-control" name="ai_providers[{{ $suffix }}][api_key]" value=""
                   autocomplete="new-password"
                   placeholder="{{ $provider ? __('Ключ сохранён — оставьте пустым') : __('Вставьте ключ') }}"
                   @disabled(!$canManage)>
        </div>
        <div class="col-md-6">
            <label class="form-label mb-1">{{ __('Модели (через запятую)') }}</label>
            <input type="text" class="form-control" name="ai_providers[{{ $suffix }}][models]" value="{{ $models }}"
                   placeholder="openai/gpt-oss-120b, llama-3.3-70b" @disabled(!$canManage)>
        </div>
        <div class="col-md-4">
            <label class="form-label mb-1">{{ __('Модель по умолчанию') }}</label>
            <input type="text" class="form-control" name="ai_providers[{{ $suffix }}][default_model]" value="{{ $defaultModel }}"
                   placeholder="openai/gpt-oss-120b" @disabled(!$canManage)>
        </div>
        <div class="col-md-4">
            <label class="form-label mb-1">{{ __('Модель изображений') }}</label>
            <input type="text" class="form-control" name="ai_providers[{{ $suffix }}][image_model]" value="{{ $imageModel }}"
                   placeholder="необязательно" @disabled(!$canManage)>
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1">{{ __('Embeddings') }}</label>
            <input type="text" class="form-control" name="ai_providers[{{ $suffix }}][embedding_model]" value="{{ $embeddingModel }}"
                   placeholder="необязательно" @disabled(!$canManage)>
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1">{{ __('Таймаут, сек') }}</label>
            <input type="number" class="form-control" min="5" max="300" name="ai_providers[{{ $suffix }}][timeout]"
                   value="{{ $timeout }}" @disabled(!$canManage)>
        </div>
    </div>
</div>
