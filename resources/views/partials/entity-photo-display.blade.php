@php
    $photoUrl = ! empty($photoPath) ? app(\App\Support\EntityPhotoService::class)->url($photoPath) : null;
    $photoClass = $photoClass ?? 'entity-photo-preview';
    $photoAlt = $photoAlt ?? __('Фото');
@endphp
@if ($photoUrl)
    <img src="{{ $photoUrl }}" alt="{{ $photoAlt }}" class="{{ $photoClass }}">
@endif
