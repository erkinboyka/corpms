@php
    $moodValue = (string) ($mood ?? $emotion ?? (isset($bumi) ? $bumi->mood->value : 'happy'));
    if (!in_array($moodValue, \App\Shared\Enums\BumiMood::values(), true)) {
        $moodValue = 'happy';
    }
    $moodReason = (string) ($reason ?? (isset($bumi) ? $bumi->reason : 'ready'));
    $moodLabel = \App\Shared\Enums\BumiMood::safe($moodValue)->label();
@endphp
{{-- На лице только мимика: фраза появляется отдельным репликовым пузырём по клику. --}}
<button type="button" class="bumi-face {{ $class ?? '' }}" data-bumi-face data-bumi-mood="{{ $moodValue }}"
        data-bumi-emotion="{{ $moodValue }}" data-bumi-reason="{{ $moodReason }}"
        aria-label="{{ __('Узнать, что думает Bumi') }}: {{ $moodLabel }}">
    <svg viewBox="79 31 314 242" width="40" height="32" aria-hidden="true" focusable="false">
        <use href="/assets/bm-mascot-mini.svg?v3#helmet"/>

        <g class="bumi-face__expression bumi-face__expression--happy">
            <use href="/assets/bm-mascot-mini.svg?v3#eyes"/>
            <use href="/assets/bm-mascot-mini.svg?v3#smile"/>
            <ellipse cx="207" cy="178" rx="8" ry="4" fill="#a4edd3" opacity=".22"/>
            <ellipse cx="317" cy="172" rx="8" ry="4" fill="#a4edd3" opacity=".22"/>
        </g>

        <g class="bumi-face__expression bumi-face__expression--wink" fill="none" stroke="#a4edd3" stroke-width="7" stroke-linecap="round">
            <path d="M218 155q12-16 26-2m48-5q12 9 24-1m-63 34q16 18 36-3"/>
            <path d="M324 137l7-9m-3 16 9-5" stroke-width="4"/>
        </g>

        <g class="bumi-face__expression bumi-face__expression--thinking" fill="none" stroke="#a4edd3" stroke-width="7" stroke-linecap="round">
            <path d="M217 154q11-11 24-3m51 0q12-9 24 2m-54 27 17 2"/>
            <circle cx="273" cy="194" r="2" fill="#a4edd3" stroke="none"/>
            <path d="M326 132c8-7 19-4 19 5 0 6-7 8-9 12m1 6h1" stroke-width="4"/>
        </g>

        <g class="bumi-face__expression bumi-face__expression--surprised" fill="#a4edd3">
            <ellipse cx="231" cy="155" rx="12" ry="16"/>
            <ellipse cx="304" cy="149" rx="12" ry="16"/>
            <ellipse cx="271" cy="185" rx="8" ry="10"/>
            <path d="M322 125l3-9 3 9 9 3-9 3-3 9-3-9-9-3Z" fill="#ffd477"/>
        </g>

        <g class="bumi-face__expression bumi-face__expression--sleepy" fill="none" stroke="#a4edd3" stroke-width="6" stroke-linecap="round">
            <path d="M216 155q14 11 29 0m45-6q14 11 29 0"/>
            <path d="M259 188q13 4 26 0" stroke-width="4"/>
            <path d="M329 130q7 10 14 0" stroke-width="4" stroke-dasharray="1 8"/>
        </g>

        <g class="bumi-face__expression bumi-face__expression--sluggish" fill="none" stroke="#a4edd3" stroke-width="6" stroke-linecap="round">
            <path d="M219 150h25m49-5h25"/>
            <path d="M222 165q10 5 21 0m48-5q11 5 22 0" stroke-width="4" opacity=".7"/>
            <path d="M257 188h30" stroke-width="4"/>
        </g>

        <g class="bumi-face__expression bumi-face__expression--sad" fill="none" stroke="#a4edd3" stroke-width="6" stroke-linecap="round">
            <path d="M219 148q13-7 26 2m48-8q13-5 26 2"/>
            <path d="M253 194q17-14 35 0" stroke-width="4"/>
            <path d="M231 165q0 10 6 15" stroke="#7fc9ff" stroke-width="5"/>
            <path d="M237 183q5 7 0 10-5-3 0-10Z" fill="#7fc9ff" stroke="none"/>
        </g>

        <g class="bumi-face__expression bumi-face__expression--angry" fill="none" stroke="#a4edd3" stroke-width="6" stroke-linecap="round">
            <path d="M215 141l30 12m46-17 29 11" stroke="#ff9b8a" stroke-width="7"/>
            <path d="M220 155l23 6m49-12 23-5"/>
            <path d="M257 195q16-18 32 0" stroke-width="4"/>
        </g>

        <g class="bumi-face__expression bumi-face__expression--curious" fill="none" stroke="#a4edd3" stroke-width="6" stroke-linecap="round">
            <use href="/assets/bm-mascot-mini.svg?v3#eyes" stroke="none"/>
            <path d="M215 137q13-11 29-2m45-8q13-2 26 6" stroke-width="5"/>
            <path d="M259 188q13 8 27-2" stroke-width="4"/>
            <path d="M328 129c8-7 20-4 20 5 0 6-8 8-10 12m2 6h1" stroke-width="4"/>
        </g>

        <g class="bumi-face__expression bumi-face__expression--proud" fill="none" stroke="#a4edd3" stroke-width="6" stroke-linecap="round">
            <path d="M215 152q15-17 30 0m44-5q15-17 30 0"/>
            <path d="M250 182q20 30 42-4l-42 4Z" fill="#a4edd3" fill-opacity=".32" stroke="none"/>
            <path d="M324 129l3 8 8 3-8 3-3 8-3-8-8-3 8-3Z" fill="#ffd477" stroke="none"/>
        </g>

        {{-- Рот включается только во время речи; текст живёт отдельным пузырём. --}}
        <g class="bumi-face__talk" aria-hidden="true">
            <ellipse cx="271" cy="188" rx="13" ry="9" fill="#0d3831" stroke="#a4edd3" stroke-width="3"/>
            <path d="M262 184q9-4 18 0" fill="none" stroke="#d7fff0" stroke-width="3" stroke-linecap="round"/>
        </g>
    </svg>
</button>
