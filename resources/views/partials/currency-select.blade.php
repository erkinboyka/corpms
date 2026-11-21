{{--
    Селект мировых валют: код ISO 4217 + русское название.
    Использование:
        @include('partials.currency-select', [
            'id' => 'fa-currency',
            'name' => 'currency',
            'value' => old('currency', 'RUB'),
            'label' => __('Валюта'),
            'required' => false,
        ])
--}}
@php
    // 30 основных мировых валют: экзотика региона + глобальные резервные.
    $bm_currencies = [
        'USD' => __('Доллар США'),
        'EUR' => __('Евро'),
        'RUB' => __('Российский рубль'),
        'CNY' => __('Юань'),
        'GBP' => __('Фунт стерлингов'),
        'JPY' => __('Японская иена'),
        'CHF' => __('Швейцарский франк'),
        'CAD' => __('Канадский доллар'),
        'AUD' => __('Австралийский доллар'),
        'KZT' => __('Казахстанский тенге'),
        'UAH' => __('Украинская гривна'),
        'BYN' => __('Белорусский рубль'),
        'AMD' => __('Армянский драм'),
        'KGS' => __('Киргизский сом'),
        'UZS' => __('Узбекский сум'),
        'TJS' => __('Таджикский сомони'),
        'AZN' => __('Азербайджанский манат'),
        'GEL' => __('Грузинский лари'),
        'TRY' => __('Турецкая лира'),
        'AED' => __('Дирхам ОАЭ'),
        'ILS' => __('Израильский шекель'),
        'INR' => __('Индийская рупия'),
        'KRW' => __('Южнокорейская вона'),
        'SGD' => __('Сингапурский доллар'),
        'MXN' => __('Мексиканское песо'),
        'BRL' => __('Бразильский реал'),
        'PLN' => __('Польский злотый'),
        'CZK' => __('Чешская крона'),
        'SEK' => __('Шведская крона'),
        'ZAR' => __('Южноафриканский рэнд'),
    ];
    $bm_cur_value = $value ?? 'RUB';
    $bm_cur_label = $label ?? __('Валюта');
@endphp
<div class="crm-form__field">
    <label class="form-label" for="{{ $id }}">{{ $bm_cur_label }}@if (!empty($required))<span class="req">*</span>@endif</label>
    <select id="{{ $id }}" name="{{ $name }}" class="form-select" @if (!empty($required)) required @endif>
        @foreach ($bm_currencies as $code => $nameRu)
            <option value="{{ $code }}" @selected($bm_cur_value === $code)>{{ $code }} · {{ $nameRu }}</option>
        @endforeach
    </select>
</div>
