{{--
    Единое поле «Телефон»: страна через select2 (поиск, группы, тёмная тема)
    + ввод номера. Эмодзи-флаги не используются (на Windows не рендерятся) —
    в списке только полное название и код.

    Параметры @include:
      $id       — id инпута (и for у label);
      $name     — name инпута;
      $value    — текущее значение (по умолчанию из old($name));
      $label    — подпись («Телефон»);
      $hint     — текст под полем (form-text), null;
      $required — обязательное поле;
      $timezone — часовой пояс для выбора страны по умолчанию (company и т.п.).

    Разметку ищет corpms.js:
      - select[data-crm-phone-country] — данные маски (options с data-dial,
        value=ISO); инициализируется select2 (SELECT2_TARGETS) с поиском;
      - [data-crm-phone-group] — контейнер; data-crm-tz-map — JSON
        «часовой пояс => ISO» для авто-выбора страны при смене пояса
        в формах компаний.
--}}
@php
    $id = $id ?? ('phone-' . preg_replace('/\W+/', '-', (string) $name));
    $value = $value ?? old($name);
    $label = $label ?? __('Телефон');
    $hint = $hint ?? null;
    $required = $required ?? false;
    $timezone = $timezone ?? null;
    $countries = \App\Support\PhoneHelper::countries();
    $initialIso = \App\Support\PhoneHelper::guess((string) ($value ?? ''), $timezone);
    $tzMap = \App\Support\PhoneHelper::dialByTimezone();
    $initialDial = \App\Support\PhoneHelper::dialOf($initialIso);
    $placeholder = $initialDial === '+7' ? '+7 (999) 123-45-67' : $initialDial . ' …';
@endphp
<div class="crm-form__field">
    <label class="form-label" for="{{ $id }}">{{ $label }}@if ($required)<span class="req">*</span>@endif</label>
    <div class="input-group" data-crm-phone-group data-crm-tz-map='@json($tzMap)'>
        <div class="crm-cc">
            <select class="form-select" data-crm-phone-country aria-label="{{ __("Код страны") }}">
                @foreach ($countries as $country)
                    @if ($country['group'] !== '' && ($loop->first || $countries[$loop->index - 1]['group'] !== $country['group']))
                        <optgroup label="{{ $country['group'] }}">
                    @endif
                    <option value="{{ $country['iso'] }}" data-dial="{{ $country['dial'] }}"
                            @selected($country['iso'] === $initialIso)>{{ $country['name'] }} {{ $country['dial'] }}</option>
                    @if ($country['group'] !== '' && ($loop->last || $countries[$loop->index + 1]['group'] !== $country['group']))
                        </optgroup>
                    @endif
                @endforeach
            </select>
        </div>
        <input type="tel" id="{{ $id }}" name="{{ $name }}" class="form-control" data-crm-phone
               value="{{ $value }}" autocomplete="tel" inputmode="tel" placeholder="{{ $placeholder }}"
               @if ($required) required @endif>
    </div>
    @if (! empty($hint))
        <div class="form-text">{{ $hint }}</div>
    @endif
</div>
