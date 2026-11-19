<div class="bm-pet d-print-none" data-ai-companion data-state="idle">
    <button type="button" class="bm-pet__handle" data-ai-toggle aria-controls="ai-panel" aria-expanded="false"
            aria-label="Bumi Mini. Открыть чат" aria-description="Перемещайте стрелками или перетаскивайте. Escape вернёт Bumi на место.">
        <svg class="bm-mini-actor" viewBox="53 9 368 395" aria-hidden="true" focusable="false">
            <ellipse class="mini-shadow" cx="236" cy="382" rx="81" ry="8" fill="#162323" opacity=".16"/>
            <g class="mini-jump"><g class="mini-facing"><g class="mini-stride">
                <use class="mini-foot mini-left" href="/assets/bm-mascot-mini.svg?v3#boot-l"/>
                <use class="mini-foot mini-right" href="/assets/bm-mascot-mini.svg?v3#boot-r"/>
                <use class="mini-arm mini-right" href="/assets/bm-mascot-mini.svg?v3#far-arm"/>
                <use href="/assets/bm-mascot-mini.svg?v3#torso"/>
                <use class="mini-arm mini-left" href="/assets/bm-mascot-mini.svg?v3#near-arm"/>
                <g class="mini-head"><g class="mini-idle">
                    <use href="/assets/bm-mascot-mini.svg?v3#helmet"/>
                    <g class="mini-gaze"><use class="mini-blink" href="/assets/bm-mascot-mini.svg?v3#eyes"/></g>
                    <use href="/assets/bm-mascot-mini.svg?v3#smile"/>
                </g></g>
            </g></g></g>
        </svg>
    </button>
    <div class="bm-mini-dock">
        <button type="button" data-ai-write aria-label="Написать Bumi" aria-controls="ai-panel" aria-expanded="false"><i class="ti ti-edit" aria-hidden="true"></i></button>
        <span class="bm-mini-divider" aria-hidden="true"></span>
        <button type="button" data-mini-record aria-label="Диктовать сообщение" aria-pressed="false">@include('partials.ai-wave')</button>
    </div>
    <div class="bm-pet__reaction" data-ai-reaction role="status"></div>
    <section id="ai-panel" class="bm-mini-editor" aria-label="Чат Bumi Mini" hidden>
        <div class="ai-panel" data-mini-thread hidden>
            <header class="ai-panel__header">
                <h2 class="ai-panel__title">Bumi Mini</h2>
                <span class="ai-panel__state" data-ai-pet-status role="status"></span>
                @unless (request()->routeIs('ai.index'))
                    <a class="ai-panel__icon" href="{{ route('ai.index') }}" aria-label="История диалогов"><i class="ti ti-history" aria-hidden="true"></i></a>
                @endunless
                <button type="button" class="ai-panel__icon" data-ai-close aria-label="Свернуть чат"><i class="ti ti-x" aria-hidden="true"></i></button>
            </header>
            <div class="ai-panel__messages" id="ai-messages" role="log" aria-live="polite" aria-busy="false" aria-label="Переписка с Bumi">
                <div class="ai-typing" id="ai-typing" hidden role="status" aria-label="Ассистент печатает"></div>
            </div>
        </div>
        <form class="bm-mini-compose" id="ai-form" method="post" action="{{ route('ai.message') }}" autocomplete="off">
            @csrf
            <button type="button" class="mini-plus" data-mini-actions aria-expanded="false" aria-controls="mini-actions" aria-label="Действия"><i class="ti ti-plus" aria-hidden="true"></i></button>
            <div id="mini-actions" class="mini-actions" hidden>
                <button type="button" data-ai-voice aria-pressed="false"><i class="ti ti-microphone" aria-hidden="true"></i> <span data-mini-voice-label>Диктовать</span></button>
                <button type="button" data-ai-example><i class="ti ti-list-check" aria-hidden="true"></i> Задачи на сегодня</button>
                <button type="button" data-mini-fold><i class="ti ti-chevron-up" aria-hidden="true"></i> Свернуть</button>
            </div>
            <textarea rows="1" id="ai-input" name="question" minlength="3" maxlength="500" required placeholder="Начать новый чат" aria-label="Сообщение для Bumi" aria-description="Enter — отправить, Shift+Enter — новая строка"></textarea>
            <button type="submit" class="mini-send" id="ai-send" aria-label="Отправить" disabled><i class="ti ti-arrow-up" aria-hidden="true"></i></button>
            <span class="mini-voice-status" data-ai-voice-status role="status" hidden></span>
        </form>
    </section>
    <template data-mini-avatar>@include('partials.bumi-face', ['class' => 'ai-msg__avatar'])</template>
</div>
