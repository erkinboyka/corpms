<button type="button" class="bumi-face {{ $class ?? '' }}" data-bumi-face data-bumi-emotion="{{ $emotion ?? 'smile' }}"
        aria-label="{{ __("Сменить выражение лица Bumi") }}" title="{{ __("Сменить выражение лица Bumi") }}">
    <svg viewBox="79 31 314 242" width="40" height="32" aria-hidden="true" focusable="false">
        <use href="/assets/bm-mascot-mini.svg?v3#helmet"/>
        <g class="bumi-face__expression bumi-face__expression--smile">
            <use href="/assets/bm-mascot-mini.svg?v3#eyes"/>
            <use href="/assets/bm-mascot-mini.svg?v3#smile"/>
        </g>
        <g class="bumi-face__expression bumi-face__expression--wink" fill="none" stroke="#a4edd3" stroke-width="7" stroke-linecap="round">
            <path d="M218 155q12-16 26-2m48-5q12 9 24-1m-63 34q16 18 36-3"/>
        </g>
        <g class="bumi-face__expression bumi-face__expression--thinking" fill="none" stroke="#a4edd3" stroke-width="7" stroke-linecap="round">
            <path d="M217 154q11-11 24-3m51 0q12-9 24 2m-54 27 17 2"/>
            <circle cx="273" cy="194" r="2" fill="#a4edd3" stroke="none"/>
        </g>
        <g class="bumi-face__expression bumi-face__expression--surprise" fill="#a4edd3">
            <ellipse cx="231" cy="155" rx="12" ry="16"/>
            <ellipse cx="304" cy="149" rx="12" ry="16"/>
            <ellipse cx="271" cy="185" rx="8" ry="10"/>
        </g>
    </svg>
</button>
