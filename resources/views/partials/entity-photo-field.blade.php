@php
    $photoUrl = ! empty($photoPath) ? app(\App\Support\EntityPhotoService::class)->url($photoPath) : null;
@endphp
<div class="mb-3">
    <label class="form-label" for="{{ $id ?? 'entity-photo' }}">{{ __('Фото') }}</label>
    @if ($photoUrl)
        <div class="mb-2"><img src="{{ $photoUrl }}" alt="{{ __('Фото') }}" class="entity-photo-preview"></div>
        <label class="form-check d-inline-flex align-items-center gap-2 mb-2">
            <input type="checkbox" name="reset_photo" value="1" class="form-check-input">
            <span class="form-check-label">{{ __('Удалить фото') }}</span>
        </label>
    @endif
    <input type="file" id="{{ $id ?? 'entity-photo' }}" name="photo" class="form-control"
           accept="image/jpeg,image/png,image/webp">
    <div class="form-text">{{ __('JPEG, PNG или WebP, до 5 МБ') }}</div>
</div>
