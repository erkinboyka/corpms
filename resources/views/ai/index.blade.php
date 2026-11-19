@extends('layouts.tabler')
@section('workspace_layout', 'conversation')

@section('title', 'Bumi')
@section('body_class', 'bm-assistant')

@section('content')
<div class="bm-assistant__workspace" data-ai-workspace>
    <section class="bm-assistant__conversation" aria-labelledby="conversation-heading">
        <header class="bm-assistant__thread-header">
            <div class="bm-assistant__identity">
                @include('partials.bumi-face', ['class' => 'bm-assistant__badge'])
                <div>
                    <h1 id="conversation-heading">Bumi</h1>
                    <p>Рабочий чат</p>
                </div>
            </div>
            @if ($history->isNotEmpty())
                <button type="button" class="bm-assistant__latest" data-ai-latest>
                    <i class="ti ti-arrow-down" aria-hidden="true"></i> К последнему
                </button>
            @endif
        </header>

        <div class="bm-assistant__messages" data-ai-history tabindex="0" role="region" aria-label="История диалога">
            @forelse ($history->reverse() as $item)
                <div class="ai-msg ai-msg--me">
                    <div class="ai-msg__bubble">
                        <p class="bm-assistant__answer">{{ $item->question }}</p>
                        @if ($item->created_at)
                            <time class="ai-msg__meta" datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->format('d.m.Y H:i') }}</time>
                        @endif
                    </div>
                </div>
                <div class="ai-msg ai-msg--ai">
                    @include('partials.bumi-face', ['class' => 'ai-msg__avatar', 'emotion' => $item->grounded ? 'smile' : 'thinking'])
                    <div class="ai-msg__bubble">
                        <div class="bm-assistant__answer ai-markdown">{!! \App\Support\AssistantMarkdown::render((string) $item->answer) !!}</div>
                        <div class="ai-msg__meta bm-assistant__answer-footer" data-grounded="{{ $item->grounded ? 'true' : 'false' }}">
                            <span>{{ $item->grounded ? 'По данным компании' : 'Требует проверки' }}</span>
                            <button type="button" class="bm-assistant__copy" data-ai-copy aria-label="Скопировать ответ" title="Скопировать ответ"><i class="ti ti-copy" aria-hidden="true"></i></button>
                        </div>
                    </div>
                </div>
            @empty
                <div class="bm-assistant__empty">
                    @include('partials.bumi-face', ['class' => 'bm-assistant__mascot'])
                    <h2>Чем помочь?</h2>
                    <p>Спросите о своих задачах, графике или рабочем пространстве.</p>
                    <div class="bm-assistant__prompts">
                        @foreach ([
                            ['ti-checklist', 'Мои задачи', 'Какие задачи у меня на сегодня?'],
                            ['ti-clock', 'Мой график', 'Какой у меня график на этой неделе?'],
                            ['ti-file-text', 'Документы', 'Где найти шаблоны документов?'],
                        ] as [$icon, $label, $prompt])
                            <button type="button" data-ai-prompt="{{ $prompt }}">
                                <i class="ti {{ $icon }}" aria-hidden="true"></i><span>{{ $label }}</span>
                                <i class="ti ti-arrow-up-right" aria-hidden="true"></i>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforelse
        </div>

        <div class="bm-assistant__footer">
            @error('question')<p class="bm-assistant__error" role="alert">{{ $message }}</p>@enderror
            <form method="post" action="{{ route('ai.ask') }}" class="bm-assistant__composer">
                @csrf
                @include('partials.ai-voice', ['showWave' => true])
                <label class="visually-hidden" for="bm-ai-question">Ваш вопрос ассистенту</label>
                <textarea id="bm-ai-question" name="question" rows="1" maxlength="500" minlength="3" required
                          placeholder="Спросите Bumi…" aria-describedby="bm-ai-keys">{{ old('question') }}</textarea>
                <button type="submit" class="bm-assistant__send" aria-label="Отправить вопрос">
                    <i class="ti ti-arrow-up" aria-hidden="true"></i>
                </button>
            </form>
            <p id="bm-ai-keys" class="bm-assistant__keys">Enter — отправить · Shift+Enter — новая строка</p>
            <span class="visually-hidden" role="status" data-ai-copy-status></span>
        </div>
    </section>
</div>
@endsection
