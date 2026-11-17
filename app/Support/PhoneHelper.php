<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Телефоны по странам: страна выбирается по коду (+7…), часовому поясу
 * компании или угадывается из введённого номера. Колонки country в БД нет —
 * единственный источник истины здесь; формы отображают страну в селекте
 * (без name — это только UI, на сервер уходит строка телефона целиком).
 *
 * RU и KZ делят код +7: в этом случае однозначно страну задаёт селект
 * «Страна» в форме (см. public/assets/js/corpms.js, [data-crm-phone-group]).
 */
final class PhoneHelper
{
    /**
     * ISO-код => название, код, группа. Порядок массива = порядок в селекте:
     * сначала RU/TJ, затем ЦА, СНГ, Европа, Америка, Азия/Ближний Восток.
     * Флаги-эмодзи не используются (на Windows не рендерятся) — в UI выводится
     * текстовый ISO-бейдж (см. partials/phone-field.blade.php).
     *
     * @var list<array{iso: string, name: string, dial: string, group: string}>
     */
    private const COUNTRIES = [
        /* приоритет: Россия и Таджикистан */
        ['iso' => 'RU', 'name' => 'Россия', 'dial' => '+7', 'group' => ''],
        ['iso' => 'TJ', 'name' => 'Таджикистан', 'dial' => '+992', 'group' => ''],

        /* Центральная Азия */
        ['iso' => 'KZ', 'name' => 'Казахстан', 'dial' => '+7', 'group' => 'Страны ЦА'],
        ['iso' => 'UZ', 'name' => 'Узбекистан', 'dial' => '+998', 'group' => 'Страны ЦА'],
        ['iso' => 'KG', 'name' => 'Кыргызстан', 'dial' => '+996', 'group' => 'Страны ЦА'],
        ['iso' => 'TM', 'name' => 'Туркменистан', 'dial' => '+993', 'group' => 'Страны ЦА'],

        /* СНГ */
        ['iso' => 'BY', 'name' => 'Беларусь', 'dial' => '+375', 'group' => 'СНГ'],
        ['iso' => 'UA', 'name' => 'Украина', 'dial' => '+380', 'group' => 'СНГ'],
        ['iso' => 'AM', 'name' => 'Армения', 'dial' => '+374', 'group' => 'СНГ'],
        ['iso' => 'AZ', 'name' => 'Азербайджан', 'dial' => '+994', 'group' => 'СНГ'],
        ['iso' => 'GE', 'name' => 'Грузия', 'dial' => '+995', 'group' => 'СНГ'],
        ['iso' => 'MD', 'name' => 'Молдавия', 'dial' => '+373', 'group' => 'СНГ'],

        /* Европа */
        ['iso' => 'DE', 'name' => 'Германия', 'dial' => '+49', 'group' => 'Европа'],
        ['iso' => 'FR', 'name' => 'Франция', 'dial' => '+33', 'group' => 'Европа'],
        ['iso' => 'GB', 'name' => 'Великобритания', 'dial' => '+44', 'group' => 'Европа'],
        ['iso' => 'IT', 'name' => 'Италия', 'dial' => '+39', 'group' => 'Европа'],
        ['iso' => 'ES', 'name' => 'Испания', 'dial' => '+34', 'group' => 'Европа'],
        ['iso' => 'PL', 'name' => 'Польша', 'dial' => '+48', 'group' => 'Европа'],
        ['iso' => 'FI', 'name' => 'Финляндия', 'dial' => '+358', 'group' => 'Европа'],
        ['iso' => 'SE', 'name' => 'Швеция', 'dial' => '+46', 'group' => 'Европа'],
        ['iso' => 'NO', 'name' => 'Норвегия', 'dial' => '+47', 'group' => 'Европа'],
        ['iso' => 'DK', 'name' => 'Дания', 'dial' => '+45', 'group' => 'Европа'],
        ['iso' => 'NL', 'name' => 'Нидерланды', 'dial' => '+31', 'group' => 'Европа'],
        ['iso' => 'BE', 'name' => 'Бельгия', 'dial' => '+32', 'group' => 'Европа'],
        ['iso' => 'CH', 'name' => 'Швейцария', 'dial' => '+41', 'group' => 'Европа'],
        ['iso' => 'AT', 'name' => 'Австрия', 'dial' => '+43', 'group' => 'Европа'],
        ['iso' => 'PT', 'name' => 'Португалия', 'dial' => '+351', 'group' => 'Европа'],
        ['iso' => 'GR', 'name' => 'Греция', 'dial' => '+30', 'group' => 'Европа'],
        ['iso' => 'RO', 'name' => 'Румыния', 'dial' => '+40', 'group' => 'Европа'],
        ['iso' => 'CZ', 'name' => 'Чехия', 'dial' => '+420', 'group' => 'Европа'],
        ['iso' => 'HU', 'name' => 'Венгрия', 'dial' => '+36', 'group' => 'Европа'],
        ['iso' => 'BG', 'name' => 'Болгария', 'dial' => '+359', 'group' => 'Европа'],
        ['iso' => 'IE', 'name' => 'Ирландия', 'dial' => '+353', 'group' => 'Европа'],
        ['iso' => 'LT', 'name' => 'Литва', 'dial' => '+370', 'group' => 'Европа'],
        ['iso' => 'LV', 'name' => 'Латвия', 'dial' => '+371', 'group' => 'Европа'],
        ['iso' => 'EE', 'name' => 'Эстония', 'dial' => '+372', 'group' => 'Европа'],

        /* Америка */
        ['iso' => 'US', 'name' => 'США', 'dial' => '+1', 'group' => 'Америка'],
        ['iso' => 'CA', 'name' => 'Канада', 'dial' => '+1', 'group' => 'Америка'],
        ['iso' => 'MX', 'name' => 'Мексика', 'dial' => '+52', 'group' => 'Америка'],
        ['iso' => 'BR', 'name' => 'Бразилия', 'dial' => '+55', 'group' => 'Америка'],
        ['iso' => 'AR', 'name' => 'Аргентина', 'dial' => '+54', 'group' => 'Америка'],

        /* Азия и Ближний Восток */
        ['iso' => 'TR', 'name' => 'Турция', 'dial' => '+90', 'group' => 'Азия и Ближний Восток'],
        ['iso' => 'AE', 'name' => 'ОАЭ', 'dial' => '+971', 'group' => 'Азия и Ближний Восток'],
        ['iso' => 'IL', 'name' => 'Израиль', 'dial' => '+972', 'group' => 'Азия и Ближний Восток'],
        ['iso' => 'IN', 'name' => 'Индия', 'dial' => '+91', 'group' => 'Азия и Ближний Восток'],
        ['iso' => 'CN', 'name' => 'Китай', 'dial' => '+86', 'group' => 'Азия и Ближний Восток'],
        ['iso' => 'JP', 'name' => 'Япония', 'dial' => '+81', 'group' => 'Азия и Ближний Восток'],
        ['iso' => 'KR', 'name' => 'Южная Корея', 'dial' => '+82', 'group' => 'Азия и Ближний Восток'],
    ];

    /** ISO-код => часовые пояса (только РФ и СНГ; всё остальное => RU). */
    private const TZ_BY_ISO = [
        'RU' => [
            'Europe/Moscow', 'Europe/Kaliningrad', 'Europe/Samara', 'Europe/Volgograd',
            'Europe/Astrakhan', 'Europe/Ulyanovsk', 'Europe/Simferopol',
            'Asia/Yekaterinburg', 'Asia/Novosibirsk', 'Asia/Krasnoyarsk', 'Asia/Irkutsk',
            'Asia/Vladivostok', 'Asia/Anadyr', 'Asia/Kamchatka', 'Asia/Magadan', 'Asia/Sakhalin',
        ],
        'KZ' => ['Asia/Almaty', 'Asia/Astana'],
        'TJ' => ['Asia/Dushanbe'],
        'UZ' => ['Asia/Tashkent'],
        'KG' => ['Asia/Bishkek'],
        'BY' => ['Europe/Minsk'],
        'UA' => ['Europe/Kyiv', 'Europe/Kiev'],
        'AM' => ['Asia/Yerevan'],
        'AZ' => ['Asia/Baku'],
        'GE' => ['Asia/Tbilisi'],
        'TM' => ['Asia/Ashgabat'],
        'MD' => ['Europe/Chisinau'],
        'DE' => ['Europe/Berlin'],
        'FR' => ['Europe/Paris'],
        'GB' => ['Europe/London'],
        'IT' => ['Europe/Rome'],
        'ES' => ['Europe/Madrid'],
        'PL' => ['Europe/Warsaw'],
        'FI' => ['Europe/Helsinki'],
        'SE' => ['Europe/Stockholm'],
        'NO' => ['Europe/Oslo'],
        'DK' => ['Europe/Copenhagen'],
        'NL' => ['Europe/Amsterdam'],
        'BE' => ['Europe/Brussels'],
        'CH' => ['Europe/Zurich'],
        'AT' => ['Europe/Vienna'],
        'PT' => ['Europe/Lisbon'],
        'GR' => ['Europe/Athens'],
        'RO' => ['Europe/Bucharest'],
        'CZ' => ['Europe/Prague'],
        'HU' => ['Europe/Budapest'],
        'BG' => ['Europe/Sofia'],
        'IE' => ['Europe/Dublin'],
        'LT' => ['Europe/Vilnius'],
        'LV' => ['Europe/Riga'],
        'EE' => ['Europe/Tallinn'],
        'TR' => ['Europe/Istanbul'],
        'US' => [
            'America/New_York', 'America/Detroit', 'America/Chicago', 'America/Denver',
            'America/Los_Angeles', 'America/Phoenix', 'America/Anchorage', 'Pacific/Honolulu',
        ],
        'CA' => [
            'America/Toronto', 'America/Vancouver', 'America/Edmonton',
            'America/Winnipeg', 'America/Halifax', 'America/St_Johns',
        ],
        'MX' => ['America/Mexico_City', 'America/Tijuana', 'America/Monterrey'],
        'BR' => ['America/Sao_Paulo', 'America/Manaus', 'America/Fortaleza', 'America/Recife'],
        'AR' => ['America/Argentina/Buenos_Aires'],
        'AE' => ['Asia/Dubai'],
        'IL' => ['Asia/Jerusalem'],
        'IN' => ['Asia/Kolkata'],
        'CN' => ['Asia/Shanghai', 'Asia/Urumqi'],
        'JP' => ['Asia/Tokyo'],
        'KR' => ['Asia/Seoul'],
    ];

    /**
     * Часовой пояс => ISO-код страны (только РФ/СНГ; для остальных поясов ключа нет).
     *
     * @return array<string, string>
     */
    public static function dialByTimezone(): array
    {
        $map = [];
        foreach (self::TZ_BY_ISO as $iso => $zones) {
            foreach ($zones as $tz) {
                $map[$tz] = $iso;
            }
        }

        return $map;
    }

    /**
     * Упорядоченный список стран для селекта: iso, name, dial, tz, group.
     *
     * @return list<array{iso: string, name: string, dial: string, group: string, tz: list<string>}>
     */
    public static function countries(): array
    {
        $list = [];
        foreach (self::COUNTRIES as $country) {
            $country['tz'] = self::TZ_BY_ISO[$country['iso']] ?? [];
            $list[] = $country;
        }

        return $list;
    }

    /** ISO-код страны по часовому поясу; неизвестный пояс => RU. */
    public static function countryFromTimezone(?string $tz): string
    {
        if ($tz === null || $tz === '') {
            return 'RU';
        }

        return self::dialByTimezone()[$tz] ?? 'RU';
    }

    /** ISO-код страны по коду из номера (+992…); длинные коды первыми. */
    public static function countryFromPhone(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || ! str_starts_with($value, '+')) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }

        foreach (self::dialCodesLongestFirst() as $dial => $iso) {
            if (str_starts_with($digits, (string) $dial)) {
                return $iso;
            }
        }

        return null;
    }

    /**
     * Лучшее предположение страны: номер, затем пояс. Для общего кода +7
     * приоритет у пояса компании (KZ-компания с +7 => KZ, а не RU).
     */
    public static function guess(?string $phone, ?string $timezone): string
    {
        $iso = self::countryFromPhone((string) $phone);
        if ($iso === null) {
            return self::countryFromTimezone($timezone);
        }

        $tzIso = self::countryFromTimezone($timezone);
        if ($tzIso !== 'RU' && self::dialOf($tzIso) === self::dialOf($iso)) {
            return $tzIso;
        }

        return $iso;
    }

    /** Код страны ISO => "+7" / "+992"… (неизвестный => "+7"). */
    public static function dialOf(string $iso): string
    {
        foreach (self::COUNTRIES as $country) {
            if ($country['iso'] === $iso) {
                return $country['dial'];
            }
        }

        return '+7';
    }

    /** Коды без «+» => ISO; длинные коды первыми (992 раньше 9 и т.п.). */
    private static function dialCodesLongestFirst(): array
    {
        $dials = [];
        foreach (self::COUNTRIES as $country) {
            $dial = ltrim($country['dial'], '+');
            if (! isset($dials[$dial])) {
                $dials[$dial] = $country['iso'];
            }
        }

        uksort($dials, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $dials;
    }
}
