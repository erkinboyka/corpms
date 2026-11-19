{{--
    Селект мировых валют: код ISO 4217 + русское название.
    Использование:
        @include('partials.currency-select', [
            'id' => 'fa-currency',
            'name' => 'currency',
            'value' => old('currency', 'RUB'),
            'label' => 'Валюта',
            'required' => false,
        ])
--}}
@php
    // 30 основных мировых валют: экзотика региона + глобальные резервные.
    $bm_currencies = [
        'USD' => 'Доллар США',
        'EUR' => 'Евро',
        'RUB' => 'Российский рубль',
        'CNY' => 'Юань',
        'GBP' => 'Фунт стерлингов',
        'JPY' => 'Японская иена',
        'CHF' => 'Швейцарский франк',
        'CAD' => 'Канадский доллар',
        'AUD' => 'Австралийский доллар',
        'KZT' => 'Казахстанский тенге',
        'UAH' => 'Украинская гривна',
        'BYN' => 'Белорусский рубль',
        'AMD' => 'Армянский драм',
        'KGS' => 'Киргизский сом',
        'UZS' => 'Узбекский сум',
        'TJS' => 'Таджикский сомони',
        'AZN' => 'Азербайджанский манат',
        'GEL' => 'Грузинский лари',
        'TRY' => 'Турецкая лира',
        'AED' => 'Дирхам ОАЭ',
        'ILS' => 'Израильский шекель',
        'INR' => 'Индийская рупия',
        'KRW' => 'Южнокорейская вона',
        'SGD' => 'Сингапурский доллар',
        'MXN' => 'Мексиканское песо',
        'BRL' => 'Бразильский реал',
        'PLN' => 'Польский злотый',
        'CZK' => 'Чешская крона',
        'SEK' => 'Шведская крона',
        'ZAR' => 'Южноафриканский рэнд',
    ];
    $bm_cur_value = $value ?? 'RUB';
    $bm_cur_label = $label ?? 'Валюта';
@endphp
<div class="crm-form__field">
    <label class="form-label" for="{{ $id }}">{{ $bm_cur_label }}@if (!empty($required))<span class="req">*</span>@endif</label>
    <select id="{{ $id }}" name="{{ $name }}" class="form-select" @if (!empty($required)) required @endif>
        @foreach ($bm_currencies as $code => $nameRu)
            <option value="{{ $code }}" @selected($bm_cur_value === $code)>{{ $code }} · {{ $nameRu }}</option>
        @endforeach
    </select>
</div>
