@php
    $questions = array_values(array_unique(array_merge($generatedQuestions, $sampleQuestions)));
@endphp

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h3 class="card-title mb-0">{{ __('Тест ответов Bumi') }}</h3>
                    <div class="text-secondary small">{{ __('Без лида и без записи в историю — проверка ответа по вашей базе знаний.') }}</div>
                </div>
                @if ($canManage)
                <form method="post" action="{{ route('bumi.settings.playground.generate', $agent) }}">
                    @csrf
                    <button class="btn btn-sm btn-ghost-secondary" type="submit">
                        <i class="ti ti-sparkles me-1"></i>{{ __('Сгенерировать вопросы') }}
                    </button>
                </form>
                @endif
            </div>
            <div class="card-body">
                @if ($questions !== [])
                    <div class="small text-secondary mb-2">{{ __('Нажмите на вопрос — он подставится в поле') }}</div>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        @foreach ($questions as $question)
                            <button type="button" class="btn btn-sm btn-ghost-secondary" data-bumi-question="@e($question)">
                                <i class="ti ti-message-circle me-1"></i>{{ $question }}
                            </button>
                        @endforeach
                    </div>
                @endif

                @if ($canManage)
                <form method="post" action="{{ route('bumi.settings.agent.playground', $agent) }}" data-submit-guard data-bumi-playground-form>
                    @csrf
                    <label class="form-label" for="bumi-playground-message">{{ __('Сообщение клиента') }}</label>
                    <div class="bumi-rich">
                        <textarea id="bumi-playground-message" name="message" class="form-control" rows="6" maxlength="4000"
                                  data-crm-rich data-rich-height="260" required>{{ $playgroundMessage }}</textarea>
                    </div>
                    @error('message')<div class="text-danger small">{{ $message }}</div>@enderror
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">
                        <div class="text-secondary small">{{ __(' corpms.ai + RAG по базе знаний; при недоступности — локальный ответ') }}</div>
                        <button type="submit" class="btn btn-primary"><i class="ti ti-send me-1"></i>{{ __('Проверить') }}</button>
                    </div>
                </form>
                @else
                    <div class="alert alert-info mb-0">{{ __('Доступ только для чтения: нет права bumi.manage') }}</div>
                @endif
            </div>
        </div>

        <div id="bumi-playground-thread" aria-live="polite">
        @if ($playgroundReply !== '')
            <div class="card">
                <div class="card-header"><h3 class="card-title">{{ __('Ответ Bumi') }}</h3></div>
                <div class="card-body d-flex flex-column gap-3">
                    @if ($playgroundMessage !== '')
                        <div class="d-flex gap-3" data-bumi-playground-message>
                            <span class="avatar avatar-sm"><i class="ti ti-user"></i></span>
                            <div>
                                <div class="small text-secondary">{{ __('Клиент') }}</div>
                                <div class="text-break">@include('partials.rich-text', ['value' => $playgroundMessage])</div>
                            </div>
                        </div>
                    @endif
                    <div class="d-flex gap-3" data-bumi-playground-reply>
                        <span class="avatar avatar-sm"><i class="ti ti-robot"></i></span>
                        <div>
                            <div class="small text-secondary d-flex flex-wrap align-items-center gap-2">
                                <span>Bumi</span>
                                @if ($playgroundSource !== '')
                                    <span class="crm-chip crm-chip--grey" data-bumi-playground-source>{{ $playgroundSource }}</span>
                                @endif
                            </div>
                            <div class="text-break">@include('partials.rich-text', ['value' => $playgroundReply])</div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
        </div>
    </div>

    <div class="col-xl-4">
        <div class="crm-form__section">
            <h3>{{ __('Готовый пакет Bumi') }}</h3>
            <div class="crm-form__desc">{{ __('Проверьте базовые сценарии перед подключением канала.') }}</div>
            <div class="crm-timeline mt-3">
                <div class="crm-timeline__item">
                    <div class="crm-timeline__time">1</div>
                    <div class="crm-timeline__title">{{ __('Приветствие') }}</div>
                    <div class="crm-timeline__text">{{ __('Bumi поздоровается и представится') }}</div>
                </div>
                <div class="crm-timeline__item">
                    <div class="crm-timeline__time">2</div>
                    <div class="crm-timeline__title">{{ __('База знаний') }}</div>
                    <div class="crm-timeline__text">{{ __('Ответ ищется в парах вопрос/ответ и в проиндексированных документах') }}</div>
                </div>
                <div class="crm-timeline__item">
                    <div class="crm-timeline__time">3</div>
                    <div class="crm-timeline__title">{{ __('Неизвестный вопрос') }}</div>
                    <div class="crm-timeline__text">{{ __('Агент задаст уточняющий вопрос или предложит сотрудника') }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
