@if (isset($metrics[$key]))
    {{ number_format((float) $metrics[$key], 2, ',', ' ') }} {{ $metrics['currency'] ?? '' }}
@elseif (in_array($key, ['balance', 'income', 'expense', 'profit'], true))
    @forelse ($metrics['by_currency'] as $currency => $values)
        <div>{{ number_format((float) $values[$key], 2, ',', ' ') }} {{ $currency }}</div>
    @empty
        —
    @endforelse
@else
    <span class="fs-5 text-secondary">{{ __('Валюта не указана') }}</span>
@endif
