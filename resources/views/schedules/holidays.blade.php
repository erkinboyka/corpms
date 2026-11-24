@extends('layouts.tabler')
@section('workspace_layout', 'planning')

@section('title', __('Праздники') . ' ' . $year)

@php
    $kindChips = [
        'public' => 'crm-chip--red',
        'operating' => 'crm-chip--cyan',
        'shortened' => 'crm-chip--orange',
    ];
    $kindTones = [
        'public' => 'var(--crm-danger)',
        'operating' => 'var(--crm-info)',
        'shortened' => 'var(--crm-warning)',
    ];

    // индекс праздников: месяц => день => список
    $byDay = [];
    foreach ($holidays as $holiday) {
        $byDay[(int) $holiday->date->format('m')][(int) $holiday->date->format('d')][] = $holiday;
    }

    $byKind = $holidays->countBy(fn ($h) => $h->kind->value);

    $months = [
        1 => __('Январь'), 2 => __('Февраль'), 3 => __('Март'), 4 => __('Апрель'),
        5 => __('Май'), 6 => __('Июнь'), 7 => __('Июль'), 8 => __('Август'),
        9 => __('Сентябрь'), 10 => __('Октябрь'), 11 => __('Ноябрь'), 12 => __('Декабрь'),
    ];
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Календарь') }}</div>
            <h1 class="page-title mt-1">{{ __('Праздники') }} {{ $year }}</h1>
            <div class="text-secondary mt-1">{{ __('Производственный календарь') }} {{ $holidays->count() }} {{ __('дн. в году') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
                <div class="d-flex gap-1 align-items-center">
                    <a class="btn btn-ghost-secondary" href="{{ route('schedules.holidays', ['year' => $year - 1]) }}" title="{{ __("Предыдущий год") }}"><i class="ti ti-chevron-left"></i></a>
                    <form method="get" class="d-flex gap-1 align-items-center">
                        <input class="form-control" type="number" name="year" value="{{ $year }}" style="width: 6.5rem" aria-label="{{ __("Год") }}">
                        <button class="btn btn-ghost-secondary">{{ __('Показать') }}</button>
                    </form>
                    <a class="btn btn-ghost-secondary" href="{{ route('schedules.holidays', ['year' => $year + 1]) }}" title="{{ __("Следующий год") }}"><i class="ti ti-chevron-right"></i></a>
                </div>
                <a class="btn btn-outline-secondary" href="{{ route('schedules.holidays', ['year' => now()->year]) }}#month-{{ now()->month }}">{{ __('Текущий месяц') }}</a>
                @if ($canManage)
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newHoliday">
                        <i class="ti ti-plus me-1"></i>{{ __('Добавить праздник') }}
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="crm-metrics mb-3">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-danger);"><i class="ti ti-flag"></i>{{ __('Праздничных дней') }}</div>
        <div class="crm-metric__value">{{ $byKind['public'] ?? 0 }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-briefcase"></i>{{ __('Рабочие по календарю') }}</div>
        <div class="crm-metric__value">{{ $byKind['operating'] ?? 0 }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-clock-hour-4"></i>{{ __('Сокращённые дни') }}</div>
        <div class="crm-metric__value">{{ $byKind['shortened'] ?? 0 }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-text-secondary);"><i class="ti ti-calendar-stats"></i>{{ __('Всего отметок') }}</div>
        <div class="crm-metric__value">{{ $holidays->count() }}</div>
    </div>
</div>

{{-- ===== Годовой календарь ===== --}}
<div class="row g-3 mb-3">
    @foreach ($months as $m => $monthName)
        @php
            $first = \Carbon\Carbon::create($year, $m, 1);
            $lead = $first->dayOfWeekIso - 1;
            $daysInMonth = $first->daysInMonth;
            $isCurrentMonth = $year === (int) now()->format('Y') && $m === (int) now()->format('m');
            $todayDay = (int) now()->format('d');
        @endphp
        <div class="col-12 col-sm-6 col-md-4 col-xl-3">
            <div class="crm-form__section h-100">
                <h2 id="month-{{ $m }}" tabindex="-1" class="h5">{{ $monthName }} @if ($isCurrentMonth)<span class="crm-chip crm-chip--green ms-1">{{ __('сейчас') }}</span>@endif</h2>
                <div class="crm-cal crm-cal--sm mt-2">
                    <div class="crm-cal__head">
                        @foreach ([__('Пн'), __('Вт'), __('Ср'), __('Чт'), __('Пт'), __('Сб'), __('Вс')] as $dow)
                            <div>{{ $dow }}</div>
                        @endforeach
                    </div>
                    <div class="crm-cal__grid">
                        @for ($i = 0; $i < $lead; $i++)
                            <div class="is-out"></div>
                        @endfor
                        @for ($day = 1; $day <= $daysInMonth; $day++)
                            @php
                                $dayHolidays = $byDay[$m][$day] ?? [];
                                $holiday = $dayHolidays[0] ?? null;
                                $isWeekend = $first->copy()->addDays($day - 1)->dayOfWeekIso >= 6;
                                $classes = [];
                                if ($isWeekend) { $classes[] = 'is-weekend'; }
                                if ($holiday !== null) { $classes[] = 'is-holiday'; }
                                if ($holiday?->kind->value === 'operating') { $classes[] = 'is-workday'; }
                                if ($isCurrentMonth && $day === $todayDay) { $classes[] = 'is-today'; }
                            @endphp
                            @php
                                $dayPayload = collect($dayHolidays)->map(fn($h) => [
                                    'name' => $h->name,
                                    'date' => $h->date->format('d.m.Y'),
                                    'kind' => $crm_label('holiday_kind', $h->kind->value),
                                    'kindValue' => $h->kind->value,
                                    'transferred' => $h->transferred_from ? \Carbon\Carbon::parse($h->transferred_from)->format('d.m.Y') : null,
                                ])->values()->all();
                                $dateObj = $first->copy()->day($day);
                                $dayTitle = $dateObj->locale(app()->getLocale())->isoFormat('D MMMM YYYY, dddd');
                            @endphp
                            <div class="{{ implode(' ', $classes) }} crm-cal__day-btn"
                                 role="button" tabindex="0"
                                 data-holiday-day="{{ $day }}"
                                 data-holiday-month="{{ $monthName }}"
                                 data-day-title="{{ $dayTitle }}"
                                 data-holidays='@json($dayPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG)'>
                                <span class="crm-cal__day">{{ $day }}</span>
                                @if ($holiday !== null)
                                    <span class="crm-cal__dot" style="background: {{ $kindTones[$holiday->kind->value] ?? 'var(--crm-text-secondary)' }};"></span>
                                @endif
                            </div>
                        @endfor
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- ===== Список праздников ===== --}}
<div class="crm-form__section">
    <h3>{{ __('Праздники и особые дни') }} <span class="text-secondary fw-normal">({{ $holidays->count() }})</span></h3>
    <div class="crm-form__desc">{{ __('Красные — выходные, синие — рабочие по календарю, оранжевые — сокращённые') }}</div>
    <div class="crm-queue">
        @forelse ($holidays as $holiday)
            <div class="crm-queue__item" style="--c: {{ $kindTones[$holiday->kind->value] ?? 'var(--crm-text-secondary)' }};">
                <span class="crm-entity__logo" style="width:38px;height:38px;font-size:13px;border-radius:11px;background: {{ ($kindTones[$holiday->kind->value] ?? 'var(--crm-text-secondary)') }}1f; color: {{ $kindTones[$holiday->kind->value] ?? 'var(--crm-text-secondary)' }};">
                    <i class="ti ti-{{ $holiday->kind->value === 'operating' ? 'briefcase' : ($holiday->kind->value === 'shortened' ? 'clock-hour-4' : 'flag') }}"></i>
                </span>
                <div class="crm-queue__body">
                    <div class="crm-queue__title">{{ $holiday->name }}</div>
                    <div class="crm-queue__sub">
                        {{ $holiday->date->format('d.m.Y') }} · {{ $holiday->date->translatedFormat('l') }}
                        @if ($holiday->transferred_from) · {{ __('перенесён с') }} {{ \Carbon\Carbon::parse($holiday->transferred_from)->format('d.m') }}@endif
                    </div>
                </div>
                <span class="crm-chip {{ $kindChips[$holiday->kind->value] ?? 'crm-chip--grey' }}">{{ $crm_label('holiday_kind', $holiday->kind->value) }}</span>
                @if ($canManage)
                    <form method="post" action="{{ route('holidays.destroy', $holiday) }}" data-confirm="{{ __('Удалить праздник') }} «{{ $holiday->name }}»?" data-confirm-danger="1" data-submit-guard>
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-ghost-danger" title="{{ __("Удалить праздник") }}"><i class="ti ti-trash"></i></button>
                    </form>
                @endif
            </div>
        @empty
            <div class="crm-queue__item" style="--c: var(--crm-text-secondary);">
                <div class="crm-queue__body">
                    <div class="crm-queue__title">{{ __('Праздников в году нет') }}</div>
                    <div class="crm-queue__sub">{{ __('Добавьте первый — календарь подсветит его автоматически') }}</div>
                </div>
            </div>
        @endforelse
    </div>
</div>

{{-- ===== Модалка добавления ===== --}}
@if ($canManage)
<div class="modal modal-blur fade" id="newHoliday" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('holidays.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Добавить праздник') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('День') }}</h3>
                    <div class="crm-form__desc">{{ __('Название и дата — календарь подсветит его в нужном месяце') }}</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nh-name">{{ __('Название') }}<span class="req">*</span></label>
                        <input type="text" id="nh-name" name="name" class="form-control" placeholder="{{ __("День народного единства") }}" required>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="nh-date">{{ __('Дата') }}<span class="req">*</span></label>
                        <input type="date" id="nh-date" name="date" class="form-control" value="{{ $year }}-01-01" required>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Тип дня') }}</h3>
                    <div class="crm-form__desc">{{ __('Определяет, выходной это день или рабочий по календарю') }}</div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="nh-kind">{{ __('Тип') }}</label>
                        <select id="nh-kind" class="form-select" name="kind">
                            @foreach (['public', 'operating', 'shortened'] as $kind)
                                <option value="{{ $kind }}">{{ $crm_label('holiday_kind', $kind) }}</option>
                            @endforeach
                        </select>
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
@endif

<div class="modal modal-blur fade" id="holidayDayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="holidayDayModalTitle">{{ __('Праздник') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Закрыть') }}"></button>
            </div>
            <div class="modal-body" id="holidayDayModalBody"></div>
        </div>
    </div>
</div>
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('holidayDayModal');
    if (!modalEl) return;
    var title = document.getElementById('holidayDayModalTitle');
    var body = document.getElementById('holidayDayModalBody');
    function openDay(el) {
        var raw = el.getAttribute('data-holidays') || '[]';
        var items = [];
        try { items = JSON.parse(raw); } catch (e) { items = []; }
        var day = el.getAttribute('data-holiday-day') || '';
        var month = el.getAttribute('data-holiday-month') || '';
        var dayTitle = el.getAttribute('data-day-title') || (day + ' ' + month);
        title.textContent = dayTitle;
        body.innerHTML = '';
        if (!items.length) {
            var empty = document.createElement('div');
            empty.className = 'text-secondary';
            empty.textContent = '{{ __('Праздников в этот день нет.') }}';
            body.appendChild(empty);
        } else {
            items.forEach(function (h) {
                var row = document.createElement('div');
                row.className = 'd-flex align-items-start gap-3 p-3 rounded-3 border mb-2';
                var dot = document.createElement('span');
                dot.style.cssText = 'width:10px;height:10px;border-radius:50%;margin-top:6px;flex:0 0 10px;background:' + ({public:'var(--crm-danger)',operating:'var(--crm-info)',shortened:'var(--crm-warning)'}[h.kindValue]||'var(--crm-text-secondary)');
                var content = document.createElement('div');
                var name = document.createElement('div');
                name.className = 'fw-semibold';
                name.textContent = h.name || '';
                var meta = document.createElement('div');
                meta.className = 'text-secondary small mt-1';
                meta.textContent = (h.kind || '') + (h.date ? ' · ' + h.date : '') + (h.transferred ? ' · перенос с ' + h.transferred : '');
                content.appendChild(name); content.appendChild(meta);
                row.appendChild(dot); row.appendChild(content);
                body.appendChild(row);
            });
        }
        if (window.bootstrap && bootstrap.Modal) bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-holidays]');
        if (el) openDay(el);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var el = e.target.closest('[data-holidays]');
        if (!el) return;
        e.preventDefault(); openDay(el);
    });
});
</script>
@endpush
@endsection