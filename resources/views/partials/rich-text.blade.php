@php
    $value = $value ?? null;
    $class = $class ?? '';
@endphp
@if (filled($value))
    @if (\App\Support\HtmlSanitizer::looksLikeHtml((string) $value))
        <div class="crm-rich-text {{ $class }}">{!! \App\Support\HtmlSanitizer::sanitize((string) $value) !!}</div>
    @else
        <div class="crm-rich-text {{ $class }}" style="white-space: pre-wrap;">{{ $value }}</div>
    @endif
@endif
