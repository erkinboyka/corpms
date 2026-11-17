@extends('layouts.tabler')

@section('title', 'Праздники ' . $year)

@php
    $kindChips = [
        'public' => 'crm-chip--red',
        'operating' => 'crm-chip--cyan',
        'shortened' => 'crm-chip--orange',
    ];
    $kindTones = [
        'public' => '#d63939',
        'operating' => '#4299e1',
        'shortened' => '#f76707',
    ];

    // индекс праздников: месяц => день => список
    $byDay = [];
    foreach ($holidays as $holiday) {
        $byDay[(int) $holiday->date->format('m')][(int) $holiday->date->format('d')][] = $holiday;
    }

    $byKind = $holidays->countBy(fn ($h) => $h->kind->value);

    $months = [
        1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
        5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
        9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
    ];
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Календарь</div>
            <h2 class="page-title mt-1">Праздники {{ $year }}</h2>
            <div class="text-secondary mt-1">Производственный календарь · {{ $holidays->count() }} дн. в году</div>
        </div>
        <div class="col-auto d-print-none">
            <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
                <div class="d-flex gap-1 align-items-center">
                    <a class="btn btn-ghost-secondary" href="{{ route('schedules.holidays', ['year' => $year - 1]) }}" title="Предыдущий год"><i class="ti ti-chevron-left"></i></a>
                    <form method="get" class="d-flex gap-1 align-items-center">
                        <input class="form-control" type="number" name="year" value="{{ $year }}" style="width: 6.5rem" aria-label="Год">
                        <button class="btn btn-ghost-secondary">Год</button>
                    </form>
                    <a class="btn btn-ghost-secondary" href="{{ route('schedules.holidays', ['year' => $year + 1]) }}" title="Следующий год"><i class="ti ti-chevron-right"></i></a>
                </div>
                @if ($canManage)
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newHoliday">
                        <i class="ti ti-plus me-1"></i>Добавить праздник
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="crm-metrics mb-3">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #d63939;"><i class="ti ti-flag"></i>Праздничных дней</div>
        <div class="crm-metric__value">{{ $byKind['public'] ?? 0 }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #4299e1;"><i class="ti ti-briefcase"></i>Рабочие по календарю</div>
        <div class="crm-metric__value">{{ $byKind['operating'] ?? 0 }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #f76707;"><i class="ti ti-clock-hour-4"></i>Сокращённые дни</div>
        <div class="crm-metric__value">{{ $byKind['shortened'] ?? 0 }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #6c7a91;"><i class="ti ti-calendar-stats"></i>Всего отметок</div>
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
        <div class="col-6 col-md-4 col-xl-3">
            <div class="crm-form__section h-100">
                <h3 class="h5">{{ $monthName }} @if ($isCurrentMonth)<span class="crm-chip crm-chip--green ms-1">сейчас</span>@endif</h3>
                <div class="crm-cal crm-cal--sm mt-2">
                    <div class="crm-cal__head">
                        @foreach (['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'] as $dow)
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
                            <div class="{{ implode(' ', $classes) }}"
                                 @if ($holiday !== null) title="{{ $holiday->name }} — {{ $holiday->date->format('d.m') }}" @endif>
                                <span class="crm-cal__day">{{ $day }}</span>
                                @if ($holiday !== null)
                                    <span class="crm-cal__dot" style="background: {{ $kindTones[$holiday->kind->value] ?? '#6c7a91' }};"></span>
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
    <h3>Праздники и особые дни <span class="text-secondary fw-normal">({{ $holidays->count() }})</span></h3>
    <div class="crm-form__desc">Красные — выходные, синие — рабочие по календарю, оранжевые — сокращённые.</div>
    <div class="crm-queue">
        @forelse ($holidays as $holiday)
            <div class="crm-queue__item" style="--c: {{ $kindTones[$holiday->kind->value] ?? '#6c7a91' }};">
                <span class="crm-entity__logo" style="width:38px;height:38px;font-size:13px;border-radius:11px;background: {{ ($kindTones[$holiday->kind->value] ?? '#6c7a91') }}1f; color: {{ $kindTones[$holiday->kind->value] ?? '#6c7a91' }};">
                    <i class="ti ti-{{ $holiday->kind->value === 'operating' ? 'briefcase' : ($holiday->kind->value === 'shortened' ? 'clock-hour-4' : 'flag') }}"></i>
                </span>
                <div class="crm-queue__body">
                    <div class="crm-queue__title">{{ $holiday->name }}</div>
                    <div class="crm-queue__sub">
                        {{ $holiday->date->format('d.m.Y') }} · {{ $holiday->date->translatedFormat('l') }}
                        @if ($holiday->transferred_from) · перенесён с {{ \Carbon\Carbon::parse($holiday->transferred_from)->format('d.m') }}@endif
                    </div>
                </div>
                <span class="crm-chip {{ $kindChips[$holiday->kind->value] ?? 'crm-chip--grey' }}">{{ $crm_label('holiday_kind', $holiday->kind->value) }}</span>
                @if ($canManage)
                    <form method="post" action="{{ route('holidays.destroy', $holiday) }}" onsubmit="return confirm('Удалить праздник «{{ $holiday->name }}»?')" data-submit-guard>
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-ghost-danger" title="Удалить праздник"><i class="ti ti-trash"></i></button>
                    </form>
                @endif
            </div>
        @empty
            <div class="crm-queue__item" style="--c: #6c7a91;">
                <div class="crm-queue__body">
                    <div class="crm-queue__title">Праздников в году нет</div>
                    <div class="crm-queue__sub">Добавьте первый — календарь подсветит его автоматически.</div>
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
            <div class="modal-header"><h5 class="modal-title">Добавить праздник</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>День</h3>
                    <div class="crm-form__desc">Название и дата — календарь подсветит его в нужном месяце.</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nh-name">Название<span class="req">*</span></label>
                        <input type="text" id="nh-name" name="name" class="form-control" placeholder="День народного единства" required>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="nh-date">Дата<span class="req">*</span></label>
                        <input type="date" id="nh-date" name="date" class="form-control" value="{{ $year }}-01-01" required>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>Тип дня</h3>
                    <div class="crm-form__desc">Определяет, выходной это день или рабочий по календарю.</div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="nh-kind">Тип</label>
                        <select id="nh-kind" class="form-select" name="kind">
                            @foreach (['public', 'operating', 'shortened'] as $kind)
                                <option value="{{ $kind }}">{{ $crm_label('holiday_kind', $kind) }}</option>
                            @endforeach
                        </select>
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
@endif
@endsection
