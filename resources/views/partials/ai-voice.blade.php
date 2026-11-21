<button type="button" class="ai-panel__icon ai-panel__mic" data-ai-voice aria-label="{{ __("Диктовать сообщение") }}" aria-pressed="false" title="{{ __("Диктовать сообщение") }}">
    @if ($showWave ?? false)
        @include('partials.ai-wave')
    @else
        <i class="ti ti-microphone" aria-hidden="true"></i>
    @endif
</button>
<span class="ai-panel__voice-status" data-ai-voice-status role="status" hidden></span>
