@php
    /**
     * Хлебные крошки.
     * $bmItems — массив ['label' => ..., 'href' => ... (необяз.), 'current' => true (необяз.)].
     * Последний элемент — текущая страница (кликабельность игнорируется).
     */
    $bmItems = $bmItems ?? [];
@endphp
@if (count($bmItems) > 0)
    <nav class="bm-crumbs" aria-label="Хлебные крошки">
        @foreach ($bmItems as $bmIndex => $bmCrumb)
            @if ($bmIndex > 0)
                <i class="ti ti-chevron-right bm-crumbs__sep" aria-hidden="true"></i>
            @endif
            @if (! empty($bmCrumb['href']) && ! ($bmCrumb['current'] ?? false))
                <a class="bm-crumbs__item" href="{{ $bmCrumb['href'] }}">{{ $bmCrumb['label'] }}</a>
            @else
                <span class="bm-crumbs__item{{ ($bmCrumb['current'] ?? false) || $bmIndex === count($bmItems) - 1 ? ' bm-crumbs__item--current' : '' }}">{{ $bmCrumb['label'] }}</span>
            @endif
        @endforeach
    </nav>
@endif
