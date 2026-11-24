@extends('layouts.tabler')
@section('workspace_layout', 'planning')

@section('title', __('Мой график'))

@php
    $kindTones = [
        'public' => 'var(--crm-danger)',
        'operating' => 'var(--crm-info)',
        'shortened' => 'var(--crm-warning)',
    ];
    $monthNames = [1=>__('Январь'),2=>__('Февраль'),3=>__('Март'),4=>__('Апрель'),5=>__('Май'),6=>__('Июнь'),7=>__('Июль'),8=>__('Август'),9=>__('Сентябрь'),10=>__('Октябрь'),11=>__('Ноябрь'),12=>__('Декабрь')];
    $year = (int) ($year ?? request('year', now()->year));
    $month = (int) ($month ?? request('month', now()->month));
    $viewMode = $viewMode ?? (in_array(request('view'), ['details','calendar'], true) ? request('view') : 'details');
    $monthStart = \Carbon\Carbon::create($year, $month, 1);
    $daysInMonth = $monthStart->daysInMonth;
    $firstWeekday = $monthStart->dayOfWeekIso;
    $workDays = collect($schedule?->work_days ?? [1,2,3,4,5])->map(fn($d)=>(int)$d)->all();
    $holidaysByDay = collect($holidays ?? [])->filter(fn($h) => (int) \Carbon\Carbon::parse($h->date)->month === $month)->groupBy(fn($h) => (int) \Carbon\Carbon::parse($h->date)->day);
    $leaveItems = collect($leaves ?? []);
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Планирование') }}</div>
            <h1 class="page-title mt-1">{{ __('Мой график') }}</h1>
            <div class="text-secondary mt-1">{{ __('Рабочий график, история назначений и ближайшие события') }}</div>
        </div>
        <div class="col-auto d-print-none d-flex gap-2 align-items-center">
            <div class="crm-switch" data-crm-switch="my-schedule-view">
                <button type="button" class="crm-switch__btn {{ $viewMode !== 'calendar' ? 'is-active' : '' }}" data-view="details"><i class="ti ti-list-details"></i> {{ __('Детали') }}</button>
                <button type="button" class="crm-switch__btn {{ $viewMode === 'calendar' ? 'is-active' : '' }}" data-view="calendar"><i class="ti ti-calendar-month"></i> {{ __('Календарь') }}</button>
            </div>
            <a class="btn btn-ghost-secondary" href="{{ route('schedules.index') }}"><i class="ti ti-clock me-1"></i>{{ __('Графики работы') }}</a>
        </div>
    </div>
</div>

<form method="get" class="crm-toolbar mb-3">
    <input type="hidden" name="view" value="{{ $viewMode }}" data-crm-view-input="my-schedule-view">
    <label class="form-label mb-0 me-1" for="ms-month">{{ __('Месяц') }}</label>
    <select id="ms-month" name="month" class="form-select" onchange="this.form.submit()">
        @for($m=1;$m<=12;$m++)<option value="{{ $m }}" @selected($m===$month)>{{ $monthNames[$m] }}</option>@endfor
    </select>
    <label class="form-label mb-0 me-1" for="ms-year">{{ __('Год') }}</label>
    <input id="ms-year" type="number" name="year" value="{{ $year }}" class="form-control" style="width:6.5rem" min="2020" max="2100" onchange="this.form.submit()">
</form>

@if ($schedule !== null)
<div class="crm-profile-hero card mb-3">
    <div class="card-body">
        <div class="d-flex flex-column flex-md-row align-items-md-start gap-3">
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="crm-chip crm-chip--cyan">{{ $crm_label('schedule_type', $schedule->type->value) }}</span>
                    <span class="crm-chip crm-chip--grey">{{ __('Опоздание') }} {{ $schedule->grace_minutes ?? 10 }} {{ __('мин') }}</span>
                    <span class="crm-chip crm-chip--grey">{{ __('Отдых мин.') }} {{ $schedule->min_rest_hours ?? 8 }} {{ __('ч') }}</span>
                </div>
                <div class="crm-eyebrow mt-2">{{ __('Текущий график') }}</div>
                <h2 class="page-title mt-1 mb-1">{{ $schedule->name }}</h2>
                <div class="d-flex align-items-baseline gap-2 flex-wrap" style="font-size: 1.6rem; font-weight: 700; letter-spacing: -.02em; font-variant-numeric: tabular-nums;">
                    {{ \Carbon\Carbon::parse($schedule->start_time)->format('H:i') }}
                    <span class="text-secondary fw-normal" style="font-size: 1.1rem;">—</span>
                    {{ \Carbon\Carbon::parse($schedule->end_time)->format('H:i') }}
                </div>
                <div class="text-secondary mt-1">
                    @if ($schedule->break_start !== null)
                        <i class="ti ti-coffee me-1"></i>{{ __('Обед') }} {{ \Carbon\Carbon::parse($schedule->break_start)->format('H:i') }}—{{ \Carbon\Carbon::parse($schedule->break_end)->format('H:i') }}
                    @else
                        <i class="ti ti-coffee me-1"></i>{{ __('Без фиксированного перерыва') }}
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@else
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
        <i class="ti ti-alert-triangle"></i>
        <div>{{ __('График не назначен — обратитесь к HR') }}</div>
    </div>
@endif

<div data-crm-view-container="my-schedule-view" data-view-default="{{ $viewMode }}">
    <div data-view-only="details" @if($viewMode==='calendar') hidden @endif>
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="crm-form__section">
                    <h3>{{ __('История назначений') }} <span class="text-secondary fw-normal">({{ $assignments->count() }})</span></h3>
                    <div class="crm-form__desc">{{ __('Когда менялся ваш график работы') }}</div>
                    <div class="crm-timeline">
                        @forelse ($assignments as $assignment)
                            <div class="crm-timeline__item">
                                <div class="crm-timeline__time">
                                    {{ $assignment->effective_from->format('d.m.Y') }} — {{ $assignment->effective_to?->format('d.m.Y') ?? __('настоящее время') }}
                                </div>
                                <div class="crm-timeline__title">
                                    <strong>{{ $assignment->workSchedule?->name ?? '—' }}</strong>
                                    @if ($assignment->effective_to === null)
                                        <span class="crm-chip crm-chip--green ms-1">{{ __('Текущий') }}</span>
                                    @endif
                                </div>
                                @if ($assignment->workSchedule)
                                    <div class="crm-timeline__text">
                                        {{ \Carbon\Carbon::parse($assignment->workSchedule->start_time)->format('H:i') }}
                                        — {{ \Carbon\Carbon::parse($assignment->workSchedule->end_time)->format('H:i') }}
                                        · {{ $crm_label('schedule_type', $assignment->workSchedule->type->value) }}
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="crm-timeline__text">{{ __('Назначений пока нет') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="crm-form__section">
                    <h3>{{ __('Праздники') }} {{ $year }}</h3>
                    <div class="crm-form__desc">{{ __('Праздничные дни за выбранный год') }}</div>
                    <div class="crm-queue">
                        @forelse ($holidays as $holiday)
                            <div class="crm-queue__item" style="--c: {{ $kindTones[$holiday->kind->value] ?? 'var(--crm-text-secondary)' }};">
                                <div class="crm-queue__body">
                                    <div class="crm-queue__title">{{ $holiday->name }}</div>
                                    <div class="crm-queue__sub">{{ $holiday->date->format('d.m.Y') }} · {{ $crm_label('holiday_kind', $holiday->kind->value) }}</div>
                                </div>
                            </div>
                        @empty
                            <div class="text-secondary">{{ __('Праздников нет') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div data-view-only="calendar" @if($viewMode!=='calendar') hidden @endif>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title bm-section-title">{{ __('Календарь на месяц') }}</h2>
                <span class="crm-chip ms-auto">{{ $monthNames[$month] }} {{ $year }}</span>
            </div>
            <div class="card-body">
                <div class="crm-cal">
                    <div class="crm-cal__grid">
                        @foreach ([__('Пн'),__('Вт'),__('Ср'),__('Чт'),__('Пт'),__('Сб'),__('Вс')] as $dow)
                            <div class="crm-cal__dow">{{ $dow }}</div>
                        @endforeach
                        @for ($i = 1; $i < $firstWeekday; $i++)
                            <div class="is-out"></div>
                        @endfor
                        @for ($d = 1; $d <= $daysInMonth; $d++)
                            @php
                                $date = \Carbon\Carbon::create($year, $month, $d);
                                $isoDow = $date->dayOfWeekIso;
                                $isWorkDay = in_array($isoDow, $workDays, true);
                                $dayHolidays = $holidaysByDay->get($d, collect());
                                $dayLeaves = $leaveItems->filter(function ($l) use ($date) {
                                    $from = \Carbon\Carbon::parse($l->from_date)->startOfDay();
                                    $to = \Carbon\Carbon::parse($l->to_date)->startOfDay();
                                    return $date->copy()->startOfDay()->betweenIncluded($from, $to);
                                });
                                $isToday = $date->isToday();
                                $classes = ['crm-cal__day-btn'];
                                if ($isToday) $classes[] = 'is-today';
                                if (!$isWorkDay) $classes[] = 'is-weekend';
                                $dayTitle = $date->locale(app()->getLocale())->isoFormat('D MMMM YYYY, dddd');
                                $payload = [
                                    'title' => $dayTitle,
                                    'work' => ($isWorkDay && $schedule) ? (\Carbon\Carbon::parse($schedule->start_time)->format('H:i') . '–' . \Carbon\Carbon::parse($schedule->end_time)->format('H:i')) : null,
                                    'isWorkDay' => $isWorkDay,
                                    'leaves' => $dayLeaves->map(fn($lv) => ['type' => $crm_label('leave_type', $lv->type->value), 'status' => $crm_label('leave_status', $lv->status->value)])->values()->all(),
                                    'holidays' => $dayHolidays->map(fn($h) => ['name' => $h->name, 'kind' => $crm_label('holiday_kind', $h->kind->value)])->values()->all(),
                                ];
                            @endphp
                            <div class="{{ implode(' ', $classes) }}" role="button" tabindex="0"
                                 data-cal-day='@json($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG)'>
                                <span class="crm-cal__num">{{ $d }}</span>
                                @foreach($dayLeaves->take(2) as $lv)
                                    <span class="crm-cal__event crm-cal__event--leave" title="{{ $crm_label('leave_type', $lv->type->value) }}">{{ $crm_label('leave_type', $lv->type->value) }}</span>
                                @endforeach
                                @foreach($dayHolidays->take(2) as $h)
                                    <span class="crm-cal__event crm-cal__event--holiday" title="{{ $h->name }}">{{ \Illuminate\Support\Str::limit($h->name, 22) }}</span>
                                @endforeach
                                @if($dayLeaves->isEmpty() && $dayHolidays->isEmpty())
                                    @if($isWorkDay && $schedule)
                                        <span class="crm-cal__event crm-cal__event--work">{{ \Carbon\Carbon::parse($schedule->start_time)->format('H:i') }}–{{ \Carbon\Carbon::parse($schedule->end_time)->format('H:i') }}</span>
                                    @else
                                        <span class="crm-cal__event crm-cal__event--off">{{ __('Выходной') }}</span>
                                    @endif
                                @endif
                            </div>
                        @endfor
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-3 text-secondary small">
                    <span class="crm-cal__event crm-cal__event--work">{{ __('Рабочий день') }}</span>
                    <span class="crm-cal__event crm-cal__event--leave">{{ __('Отпуск/отгул') }}</span>
                    <span class="crm-cal__event crm-cal__event--holiday">{{ __('Праздник') }}</span>
                    <span class="crm-cal__event crm-cal__event--off">{{ __('Выходной') }}</span>
                </div>
            </div>
        </div>
    </div>
</div>


<div class="modal modal-blur fade" id="myScheduleDayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="myScheduleDayTitle">{{ __('День') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Закрыть') }}"></button>
            </div>
            <div class="modal-body" id="myScheduleDayBody"></div>
        </div>
    </div>
</div>
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var input = document.querySelector('[data-crm-view-input="my-schedule-view"]');
    var container = document.querySelector('[data-crm-view-container="my-schedule-view"]');
    if (input && container) {
        var sync = function () { input.value = container.getAttribute('data-view') || 'details'; };
        new MutationObserver(sync).observe(container, { attributes: true, attributeFilter: ['data-view'] });
        sync();
    }
    var modalEl = document.getElementById('myScheduleDayModal');
    var title = document.getElementById('myScheduleDayTitle');
    var body = document.getElementById('myScheduleDayBody');
    function openDay(el) {
        if (!modalEl) return;
        var raw = el.getAttribute('data-cal-day') || '{}';
        var d = {};
        try { d = JSON.parse(raw); } catch (e) { d = {}; }
        title.textContent = d.title || '{{ __('День') }}';
        body.innerHTML = '';
        function row(label, value, tone) {
            var r = document.createElement('div');
            r.className = 'd-flex align-items-start gap-3 p-3 rounded-3 border mb-2';
            var dot = document.createElement('span');
            dot.style.cssText = 'width:10px;height:10px;border-radius:50%;margin-top:6px;flex:0 0 10px;background:' + (tone || 'var(--crm-text-secondary)');
            var c = document.createElement('div');
            var n = document.createElement('div'); n.className='fw-semibold'; n.textContent = label || '';
            var m = document.createElement('div'); m.className='text-secondary small mt-1'; m.textContent = value || '';
            c.appendChild(n); if (value) c.appendChild(m);
            r.appendChild(dot); r.appendChild(c); body.appendChild(r);
        }
        if (d.work) row('{{ __('Рабочее время') }}', d.work, 'rgba(34,197,94,.9)');
        else row(d.isWorkDay ? '{{ __('Рабочий день') }}' : '{{ __('Выходной') }}', d.isWorkDay ? '{{ __('График не задан') }}' : '', d.isWorkDay ? 'rgba(34,197,94,.9)' : 'rgba(148,163,184,.9)');
        (d.holidays || []).forEach(function (h) { row(h.name || '{{ __('Праздник') }}', h.kind || '', 'var(--crm-danger)'); });
        (d.leaves || []).forEach(function (l) { row(l.type || '{{ __('Отпуск/отгул') }}', l.status || '', 'var(--crm-warning)'); });
        if (window.bootstrap && bootstrap.Modal) bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
    document.addEventListener('click', function (e) { var el = e.target.closest('[data-cal-day]'); if (el) openDay(el); });
    document.addEventListener('keydown', function (e) { if (e.key !== 'Enter' && e.key !== ' ') return; var el = e.target.closest('[data-cal-day]'); if (!el) return; e.preventDefault(); openDay(el); });
});
</script>
@endpush
@endsection
