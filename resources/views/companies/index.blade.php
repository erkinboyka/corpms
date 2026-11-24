@extends('layouts.tabler')
@section('workspace_layout', 'directory')

@section('title', __('Компании'))

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
            <div class="crm-eyebrow">{{ __('Организация') }}</div>
            <h1 class="page-title mt-1">{{ __('Компании') }}</h1>
            <div class="text-secondary mt-1">{{ __('Юридические лица') }} {{ $companies->count() }}</div>
        </div>
        @can('checkBoxPermission', 'companies.manage')
        <div class="col-auto d-print-none">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newCompany">
                <i class="ti ti-plus me-1"></i>{{ __('Добавить компанию') }}
            </button>
        </div>
        @endcan
    </div>
</div>

<div class="crm-toolbar">
    <div class="input-icon">
        <input type="search" class="form-control crm-search-input" placeholder="{{ __("Поиск по названию, коду, ИНН…") }}" aria-label="{{ __("Поиск компаний") }}"
               data-crm-filter=".crm-companies [data-crm-filter-item], .crm-companies tbody tr" data-crm-filter-count="#companies-count">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <div class="crm-toolbar__spacer"></div>
    <div class="crm-switch" data-crm-switch="companies" role="group" aria-label="{{ __("Вид списка") }}">
        <button type="button" data-view="list"><i class="ti ti-list"></i>{{ __('Список') }}</button>
        <button type="button" data-view="cards"><i class="ti ti-layout-grid"></i>{{ __('Карты') }}</button>
    </div>
    <span class="crm-toolbar__count" id="companies-count">{{ __('Показано') }} {{ $companies->count() }}</span>
</div>

<div class="crm-companies" data-crm-view-container="companies" data-view-default="cards">
    <div data-view-only="cards">
        <div class="row row-cards g-3">
            @forelse ($companyCards as $entry)
                @php $company = $entry['company']; @endphp
                <div class="col-12 col-md-6 col-xl-4" data-crm-filter-item>
                    <div class="crm-entity h-100">
                        <div class="crm-entity__head">
                            <span class="crm-entity__logo">{{ $entry['initials'] }}</span>
                            <div class="min-w-0">
                                <div class="crm-entity__name">{{ $company->name }}</div>
                                <div class="crm-entity__sub">{{ __('код') }} {{ $company->code }}@if (! $company->is_active) · <span class="crm-chip crm-chip--grey">{{ __('архив') }}</span>@endif</div>
                            </div>
                        </div>
                        <div class="crm-entity__meta">
                            <span><i class="ti ti-map-pin me-1"></i>{{ $company->region ?? __('регион не указан') }}</span>
                            <span><i class="ti ti-clock me-1"></i>{{ $company->timezone ?? '—' }}</span>
                            @if ($company->inn)<span><i class="ti ti-building-bank me-1"></i>{{ __('ИНН') }} {{ $company->inn }}</span>@endif
                        </div>
                        <div class="crm-entity__footer">
                            <div class="d-flex gap-1 flex-wrap">
                                <span class="crm-chip">{{ $company->departments_count }} {{ __('отдел(ов)') }}</span>
                                <span class="crm-chip">{{ $company->employees_count }} {{ __('сотр') }}</span>
                            </div>
                            @can('checkBoxPermission', 'companies.manage')
                            <div class="d-inline-flex gap-1 crm-row-actions">
                                <button class="btn btn-sm btn-ghost-secondary" data-bs-toggle="modal" data-bs-target="#editCompany-{{ $company->id }}"><i class="ti ti-pencil"></i></button>
                                <form method="post" action="{{ route('companies.destroy', $company) }}" data-confirm="{{ __('Удалить компанию') }} «{{ $company->name }}»?" data-confirm-danger="1">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost-danger"><i class="ti ti-trash"></i></button></form>
                            </div>
                            @endcan
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12">@include('partials.empty-state', ['icon' => 'building', 'title' => __('Компаний пока нет'), 'text' => __('Добавьте первое юридическое лицо, чтобы начать структуру организации.')])</div>
            @endforelse
        </div>
    </div>
    <div data-view-only="list">
        <div class="card"><div class="table-responsive"><table class="table table-vcenter card-table table-hover">
            <thead><tr><th>{{ __('Компания') }}</th><th>{{ __('Код') }}</th><th>{{ __('Регион') }}</th><th>{{ __('ИНН') }}</th><th>{{ __('Структура') }}</th><th class="w-1"></th></tr></thead>
            <tbody>
            @forelse ($companyCards as $entry)
                @php $company = $entry['company']; @endphp
                <tr data-crm-filter-item>
                    <td><div class="d-flex align-items-center gap-2"><span class="avatar avatar-sm rounded">{{ $entry['initials'] }}</span><div><div class="fw-medium">{{ $company->name }}</div><div class="text-secondary small">{{ $company->timezone ?? '—' }}@if (! $company->is_active) · {{ __('архив') }}@endif</div></div></div></td>
                    <td class="text-secondary">{{ $company->code }}</td>
                    <td class="text-secondary">{{ $company->region ?? '—' }}</td>
                    <td class="text-secondary">{{ $company->inn ?? '—' }}</td>
                    <td><span class="crm-chip">{{ $company->departments_count }} {{ __('отд.') }}</span> <span class="crm-chip">{{ $company->employees_count }} {{ __('сотр.') }}</span></td>
                    <td>@can('checkBoxPermission', 'companies.manage')<div class="d-inline-flex gap-1 crm-row-actions"><button class="btn btn-sm btn-ghost-secondary" data-bs-toggle="modal" data-bs-target="#editCompany-{{ $company->id }}"><i class="ti ti-pencil"></i></button><form method="post" action="{{ route('companies.destroy', $company) }}" data-confirm="{{ __('Удалить компанию') }} «{{ $company->name }}»?" data-confirm-danger="1">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost-danger"><i class="ti ti-trash"></i></button></form></div>@endcan</td>
                </tr>
            @empty
                <tr><td colspan="6">@include('partials.empty-state', ['icon' => 'building', 'title' => __('Компаний пока нет'), 'text' => __('Добавьте первое юридическое лицо, чтобы начать структуру организации.')])</td></tr>
            @endforelse
            </tbody>
        </table></div></div>
    </div>
</div>

@can('checkBoxPermission', 'companies.manage')
{{-- создание --}}
<div class="modal modal-blur fade" id="newCompany" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('companies.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Новая компания') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Идентификация') }}</h3>
                    <div class="crm-form__desc">{{ __('Название и внутренний код — обязательные поля') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-name">{{ __('Название') }}<span class="req">*</span></label>
                            <input type="text" id="nc-name" name="name" class="form-control" value="{{ old('name') }}" placeholder="{{ __("ООО «Ромашка»") }}" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-code">{{ __('Код') }}<span class="req">*</span></label>
                            <input type="text" id="nc-code" name="code" class="form-control" value="{{ old('code') }}" placeholder="ROM" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-address">{{ __('Адрес') }}</label>
                            <input type="text" id="nc-address" name="address" class="form-control" value="{{ old('address') }}" placeholder="{{ __("г. Москва, ул. Ленина, 1") }}" autocomplete="off">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-active">{{ __('В работе') }}</label>
                            <div class="form-check form-switch">
                                <input type="hidden" name="is_active" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="nc-active" name="is_active" value="1" @checked(old('is_active', '1') === '1')>
                                <label class="form-check-label" for="nc-active">{{ __('Компания активна') }}</label>
                            </div>
                            <div class="form-text">{{ __('Архивные компании помечаются как «архив') }}</div>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Реквизиты и контакты') }}</h3>
                    <div class="crm-form__desc">{{ __('Необязательно — можно заполнить позже. Поля подсказывают формат при вводе') }}</div>
                    @php
                        $tzList = \DateTimeZone::listIdentifiers();
                        $regionList = [
                            __('Москва'), __('Санкт-Петербург'), __('Московская область'), __('Ленинградская область'),
                            __('Свердловская область'), __('Новосибирская область'), __('Казань (Татарстан)'),
                            __('Краснодарский край'), __('Нижегородская область'), __('Республика Беларусь'),
                            __('Казахстан (Алматы)'), __('Казахстан (Астана)'), __('Узбекистан (Ташкент)'),
                            __('Кыргызстан (Бишкек)'), __('Армения (Ереван)'), __('Грузия (Тбилиси)'),
                        ];
                    @endphp
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-inn">{{ __('ИНН') }}</label>
                            <input type="text" id="nc-inn" name="inn" class="form-control" inputmode="numeric"
                                   value="{{ old('inn') }}" maxlength="12" data-crm-digits placeholder="{{ __("10 цифр — компания, 12 — ИП") }}" autocomplete="off">
                            <div class="form-text">{{ __('Ровно 10 или 12 цифр') }}</div>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-region">{{ __('Регион') }}</label>
                            <input type="text" id="nc-region" name="region" class="form-control" list="crm-region-list"
                                   value="{{ old('region') }}" placeholder="{{ __("Начните вводить…") }}" autocomplete="off">
                            <datalist id="crm-region-list">
                                @foreach ($regionList as $regionName)
                                    <option value="{{ $regionName }}"></option>
                                @endforeach
                            </datalist>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-tz">{{ __('Часовой пояс') }}</label>
                            <select id="nc-tz" name="timezone" class="form-select">
                                <option value="">{{ __('не указан') }}</option>
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
                            'hint' => __('Формат зависит от страны — страна выбирается автоматически по часовому поясу.'), 'timezone' => old('timezone', 'Europe/Moscow'),
                        ])
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Добавить') }}</button>
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
            <div class="modal-header"><h5 class="modal-title">{{ __('Правка компании') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Идентификация') }}</h3>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ec{{ $company->id }}-name">{{ __('Название') }}<span class="req">*</span></label>
                            <input type="text" id="ec{{ $company->id }}-name" name="name" class="form-control" value="{{ old('name', $company->name) }}" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ec{{ $company->id }}-code">{{ __('Код') }}<span class="req">*</span></label>
                            <input type="text" id="ec{{ $company->id }}-code" name="code" class="form-control" value="{{ old('code', $company->code) }}" required>
                        </div>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ec{{ $company->id }}-legal">{{ __('Полное наименование') }}</label>
                        <input type="text" id="ec{{ $company->id }}-legal" name="legal_name" class="form-control" value="{{ old('legal_name', $company->legal_name) }}">
                    </div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ec{{ $company->id }}-address">{{ __('Адрес') }}</label>
                            <input type="text" id="ec{{ $company->id }}-address" name="address" class="form-control" value="{{ old('address', $company->address) }}" placeholder="{{ __("г. Москва, ул. Ленина, 1") }}" autocomplete="off">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ec{{ $company->id }}-active">{{ __('В работе') }}</label>
                            <div class="form-check form-switch">
                                <input type="hidden" name="is_active" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="ec{{ $company->id }}-active" name="is_active" value="1" @checked(old('is_active', $company->is_active ? '1' : '0') === '1')>
                                <label class="form-check-label" for="ec{{ $company->id }}-active">{{ __('Компания активна') }}</label>
                            </div>
                            <div class="form-text">{{ __('Архивные компании помечаются как «архив') }}</div>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Реквизиты и контакты') }}</h3>
                    @php
                        $tzList = \DateTimeZone::listIdentifiers();
                        $regionList = [
                            __('Москва'), __('Санкт-Петербург'), __('Московская область'), __('Ленинградская область'),
                            __('Свердловская область'), __('Новосибирская область'), __('Казань (Татарстан)'),
                            __('Краснодарский край'), __('Нижегородская область'), __('Республика Беларусь'),
                            __('Казахстан (Алматы)'), __('Казахстан (Астана)'), __('Узбекистан (Ташкент)'),
                            __('Кыргызстан (Бишкек)'), __('Армения (Ереван)'), __('Грузия (Тбилиси)'),
                        ];
                    @endphp
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ec{{ $company->id }}-inn">{{ __('ИНН') }}</label>
                            <input type="text" id="ec{{ $company->id }}-inn" name="inn" class="form-control"
                                   value="{{ old('inn', $company->inn) }}" inputmode="numeric" maxlength="12" data-crm-digits
                                   placeholder="{{ __("10 цифр — компания, 12 — ИП") }}" autocomplete="off">
                            <div class="form-text">{{ __('Ровно 10 или 12 цифр') }}</div>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ec{{ $company->id }}-region">{{ __('Регион') }}</label>
                            <input type="text" id="ec{{ $company->id }}-region" name="region" class="form-control"
                                   value="{{ old('region', $company->region) }}" list="crm-region-list-{{ $company->id }}"
                                   placeholder="{{ __("Начните вводить…") }}" autocomplete="off">
                            <datalist id="crm-region-list-{{ $company->id }}">
                                @foreach ($regionList as $regionName)
                                    <option value="{{ $regionName }}"></option>
                                @endforeach
                            </datalist>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ec{{ $company->id }}-tz">{{ __('Часовой пояс') }}</label>
                            <select id="ec{{ $company->id }}-tz" name="timezone" class="form-select">
                                <option value="">{{ __('не указан') }}</option>
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
                            'hint' => __('Формат зависит от страны — страна выбирается автоматически по часовому поясу.'), 'timezone' => old('timezone', $company->timezone),
                        ])
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Сохранить') }}</button>
            </div>
        </form>
    </div>
</div>
@endforeach
@endcan
@endsection
