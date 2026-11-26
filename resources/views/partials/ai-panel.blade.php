@php
    $biMode = request()->routeIs('bi.index', 'bi.dashboards.show');
    $petMood = isset($bumi) ? $bumi->mood->value : 'happy';
    $role = $bumiRole ?? null;
    $tutorLesson = $role && $role->role->value === 'tutor' && request()->routeIs('lms.lessons.show')
        ? request()->route('lesson')
        : null;
    $roleToolsJson = $role ? json_encode($role->tools, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) : '[]';
@endphp
<div class="bm-pet d-print-none" data-ai-companion data-state="idle" data-mood="{{ $petMood }}" data-crm-palette-background
     data-bumi-role="{{ $role?->role->value ?? 'assistant' }}"
     data-bumi-role-tools="{{ $roleToolsJson }}"
     data-bumi-greeting="{{ $role?->greeting() ?? '' }}"
     @if($biMode)
         data-bi-mode data-bi-generate-url="{{ route('bi.dashboards.generate') }}"
         @if(request()->routeIs('bi.dashboards.show'))
             @can('checkBoxPermission', 'bi.manage')
                 data-bi-chat-url="{{ route('bi.dashboards.chat', request()->route('dashboard')) }}"
                 data-bi-rating-url="{{ route('bi.chat.rating', ['message' => 0]) }}"
             @endcan
         @endif
     @endif>
    <button type="button" class="bm-pet__handle" data-ai-toggle aria-controls="ai-panel" aria-expanded="false"
            aria-label="{{ __("Bumi Mini. Открыть чат") }}" aria-description="{{ __("Перемещайте стрелками или перетаскивайте. Escape вернёт Bumi на место.") }}">
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
                    <g class="mini-gaze">
                        <g class="mini-expression mini-expression--happy"><use class="mini-blink" href="/assets/bm-mascot-mini.svg?v3#eyes"/></g>
                        <g class="mini-expression mini-expression--wink" fill="none" stroke="#a4edd3" stroke-width="7" stroke-linecap="round">
                            <path d="M218 155q12-16 26-2m49-4q12 9 24-1"/>
                        </g>
                        <g class="mini-expression mini-expression--thinking" fill="none" stroke="#a4edd3" stroke-width="7" stroke-linecap="round">
                            <path d="M218 154q11-11 24-3m51 0q12-9 24 2"/><circle cx="272" cy="193" r="2" fill="#a4edd3" stroke="none"/>
                        </g>
                        <g class="mini-expression mini-expression--surprised" fill="#a4edd3">
                            <ellipse cx="231" cy="155" rx="12" ry="16"/><ellipse cx="304" cy="149" rx="12" ry="16"/>
                        </g>
                        <g class="mini-expression mini-expression--sleepy" fill="none" stroke="#a4edd3" stroke-width="6" stroke-linecap="round">
                            <path d="M216 155q14 11 29 0m45-6q14 11 29 0"/>
                        </g>
                        <g class="mini-expression mini-expression--sluggish" fill="none" stroke="#a4edd3" stroke-width="6" stroke-linecap="round">
                            <path d="M219 150h25m49-5h25"/><path d="M222 165q10 5 21 0m48-5q11 5 22 0" stroke-width="4" opacity=".7"/>
                        </g>
                        <g class="mini-expression mini-expression--sad" fill="none" stroke="#a4edd3" stroke-width="6" stroke-linecap="round">
                            <path d="M219 148q13-7 26 2m48-8q13-5 26 2"/><path d="M231 165q0 10 6 15" stroke="#7fc9ff" stroke-width="5"/>
                        </g>
                        <g class="mini-expression mini-expression--angry" fill="none" stroke="#a4edd3" stroke-width="6" stroke-linecap="round">
                            <path d="M215 141l30 12m46-17 29 11" stroke="#ff9b8a" stroke-width="7"/><path d="M220 155l23 6m49-12 23-5"/>
                        </g>
                        <g class="mini-expression mini-expression--curious"><use href="/assets/bm-mascot-mini.svg?v3#eyes"/></g>
                        <g class="mini-expression mini-expression--proud" fill="none" stroke="#a4edd3" stroke-width="6" stroke-linecap="round">
                            <path d="M215 152q15-17 30 0m44-5q15-17 30 0"/>
                        </g>
                    </g>
                    <g class="mini-mouth">
                        <g class="mini-expression mini-expression--happy"><use href="/assets/bm-mascot-mini.svg?v3#smile"/></g>
                        <g class="mini-expression mini-expression--wink"><use href="/assets/bm-mascot-mini.svg?v3#smile"/></g>
                        <g class="mini-expression mini-expression--thinking"><path d="M260 192q10 3 19 0" fill="none" stroke="#a4edd3" stroke-width="4" stroke-linecap="round"/></g>
                        <g class="mini-expression mini-expression--surprised"><ellipse cx="271" cy="185" rx="8" ry="10" fill="#a4edd3"/></g>
                        <g class="mini-expression mini-expression--sleepy"><path d="M259 188q13 4 26 0" fill="none" stroke="#a4edd3" stroke-width="4" stroke-linecap="round"/></g>
                        <g class="mini-expression mini-expression--sluggish"><path d="M257 188h30" fill="none" stroke="#a4edd3" stroke-width="4" stroke-linecap="round"/></g>
                        <g class="mini-expression mini-expression--sad"><path d="M253 194q17-14 35 0" fill="none" stroke="#a4edd3" stroke-width="4" stroke-linecap="round"/></g>
                        <g class="mini-expression mini-expression--angry"><path d="M257 195q16-18 32 0" fill="none" stroke="#a4edd3" stroke-width="4" stroke-linecap="round"/></g>
                        <g class="mini-expression mini-expression--curious"><path d="M259 188q13 8 27-2" fill="none" stroke="#a4edd3" stroke-width="4" stroke-linecap="round"/></g>
                        <g class="mini-expression mini-expression--proud"><path d="M250 183q20 30 42-4l-42 4Z" fill="#a4edd3" fill-opacity=".32"/></g>
                        <g class="mini-talk"><ellipse cx="271" cy="188" rx="12" ry="8" fill="#0d3831" stroke="#a4edd3" stroke-width="3"/></g>
                    </g>
                </g></g></g>
            </g></g></g>
        </svg>
        @if($biMode)<span class="bm-pet__papers" aria-hidden="true"><i></i><i></i><i></i><i></i></span>@endif
    </button>
    <div class="bm-mini-dock">
        <button type="button" data-ai-write aria-label="{{ __("Написать Bumi") }}" aria-controls="ai-panel" aria-expanded="false"><i class="ti ti-edit" aria-hidden="true"></i></button>
        <span class="bm-mini-divider" aria-hidden="true"></span>
        <button type="button" data-mini-record aria-label="{{ __("Диктовать сообщение") }}" aria-pressed="false">@include('partials.ai-wave')</button>
    </div>
    <div class="bm-pet__reaction" data-ai-reaction role="status"></div>
    <section id="ai-panel" class="bm-mini-editor" aria-label="{{ __("Чат Bumi Mini") }}" hidden>
        <div class="ai-panel" data-mini-thread hidden>
            <header class="ai-panel__header">
                <h2 class="ai-panel__title">
                    @if($role)<i class="ti ti-{{ $role->role->icon() }} me-1" aria-hidden="true"></i>{{ $role->title() }}@else{{ 'Bumi Mini' }}@endif
                </h2>
                <span class="ai-panel__state" data-ai-pet-status role="status"></span>
                @unless (request()->routeIs('ai.index'))
                    <a class="ai-panel__icon" href="{{ route('ai.index') }}" aria-label="{{ __("История диалогов") }}"><i class="ti ti-history" aria-hidden="true"></i></a>
                @endunless
                <button type="button" class="ai-panel__icon" data-ai-close aria-label="{{ __("Свернуть чат") }}"><i class="ti ti-x" aria-hidden="true"></i></button>
            </header>
            @if($role && $role->tools !== [])
                <div class="bm-mini-tools" role="group" aria-label="{{ __('Инструменты Bumi на этой странице') }}">
                    @foreach($role->tools as $tool)
                        <button type="button" class="bm-mini-tool" data-bumi-role-tool
                                data-bumi-tool-prompt="{{ $tool['prompt'] }}"
                                @if(isset($tool['mode'])) data-bumi-tool-mode="{{ $tool['mode'] }}" @endif>
                            <i class="ti ti-{{ $tool['icon'] }}" aria-hidden="true"></i><span>{{ $tool['label'] }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
            <div class="ai-panel__messages" id="ai-messages" role="log" aria-live="polite" aria-busy="false" aria-label="{{ __("Переписка с Bumi") }}">
                <div class="ai-typing" id="ai-typing" hidden role="status" aria-label="{{ __("Ассистент печатает") }}"></div>
            </div>
        </div>
        <form class="bm-mini-compose" id="ai-form" method="post" action="{{ route('bumi.ask') }}" autocomplete="off"
              data-message-url="{{ route('bumi.ask') }}">
            @csrf
            @if($biMode)<input type="hidden" name="instruction" value="">@endif
            @if($tutorLesson instanceof \App\Models\LmsLesson)
                <input type="hidden" name="lesson_id" value="{{ $tutorLesson->id }}">
                <input type="hidden" name="bumi_mode" value="explain">
            @endif
            <button type="button" class="bm-mini-tool bm-mini-voice" data-ai-voice aria-pressed="false"
                    aria-label="{{ __('Диктовать') }}"><i class="ti ti-microphone" aria-hidden="true"></i></button>
            <button type="button" class="bm-mini-tool" data-bumi-templates aria-label="{{ __('Шаблоны') }}"><i class="ti ti-layout-grid" aria-hidden="true"></i></button>
            <textarea rows="1" id="ai-input" name="question" minlength="3" maxlength="2000" required
                      placeholder="{{ $role && $role->role->value === 'tutor' ? __('Спроси Bumi по уроку…') : ($biMode ? __('Опиши BI-дашборд: метрики, графики, период…') : __('Начать новый чат')) }}"
                      aria-label="{{ __("Сообщение для Bumi") }}" aria-description="{{ __("Enter — отправить, Shift+Enter — новая строка") }}"></textarea>
            <button type="submit" class="mini-send" id="ai-send" aria-label="{{ __("Отправить") }}" disabled><i class="ti ti-arrow-up" aria-hidden="true"></i></button>
            <span class="mini-voice-status" data-ai-voice-status role="status" hidden></span>
        </form>
    </section>
    <template data-mini-avatar>@include('partials.bumi-face', ['class' => 'ai-msg__avatar'])</template>
</div>
