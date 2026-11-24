@props([
    'href' => null,
    'photoPath' => null,
    'title' => '',
    'subtitle' => null,
    'icon' => 'photo',
    'badges' => [],
    'meta' => [],
    'footer' => null,
    'actions' => null,
    'mediaClass' => '',
    'variant' => '',
    'overlay' => null,
])
@php
    $photoUrl = !empty($photoPath) ? app(\App\Support\EntityPhotoService::class)->url($photoPath) : null;
    $initials = collect(preg_split('/\s+/u', trim((string) $title), -1, PREG_SPLIT_NO_EMPTY))->take(2)->map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
@endphp
<div class="crm-market-card {{ $variant !== '' ? 'crm-market-card--' . e($variant) : '' }} {{ $attributes->get('class') }}">
    <div class="crm-market-card__media {{ $mediaClass }}">
        @if($photoUrl)
            <img src="{{ $photoUrl }}" alt="{{ $title }}" loading="lazy">
        @else
            <div class="crm-market-card__placeholder">
                @if($initials !== '')
                    <span>{{ $initials }}</span>
                @else
                    <i class="ti ti-{{ $icon }}"></i>
                @endif
            </div>
        @endif
        @if(!empty($badges))
            <div class="crm-market-card__badge">
                @foreach($badges as $badge)
                    {!! $badge !!}
                @endforeach
            </div>
        @endif
    </div>
    <div class="crm-market-card__body">
        @if($href)
            <a href="{{ $href }}" class="crm-market-card__title text-decoration-none">{{ $title }}</a>
        @else
            <div class="crm-market-card__title">{{ $title }}</div>
        @endif
        @if($subtitle)
            <div class="crm-market-card__sub">{{ $subtitle }}</div>
        @endif
        @if(!empty($meta))
            <div class="crm-market-card__meta">
                @foreach($meta as $m)
                    <span>{!! $m !!}</span>
                @endforeach
            </div>
        @endif
    </div>
    @if($footer || $actions)
        <div class="crm-market-card__footer">
            <div class="d-flex gap-1 flex-wrap align-items-center">{!! $footer !!}</div>
            @if($actions)<div class="crm-market-card__actions">{!! $actions !!}</div>@endif
        </div>
    @endif
    @if($overlay)
        <div class="crm-market-card__overlay">{!! $overlay !!}</div>
    @endif
</div>
