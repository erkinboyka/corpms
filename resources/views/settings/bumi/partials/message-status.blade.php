@php
    /** @var \App\Models\BumiChannelMessage $message */
@endphp
<span class="crm-chip {{ $message->statusClass() }}" data-bumi-status="{{ $message->status }}">{{ $message->statusLabel() }}</span>
