@extends('layouts.tabler')

@section('title', 'Компании')

@php
    $companyCards = collect($companies)->map(static function ($c) {
        $words = preg_split('/\s+/u', trim((string) $c->name), -1, PREG_SPLIT_NO_EMPTY);
        $initials = '';
        foreach (array_slice($words, 0, 2) as $w) {
            $initials .= mb_strtoupper(mb_substr($w, 0, 1));
        }
        return ['company' => $c, 'initials' => $initials !== '' ? $initials : 'CO'];
    });
@endphp

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Организация</div>
            <h2 class="page-title mt-1">Компании</h2>
            <div class="text-secondary mt-1">Юридические лица · {{ $companies->count() }}</div>
        </div>
        @can('checkBoxPermission', 'companies.manage')
        <div class="col-auto d-print-none">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newCompany">
                <i class="ti ti-plus me-1"></i>Добавить компанию
            </button>
        </div>
        @endcan
    </div>
</div>

<div class="crm-toolbar">
    <div class="input-icon">
        <input type="search" class="form-control crm-search-input" placeholder="Поиск по названию, коду, ИНН…" aria-label="Поиск компаний"
               data-crm-filter=".crm-companies > *" data-crm-filter-count="#companies-count">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <div class="crm-toolbar__spacer"></div>
    <span class="crm-toolbar__count" id="companies-count">Показано {{ $companies->count() }}</span>
</div>

<div class="row row-cards g-3 crm-companies">
    @forelse ($companyCards as $entry)
        @php
            $company = $entry['company'];
        @endphp
        <div class="col-12 col-md-6 col-xl-4">
            <div class="crm-entity h-100">
                <div class="crm-entity__head">
                    <span class="crm-entity__logo">{{ $entry['initials'] }}</span>
                    <div class="min-w-0">
                        <div class="crm-entity__name">{{ $company->name }}</div>
                        <div class="crm-entity__sub">код {{ $company->code }}@if (! $company->is_active) · <span class="crm-chip crm-chip--grey">архив</span>@endif</div>
                    </div>
                </div>
                <div class="crm-entity__meta">
                    <span><i class="ti ti-map-pin me-1"></i>{{ $company->region ?? 'регион не указан' }}</span>
                    <span><i class="ti ti-clock me-1"></i>{{ $company->timezone ?? '—' }}</span>
                    @if ($company->inn)<span><i class="ti ti-building-bank me-1"></i>ИНН {{ $company->inn }}</span>@endif
                </div>
                <div class="crm-entity__footer">
                    <div class="d-flex gap-1 flex-wrap">
                        <span class="crm-chip">{{ $company->departments_count }} отдел(ов)</span>
                        <span class="crm-chip">{{ $company->employees_count }} сотр.</span>
                    </div>
                    @can('checkBoxPermission', 'companies.manage')
                    <div class="d-inline-flex gap-1 crm-row-actions">
                        <button class="btn btn-sm btn-ghost-secondary" title="Изменить компанию" data-bs-toggle="modal" data-bs-target="#editCompany-{{ $company->id }}"><i class="ti ti-pencil"></i></button>
                        <form method="post" action="{{ route('companies.destroy', $company) }}" onsubmit="return confirm('Удалить компанию «{{ $company->name }}»? Возможна только для пустой компании.')" title="Удалить">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-ghost-danger" title="Удалить компанию"><i class="ti ti-trash"></i></button>
                        </form>
                    </div>
                    @endcan
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            @include('partials.empty-state', ['icon' => 'building', 'title' => 'Компаний пока нет', 'text' => 'Добавьте первое юридическое лицо, чтобы начать структуру организации.'])
        </div>
    @endforelse
</div>

@can('checkBoxPermission', 'companies.manage')
{{-- создание --}}
<div class="modal modal-blur fade" id="newCompany" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('companies.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новая компания</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>Идентификация</h3>
                    <div class="crm-form__desc">Название и внутренний код — обязательные поля.</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-name">Название<span class="req">*</span></label>
                            <input type="text" id="nc-name" name="name" class="form-control" value="{{ old('name') }}" placeholder="ООО «Ромашка»" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-code">Код<span class="req">*</span></label>
                            <input type="text" id="nc-code" name="code" class="form-control" value="{{ old('code') }}" placeholder="ROM" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-address">Адрес</label>
                            <input type="text" id="nc-address" name="address" class="form-control" value="{{ old('address') }}" placeholder="г. Москва, ул. Ленина, 1" autocomplete="off">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-active">В работе</label>
                            <div class="form-check form-switch">
                                <input type="hidden" name="is_active" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="nc-active" name="is_active" value="1" @checked(old('is_active', '1') === '1')>
                                <label class="form-check-label" for="nc-active">Компания активна</label>
                            </div>
                            <div class="form-text">Архивные компании помечаются как «архив».</div>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>Реквизиты и контакты</h3>
                    <div class="crm-form__desc">Необязательно — можно заполнить позже. Поля подсказывают формат при вводе.</div>
                    @php
                        $tzList = \DateTimeZone::listIdentifiers();
                        $regionList = [
                            'Москва', 'Санкт-Петербург', 'Московская область', 'Ленинградская область',
                            'Свердловская область', 'Новосибирская область', 'Казань (Татарстан)',
                            'Краснодарский край', 'Нижегородская область', 'Республика Беларусь',
                            'Казахстан (Алматы)', 'Казахстан (Астана)', 'Узбекистан (Ташкент)',
                            'Кыргызстан (Бишкек)', 'Армения (Ереван)', 'Грузия (Тбилиси)',
                        ];
                    @endphp
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-inn">ИНН</label>
                            <input type="text" id="nc-inn" name="inn" class="form-control" inputmode="numeric"
                                   value="{{ old('inn') }}" maxlength="12" data-crm-digits placeholder="10 цифр — компания, 12 — ИП" autocomplete="off">
                            <div class="form-text">Ровно 10 или 12 цифр.</div>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-region">Регион</label>
                            <input type="text" id="nc-region" name="region" class="form-control" list="crm-region-list"
                                   value="{{ old('region') }}" placeholder="Начните вводить…" autocomplete="off">
                            <datalist id="crm-region-list">
                                @foreach ($regionList as $regionName)
                                    <option value="{{ $regionName }}"></option>
                                @endforeach
                            </datalist>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-tz">Часовой пояс</label>
                            <select id="nc-tz" name="timezone" class="form-select">
                                <option value="">— не указан —</option>
                                @foreach ($tzList as $tz)
                                    <option value="{{ $tz }}" @selected(old('timezone', 'Europe/Moscow') === $tz)>{{ $tz }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-email">E-mail</label>
                            <input type="email" id="nc-email" name="email" class="form-control" value="{{ old('email') }}" placeholder="office@company.ru" autocomplete="off">
                        </div>
                        @include('partials.phone-field', [
                            'id' => 'nc-phone',
                            'name' => 'phone',
                            'value' => old('phone'),
                            'hint' => 'Формат зависит от страны — страна выбирается автоматически по часовому поясу.',
                            'timezone' => old('timezone', 'Europe/Moscow'),
                        ])
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Добавить</button>
            </div>
        </form>
    </div>
</div>

{{-- правка — модалка на каждую компанию (компаний в системе немного) --}}
@foreach ($companies as $company)
<div class="modal modal-blur fade" id="editCompany-{{ $company->id }}" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('companies.update', $company) }}" data-submit-guard>
            @csrf @method('PATCH')
            <div class="modal-header"><h5 class="modal-title">Правка компании</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>Идентификация</h3>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ec{{ $company->id }}-name">Название<span class="req">*</span></label>
                            <input type="text" id="ec{{ $company->id }}-name" name="name" class="form-control" value="{{ old('name', $company->name) }}" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ec{{ $company->id }}-code">Код<span class="req">*</span></label>
                            <input type="text" id="ec{{ $company->id }}-code" name="code" class="form-control" value="{{ old('code', $company->code) }}" required>
                        </div>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ec{{ $company->id }}-legal">Полное наименование</label>
                        <input type="text" id="ec{{ $company->id }}-legal" name="legal_name" class="form-control" value="{{ old('legal_name', $company->legal_name) }}">
                    </div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ec{{ $company->id }}-address">Адрес</label>
                            <input type="text" id="ec{{ $company->id }}-address" name="address" class="form-control" value="{{ old('address', $company->address) }}" placeholder="г. Москва, ул. Ленина, 1" autocomplete="off">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ec{{ $company->id }}-active">В работе</label>
                            <div class="form-check form-switch">
                                <input type="hidden" name="is_active" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="ec{{ $company->id }}-active" name="is_active" value="1" @checked(old('is_active', $company->is_active ? '1' : '0') === '1')>
                                <label class="form-check-label" for="ec{{ $company->id }}-active">Компания активна</label>
                            </div>
                            <div class="form-text">Архивные компании помечаются как «архив».</div>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>Реквизиты и контакты</h3>
                    @php
                        $tzList = \DateTimeZone::listIdentifiers();
                        $regionList = [
                            'Москва', 'Санкт-Петербург', 'Московская область', 'Ленинградская область',
                            'Свердловская область', 'Новосибирская область', 'Казань (Татарстан)',
                            'Краснодарский край', 'Нижегородская область', 'Республика Беларусь',
                            'Казахстан (Алматы)', 'Казахстан (Астана)', 'Узбекистан (Ташкент)',
                            'Кыргызстан (Бишкек)', 'Армения (Ереван)', 'Грузия (Тбилиси)',
                        ];
                    @endphp
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ec{{ $company->id }}-inn">ИНН</label>
                            <input type="text" id="ec{{ $company->id }}-inn" name="inn" class="form-control"
                                   value="{{ old('inn', $company->inn) }}" inputmode="numeric" maxlength="12" data-crm-digits
                                   placeholder="10 цифр — компания, 12 — ИП" autocomplete="off">
                            <div class="form-text">Ровно 10 или 12 цифр.</div>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ec{{ $company->id }}-region">Регион</label>
                            <input type="text" id="ec{{ $company->id }}-region" name="region" class="form-control"
                                   value="{{ old('region', $company->region) }}" list="crm-region-list-{{ $company->id }}"
                                   placeholder="Начните вводить…" autocomplete="off">
                            <datalist id="crm-region-list-{{ $company->id }}">
                                @foreach ($regionList as $regionName)
                                    <option value="{{ $regionName }}"></option>
                                @endforeach
                            </datalist>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ec{{ $company->id }}-tz">Часовой пояс</label>
                            <select id="ec{{ $company->id }}-tz" name="timezone" class="form-select">
                                <option value="">— не указан —</option>
                                @foreach ($tzList as $tz)
                                    <option value="{{ $tz }}" @selected(old('timezone', $company->timezone) === $tz)>{{ $tz }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ec{{ $company->id }}-email">E-mail</label>
                            <input type="email" id="ec{{ $company->id }}-email" name="email" class="form-control" value="{{ old('email', $company->email) }}" autocomplete="off">
                        </div>
                        @include('partials.phone-field', [
                            'id' => "ec{$company->id}-phone",
                            'name' => 'phone',
                            'value' => old('phone', $company->phone),
                            'hint' => 'Формат зависит от страны — страна выбирается автоматически по часовому поясу.',
                            'timezone' => old('timezone', $company->timezone),
                        ])
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>
@endforeach
@endcan
@endsection
