<div class="bumi-settings-form">
    @if ($canManage)
    <form method="post" action="{{ route('bumi.settings.agent.update', $agent) }}" data-submit-guard>
        @csrf
        <div class="crm-form__section mb-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h3 class="mb-1">{{ __('Агент') }}</h3>
                    <div class="crm-form__desc mb-0">{{ __('Имя и описание видны администраторам; клиент видит только ответы') }}</div>
                </div>
                <span class="crm-chip crm-chip--cyan">{{ __('основные') }}</span>
            </div>
            <div class="crm-form__row mt-3">
                <div class="crm-form__field">
                    <label class="form-label" for="bumi-agent-name">{{ __('Название') }}<span class="req">*</span></label>
                    <input id="bumi-agent-name" name="name" class="form-control" value="{{ old('name', $agent->name) }}" maxlength="255" required>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="bumi-agent-model">{{ __('Модель') }}</label>
                    <select id="bumi-agent-model" name="model" class="form-select">
                        @foreach ($availableModels as $model)
                            <option value="{{ $model }}" @selected((string) old('model', $agent->model) === (string) $model)>
                                {{ $model === '' ? __('по умолчанию').' ('.$defaultModel.')' : $model }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-hint">{{ __('Модели берутся из настроенных провайдеров и AI_AVAILABLE_MODELS; свободный ввод запрещён') }}</div>
                    @error('model')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="crm-form__row">
                <div class="crm-form__field">
                    <label class="form-label" for="bumi-agent-owner">{{ __('Ответственный по умолчанию') }}</label>
                    <select id="bumi-agent-owner" name="default_owner_id" class="form-select">
                        <option value="">{{ __('не назначен') }}</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected((string) old('default_owner_id', $agent->default_owner_id) === (string) $employee->id)>{{ $employee->last_name }} {{ $employee->first_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="bumi-agent-description">{{ __('Описание') }}</label>
                    <div class="bumi-rich">
                        <textarea id="bumi-agent-description" name="description" class="form-control" rows="3"
                                  maxlength="4000" data-crm-rich data-rich-height="220">{{ old('description', $agent->description) }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="crm-form__section mb-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h3 class="mb-1">{{ __('Инструкции') }}</h3>
                    <div class="crm-form__desc mb-0">{{ __('Промпт, приветствие и ответ, если Bumi не знает ответа') }}</div>
                </div>
                <span class="crm-chip crm-chip--purple"><i class="ti ti-sparkles me-1"></i>Bumi</span>
            </div>
            <div class="crm-form__row crm-form__row--1 mt-3">
                <div class="crm-form__field">
                    <label class="form-label" for="bumi-agent-prompt">{{ __('Системный промпт') }}<span class="req">*</span></label>
                    <div class="bumi-rich">
                        <textarea id="bumi-agent-prompt" name="system_prompt" class="form-control" rows="12"
                                  maxlength="20000" data-crm-rich data-rich-height="380" required>{{ old('system_prompt', $agent->system_prompt) }}</textarea>
                    </div>
                    <div class="form-hint">{{ __('Текст промпта сохраняется с форматированием; в запрос к AI уходит чистый текст.') }}</div>
                </div>
            </div>
            <div class="crm-form__row">
                <div class="crm-form__field">
                    <label class="form-label" for="bumi-agent-greeting">{{ __('Приветствие') }}</label>
                    <textarea id="bumi-agent-greeting" name="greeting" class="form-control" rows="3" maxlength="1000">{{ old('greeting', $agent->greeting) }}</textarea>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="bumi-agent-fallback">{{ __('Ответ при неизвестном') }}</label>
                    <textarea id="bumi-agent-fallback" name="fallback_reply" class="form-control" rows="3" maxlength="1000">{{ old('fallback_reply', $agent->fallback_reply) }}</textarea>
                </div>
            </div>
        </div>

        <div class="crm-form__section mb-0">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h3 class="mb-1">{{ __('Автоответы и лиды') }}</h3>
                    <div class="crm-form__desc mb-0">{{ __('Поведение агента при входящих сообщениях') }}</div>
                </div>
            </div>
            <div class="crm-form__row mt-3">
                <div class="crm-form__field">
                    <label class="form-label" for="bumi-agent-limit">{{ __('Ответов в час одному клиенту') }}</label>
                    <input id="bumi-agent-limit" name="max_replies_per_hour" type="number" min="1" max="200" class="form-control" value="{{ old('max_replies_per_hour', $agent->max_replies_per_hour) }}">
                </div>
                <div class="crm-form__field">
                    @foreach ([
                        ['enabled', __('Агент включён')],
                        ['auto_reply', __('Отвечать автоматически')],
                        ['create_leads', __('Создавать лиды из обращений')],
                    ] as [$field, $label])
                        <div class="form-check form-switch mb-2">
                            <input type="hidden" name="{{ $field }}" value="0">
                            <input class="form-check-input" type="checkbox" name="{{ $field }}" id="bumi-agent-{{ $field }}" value="1"
                                   @checked((string) old($field, $agent->{$field} ? '1' : '0') === '1')>
                            <label class="form-check-label" for="bumi-agent-{{ $field }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="crm-form__footer crm-form__footer--card mt-2">
                <div class="text-secondary small">{{ __('Изменения пишутся в журнал действий') }}</div>
                <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>{{ __('Сохранить настройки') }}</button>
            </div>
        </div>
    </form>
    @else
        <div class="alert alert-info">{{ __('Доступ только для чтения: нет права bumi.manage') }}</div>
    @endif

    <div class="card mt-3">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="bumi-kb__stat-icon"><i class="ti ti-{{ $agent->enabled ? 'circle-check' : 'player-pause' }}"></i></span>
                <div>
                    <div class="fw-semibold">{{ __('Статус агента') }}</div>
                    <div class="text-secondary small">{{ __('Пауза остановит автоответы во всех каналах') }}</div>
                </div>
                <span class="crm-chip {{ $agent->enabled ? 'crm-chip--green' : 'crm-chip--grey' }}" data-bumi-toggle-chip>
                    {{ $agent->enabled ? __('Активен') : __('На паузе') }}
                </span>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="text-secondary small">
                    <div><i class="ti ti-plug me-1"></i>{{ __('Каналов') }}: {{ $agent->channels->count() }}</div>
                    <div><i class="ti ti-user-plus me-1"></i>{{ __('Ответственный') }}: {{ $agent->defaultOwner ? $agent->defaultOwner->last_name.' '.$agent->defaultOwner->first_name : '—' }}</div>
                </div>
                @can('checkBoxPermission', 'bumi.manage')
                <form method="post" action="{{ route('bumi.settings.agent.toggle', $agent) }}" data-bumi-toggle data-bumi-toggle-kind="agent" data-bumi-id="{{ $agent->id }}">
                    @csrf
                    <input type="hidden" name="enabled" value="{{ $agent->enabled ? '0' : '1' }}">
                    <button type="submit" class="btn {{ $agent->enabled ? 'btn-ghost-danger' : 'btn-primary' }}" data-bumi-toggle-button>
                        <i class="ti ti-{{ $agent->enabled ? 'player-pause' : 'player-play' }} me-1"></i>
                        {{ $agent->enabled ? __('Поставить на паузу') : __('Включить агента') }}
                    </button>
                </form>
                @endcan
            </div>
        </div>
    </div>
</div>
