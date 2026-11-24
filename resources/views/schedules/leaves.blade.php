@extends('layouts.tabler')
@section('workspace_layout', 'stream')

@section('title', __('Отпуска и отгулы'))

@php
    $statusChips = [
        'pending' => 'crm-chip--orange',
        'approved' => 'crm-chip--green',
        'rejected' => 'crm-chip--red',
        'cancelled' => 'crm-chip--grey',
    ];
    $typeIcons = [
        'vacation' => 'sun',
        'sick' => 'heartbeat',
        'business_leave' => 'briefcase',
        'day_off' => 'coffee',
        'other' => 'dots',
    ];
    $pending = $canApprove ? $leaves->getCollection()->filter(fn ($l) => $l->status->value === 'pending') : collect();
    $monthNames = [1=>__('Январь'),2=>__('Февраль'),3=>__('Март'),4=>__('Апрель'),5=>__('Май'),6=>__('Июнь'),7=>__('Июль'),8=>__('Август'),9=>__('Сентябрь'),10=>__('Октябрь'),11=>__('Ноябрь'),12=>__('Декабрь')];
    $viewMode = $viewMode ?? (in_array(request('view'), ['table','calendar'], true) ? request('view') : 'table');
    $month = (int) ($month ?? request('month', now()->month));
    $year = (int) ($year ?? request('year', now()->year));
    $monthStart = \Carbon\Carbon::create($year, $month, 1);
    $daysInMonth = $monthStart->daysInMonth;
    $firstWeekday = $monthStart->dayOfWeekIso;
    $calLeaves = collect($calendarLeaves ?? []);
    $leaveByDay = [];
    for ($d=1; $d <= $daysInMonth; $d++) {
        $date = \Carbon\Carbon::create($year, $month, $d)->startOfDay();
        $leaveByDay[$d] = $calLeaves->filter(function ($l) use ($date) {
            $from = \Carbon\Carbon::parse($l->from_date)->startOfDay();
            $to = \Carbon\Carbon::parse($l->to_date)->startOfDay();
            return $date->betweenIncluded($from, $to);
        })->values();
    }
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Отпуска') }}</div>
            <h1 class="page-title mt-1">{{ __('Отпуска и отгулы') }}</h1>
            <div class="text-secondary mt-1">{{ __('Заявки на отпуск, больничные и отгулы, по объёму') }} {{ $leaves->total() }}</div>
        </div>
        <div class="col-auto d-print-none d-flex gap-2 align-items-center">
            <div class="crm-switch" data-crm-switch="leaves-view">
                <button type="button" class="crm-switch__btn {{ $viewMode !== 'calendar' ? 'is-active' : '' }}" data-view="table"><i class="ti ti-table"></i> {{ __('Таблица') }}</button>
                <button type="button" class="crm-switch__btn {{ $viewMode === 'calendar' ? 'is-active' : '' }}" data-view="calendar"><i class="ti ti-calendar-month"></i> {{ __('Календарь') }}</button>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newLeave">
                <i class="ti ti-plus me-1"></i>{{ __('Оформить заявку') }}
            </button>
        </div>
    </div>
</div>

<div class="crm-metrics mb-3">
    <div class="crm-metric"><div class="crm-metric__label" style="color: var(--crm-text-secondary);"><i class="ti ti-file-text"></i>{{ __('Всего заявок') }}</div><div class="crm-metric__value">{{ $counts->sum() }}</div></div>
    <div class="crm-metric"><div class="crm-metric__label" style="color: var(--crm-warning);"><i class="ti ti-hourglass"></i>{{ __('Ждут решения') }}</div><div class="crm-metric__value">{{ $counts['pending'] ?? 0 }}</div></div>
    <div class="crm-metric"><div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-circle-check"></i>{{ __('Одобрено') }}</div><div class="crm-metric__value">{{ $counts['approved'] ?? 0 }}</div></div>
    <div class="crm-metric"><div class="crm-metric__label" style="color: var(--crm-danger);"><i class="ti ti-circle-x"></i>{{ __('Отклонено') }}</div><div class="crm-metric__value">{{ $counts['rejected'] ?? 0 }}</div></div>
</div>

@if ($canApprove && $pending->isNotEmpty())
    <div class="crm-form__section">
        <h3>{{ __('Ждут согласования') }} <span class="crm-chip crm-chip--orange ms-1">{{ $pending->count() }}</span></h3>
        <div class="crm-form__desc">{{ __('Заявки, ждущие решения. Примите решение в один клик.') }}</div>
        <div class="crm-queue">
            @foreach ($pending as $leave)
                <div class="crm-queue__item" style="--c: var(--crm-warning);">
                    <span class="crm-entity__logo" style="width:38px;height:38px;font-size:15px;border-radius:11px;background:color-mix(in srgb, var(--crm-warning) 12%, transparent);color:var(--crm-warning);"><i class="ti ti-{{ $typeIcons[$leave->type->value] ?? 'dots' }}"></i></span>
                    <div class="crm-queue__body">
                        <div class="crm-queue__title">{{ $leave->employee->last_name }} {{ $leave->employee->first_name }} <span class="crm-chip crm-chip--grey ms-1">{{ $crm_label('leave_type', $leave->type->value) }}</span></div>
                        <div class="crm-queue__sub">{{ $leave->from_date->format('d.m.Y') }} — {{ $leave->to_date->format('d.m.Y') }} · {{ (int) $leave->from_date->diffInDays($leave->to_date) + 1 }} {{ __('дн.') }} @if ($leave->reason) · «{{ Str::limit($leave->reason, 60) }}»@endif</div>
                    </div>
                    <form method="post" action="{{ route('leaves.decide', $leave) }}" class="d-flex gap-1" data-submit-guard>@csrf @method('PUT')<input type="hidden" name="decision" value="approved"><button class="btn btn-sm btn-success" title="{{ __("Одобрить") }}"><i class="ti ti-check me-1"></i>{{ __('Одобрить') }}</button></form>
                    <form method="post" action="{{ route('leaves.decide', $leave) }}" class="d-flex" data-submit-guard>@csrf @method('PUT')<input type="hidden" name="decision" value="rejected"><button class="btn btn-sm btn-ghost-danger" title="{{ __("Отклонить") }}"><i class="ti ti-x"></i></button></form>
                </div>
            @endforeach
        </div>
    </div>
@endif

<form method="get" class="crm-toolbar" id="leavesFilter">
    <input type="hidden" name="view" value="{{ $viewMode }}" data-crm-view-input="leaves-view">
    <select name="status" class="form-select" aria-label="{{ __("Статус") }}" onchange="this.form.submit()">
        <option value="">{{ __('Все статусы') }}</option>
        @foreach (['pending', 'approved', 'rejected', 'cancelled'] as $statusOption)
            <option value="{{ $statusOption }}" @selected($statusFilter === $statusOption)>{{ $crm_label('leave_status', $statusOption) }}</option>
        @endforeach
    </select>
    <label class="form-label mb-0 ms-2 me-1" for="lv-month">{{ __('Месяц') }}</label>
    <select id="lv-month" name="month" class="form-select" onchange="this.form.submit()">
        @for($m=1;$m<=12;$m++)<option value="{{ $m }}" @selected($m===$month)>{{ $monthNames[$m] }}</option>@endfor
    </select>
    <input type="number" name="year" value="{{ $year }}" class="form-control" style="width:6.5rem" min="2020" max="2100" onchange="this.form.submit()">
    <div class="crm-toolbar__spacer"></div>
    <span class="text-secondary small">{{ __('Показано') }} {{ $leaves->count() }} {{ __('из') }} {{ $leaves->total() }}</span>
</form>

<div data-crm-view-container="leaves-view" data-view-default="{{ $viewMode }}">
    <div data-view-only="table" @if($viewMode==='calendar') hidden @endif>
        <div class="card">
            <div class="table-responsive">
                <table data-record-key="0" class="table table-hover table-vcenter card-table crm-thead-sticky">
                    <thead><tr><th scope="col">{{ __('Сотрудник') }}</th><th scope="col">{{ __('Тип') }}</th><th scope="col">{{ __('Период') }}</th><th scope="col">{{ __('Дней') }}</th><th scope="col">{{ __('Статус') }}</th><th scope="col">{{ __('Решил') }}</th><th scope="col"></th></tr></thead>
                    <tbody>
                        @forelse ($leaves as $leave)
                            <tr>
                                <td>
                                    <span class="crm-table__main">{{ $leave->employee->last_name }} {{ $leave->employee->first_name }}</span>
                                    <div class="crm-table__sub">{{ $leave->employee->employee_no ?? '' }}</div>
                                </td>
                                <td><span class="crm-chip crm-chip--cyan"><i class="ti ti-{{ $typeIcons[$leave->type->value] ?? 'dots' }} me-1"></i>{{ $crm_label('leave_type', $leave->type->value) }}</span></td>
                                <td class="crm-table-num">{{ $leave->from_date->format('d.m.Y') }} — {{ $leave->to_date->format('d.m.Y') }}</td>
                                <td class="crm-table-num">{{ (int) $leave->from_date->diffInDays($leave->to_date) + 1 }}</td>
                                <td><span class="crm-chip {{ $statusChips[$leave->status->value] ?? 'crm-chip--grey' }}">{{ $crm_label('leave_status', $leave->status->value) }}</span></td>
                                <td class="text-secondary">{{ $leave->approver?->last_name ?? '—' }}@if ($leave->decided_at)<div class="crm-table__sub">{{ $leave->decided_at->format('d.m H:i') }}</div>@endif</td>
                                <td class="text-end">
                                    @if ($canApprove && $leave->status->value === 'pending')
                                        <form method="post" action="{{ route('leaves.decide', $leave) }}" class="d-inline" data-submit-guard>@csrf @method('PUT')<input type="hidden" name="decision" value="approved"><button class="btn btn-sm btn-ghost-success" title="{{ __("Одобрить") }}"><i class="ti ti-check"></i></button></form>
                                        <form method="post" action="{{ route('leaves.decide', $leave) }}" class="d-inline" data-submit-guard>@csrf @method('PUT')<input type="hidden" name="decision" value="rejected"><button class="btn btn-sm btn-ghost-danger" title="{{ __("Отклонить") }}"><i class="ti ti-x"></i></button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7">@include('partials.empty-state', ['icon' => 'calendar-off', 'title' => __('Заявок нет'), 'text' => __('Оформите заявку — она появится после отправки руководителю.')])</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($leaves->hasPages())
                <div class="card-footer d-flex align-items-center">
                    <div class="text-secondary me-auto">{{ __('Страница') }} {{ $leaves->currentPage() }} {{ __('из') }} {{ $leaves->lastPage() }}</div>
                    {{ $leaves->links('pagination::tabler') }}
                </div>
            @endif
        </div>
    </div>

    <div data-view-only="calendar" @if($viewMode!=='calendar') hidden @endif>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title bm-section-title">{{ __('Календарь отпусков') }}</h2>
                <span class="crm-chip ms-auto">{{ $monthNames[$month] }} {{ $year }}</span>
            </div>
            <div class="card-body">
                @php
                    $calStart = \Carbon\Carbon::create($year, $month, 1)->startOfMonth();
                    $calFirstWeekday = $calStart->dayOfWeekIso;
                    $calDays = $calStart->daysInMonth;
                @endphp
                <div class="crm-cal">
                    <div class="crm-cal__grid">
                        @foreach ([__('Пн'),__('Вт'),__('Ср'),__('Чт'),__('Пт'),__('Сб'),__('Вс')] as $dow)
                            <div class="crm-cal__dow">{{ $dow }}</div>
                        @endforeach
                        @for ($i = 1; $i < $calFirstWeekday; $i++)
                            <div class="is-out"></div>
                        @endfor
                        @for ($d = 1; $d <= $calDays; $d++)
                            @php
                                $date = \Carbon\Carbon::create($year, $month, $d);
                                $items = $calendarLeaves->filter(function ($l) use ($date) {
                                    $from = \Carbon\Carbon::parse($l->from_date)->startOfDay();
                                    $to = \Carbon\Carbon::parse($l->to_date)->startOfDay();
                                    return $date->copy()->startOfDay()->betweenIncluded($from, $to);
                                });
                                $payload = [
                                    'title' => $date->locale(app()->getLocale())->isoFormat('D MMMM YYYY, dddd'),
                                    'items' => $items->map(fn($l) => [
                                        'name' => trim(($l->employee->last_name ?? '') . ' ' . ($l->employee->first_name ?? '')),
                                        'type' => $crm_label('leave_type', $l->type->value),
                                        'status' => $crm_label('leave_status', $l->status->value),
                                        'statusValue' => $l->status->value,
                                        'period' => $l->from_date->format('d.m.Y') . ' – ' . $l->to_date->format('d.m.Y'),
                                    ])->values()->all(),
                                ];
                                $classes = ['crm-cal__day-btn'];
                                if ($date->isToday()) $classes[] = 'is-today';
                                if ($date->isWeekend()) $classes[] = 'is-weekend';
                            @endphp
                            <div class="{{ implode(' ', $classes) }}" role="button" tabindex="0"
                                 data-leave-day='@json($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG)'>
                                <span class="crm-cal__num">{{ $d }}</span>
                                @foreach ($items->take(3) as $l)
                                    @php $sv = $l->status->value; $tone = $sv === 'approved' ? 'crm-cal__event--work' : ($sv === 'pending' ? 'crm-cal__event--leave' : 'crm-cal__event--off'); @endphp
                                    <span class="crm-cal__event {{ $tone }}" title="{{ $l->employee->last_name }} — {{ $crm_label('leave_type', $l->type->value) }}">{{ \Illuminate\Support\Str::limit($l->employee->last_name, 12) }}</span>
                                @endforeach
                                @if($items->count() > 3)
                                    <span class="crm-cal__event crm-cal__event--off">+{{ $items->count() - 3 }}</span>
                                @endif
                            </div>
                        @endfor
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-3 text-secondary small">
                    <span class="crm-cal__event crm-cal__event--work">{{ __('Одобрено') }}</span>
                    <span class="crm-cal__event crm-cal__event--leave">{{ __('Ожидает') }}</span>
                    <span class="crm-cal__event crm-cal__event--off">{{ __('Другое') }}</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal modal-blur fade" id="leaveDayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="leaveDayTitle">{{ __('День') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Закрыть') }}"></button>
            </div>
            <div class="modal-body" id="leaveDayBody"></div>
        </div>
    </div>
</div>

<div class="modal modal-blur fade" id="newLeave" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('leaves.request') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Заявка на отпуск') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Период') }}</h3>
                    <div class="crm-form__desc">{{ __('Даты начала и конца — включительно.') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field"><label class="form-label" for="nl-from">{{ __('С') }}<span class="req">*</span></label><input type="date" id="nl-from" class="form-control" name="from_date" required></div>
                        <div class="crm-form__field"><label class="form-label" for="nl-to">{{ __('По') }}<span class="req">*</span></label><input type="date" id="nl-to" class="form-control" name="to_date" required></div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Тип заявки') }}</h3>
                    <div class="crm-form__field">
                        <label class="form-label" for="nl-type">{{ __('Тип') }}<span class="req">*</span></label>
                        <select id="nl-type" class="form-select" name="type" required>
                            @foreach ($types as $type)
                                <option value="{{ $type }}">{{ $crm_label('leave_type', $type) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="nl-reason">{{ __('Комментарий') }}</label>
                        <textarea id="nl-reason" class="form-control" name="reason" rows="4" data-crm-rich placeholder="{{ __("Причина заявки") }}"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary"><i class="ti ti-send me-1"></i>{{ __('Отправить заявку') }}</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var input = document.querySelector('[data-crm-view-input="leaves-view"]');
    var container = document.querySelector('[data-crm-view-container="leaves-view"]');
    if (input && container) {
        var sync = function () { input.value = container.getAttribute('data-view') || 'table'; };
        new MutationObserver(sync).observe(container, { attributes: true, attributeFilter: ['data-view'] });
        sync();
    }
    var modalEl = document.getElementById('leaveDayModal');
    var title = document.getElementById('leaveDayTitle');
    var body = document.getElementById('leaveDayBody');
    function openDay(el) {
        if (!modalEl) return;
        var raw = el.getAttribute('data-leave-day') || '{}';
        var d = {};
        try { d = JSON.parse(raw); } catch (e) { d = {}; }
        title.textContent = d.title || '{{ __('День') }}';
        body.innerHTML = '';
        var items = d.items || [];
        if (!items.length) {
            var empty = document.createElement('div');
            empty.className = 'text-secondary';
            empty.textContent = '{{ __('В этот день заявок нет.') }}';
            body.appendChild(empty);
        } else {
            items.forEach(function (it) {
                var row = document.createElement('div');
                row.className = 'd-flex align-items-start gap-3 p-3 rounded-3 border mb-2';
                var dot = document.createElement('span');
                var tone = it.statusValue === 'approved' ? 'rgba(34,197,94,.9)' : (it.statusValue === 'pending' ? 'var(--crm-warning)' : 'rgba(148,163,184,.9)');
                dot.style.cssText = 'width:10px;height:10px;border-radius:50%;margin-top:6px;flex:0 0 10px;background:' + tone;
                var c = document.createElement('div');
                var n = document.createElement('div'); n.className = 'fw-semibold'; n.textContent = it.name || '';
                var m = document.createElement('div'); m.className = 'text-secondary small mt-1';
                m.textContent = [it.type, it.status, it.period].filter(Boolean).join(' · ');
                c.appendChild(n); c.appendChild(m);
                row.appendChild(dot); row.appendChild(c);
                body.appendChild(row);
            });
        }
        if (window.bootstrap && bootstrap.Modal) bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
    document.addEventListener('click', function (e) { var el = e.target.closest('[data-leave-day]'); if (el) openDay(el); });
    document.addEventListener('keydown', function (e) { if (e.key !== 'Enter' && e.key !== ' ') return; var el = e.target.closest('[data-leave-day]'); if (!el) return; e.preventDefault(); openDay(el); });
});
</script>
@endpush
@endsection
