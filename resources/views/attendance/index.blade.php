@extends('layouts.tabler')

@section('title', 'Посещаемость')

@php
    $statusIcons = [
        'on_time' => 'circle-check',
        'present' => 'circle-check',
        'late' => 'alarm',
        'absent' => 'circle-x',
        'early_leave' => 'arrow-exit-right',
        'overtime' => 'clock-up',
        'weekend' => 'bed',
        'holiday' => 'party-popper',
    ];
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Посещаемость</div>
            <h2 class="page-title mt-1">Сегодня · {{ now()->format('d.m.Y') }}</h2>
            <div class="text-secondary mt-1">Оперативный центр: отметки за день и поток событий</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('attendance.timesheet') }}"><i class="ti ti-table me-1"></i>Табель</a>
            <a class="btn btn-ghost-secondary me-1" href="{{ route('attendance.anomalies') }}"><i class="ti ti-alert-triangle me-1"></i>Аномалии</a>
            <a class="btn btn-ghost-secondary" href="{{ route('attendance.corrections') }}"><i class="ti ti-edit me-1"></i>Корректировки</a>
        </div>
    </div>
</div>

{{-- Метрики дня --}}
<div class="crm-metrics">
    @forelse ($statusCounts as $status => $count)
        <div class="crm-metric">
            <div class="crm-metric__label"><i class="ti ti-{{ $statusIcons[$status] ?? 'circle-dot' }}"></i>{{ $crm_label('attendance_status', $status) }}</div>
            <div class="crm-metric__value" data-count="{{ (int) $count }}">{{ (int) $count }}</div>
            <div class="crm-metric__hint">из {{ $records->count() }} отметок</div>
        </div>
    @empty
        <div class="crm-metric">
            <div class="crm-metric__label"><i class="ti ti-calendar-time"></i>Отметок сегодня</div>
            <div class="crm-metric__value">0</div>
            <div class="crm-metric__hint">по текущему scope</div>
        </div>
    @endforelse
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header">
                <strong>Табель за сегодня</strong>
                <span class="crm-chip ms-auto">{{ $records->count() }} записей</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-vcenter card-table mb-0">
                    <thead>
                        <tr><th>Сотрудник</th><th>Вход</th><th>Выход</th><th>Статус</th><th class="text-end">Отраб.</th><th class="text-end">Поздно</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($records as $record)
                            <tr>
                                <td>
                                    <div class="crm-table__main">{{ $record->employee->last_name }} {{ $record->employee->first_name }}</div>
                                    <div class="crm-table__sub">{{ $record->employee->employee_no }}</div>
                                </td>
                                <td class="crm-table-num">{{ $record->clock_in_at?->format('H:i') ?? '—' }}</td>
                                <td class="crm-table-num">{{ $record->clock_out_at?->format('H:i') ?? '—' }}</td>
                                <td>
                                    @php
                                        $st = $record->status->value;
                                        $stChip = match ($st) {
                                            'late' => 'crm-chip--orange',
                                            'absent' => 'crm-chip--red',
                                            'early_leave' => 'crm-chip--orange',
                                            'overtime' => 'crm-chip--cyan',
                                            'weekend', 'holiday' => 'crm-chip--grey',
                                            default => 'crm-chip--green',
                                        };
                                    @endphp
                                    <span class="crm-chip {{ $stChip }}">{{ $crm_label('attendance_status', $st) }}</span>
                                </td>
                                <td class="text-end crm-table-num">{{ $record->worked_minutes }} мин.</td>
                                <td class="text-end">
                                    @if ($record->late_minutes > 0)
                                        <span class="crm-chip crm-chip--red">{{ $record->late_minutes }} мин.</span>
                                    @else
                                        <span class="text-secondary">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6">@include('partials.empty-state', ['icon' => 'clock-exclamation', 'title' => 'Отметок нет', 'text' => 'Сегодня по вашему scope ещё нет отметок о приходе.'])</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card">
            <div class="card-header">
                <span class="d-flex align-items-center gap-2"><span class="crm-live-dot"></span><strong>Live: поток событий</strong></span>
                <span class="crm-chip ms-auto" id="live-counter">обновляется каждые 10 с</span>
            </div>
            <div class="card-body py-2">
                <div class="crm-timeline" id="live-events">
                    @forelse ($events as $event)
                        <div class="crm-timeline__item">
                            <div class="crm-timeline__time">{{ $event->occurred_at->format('H:i:s') }} · {{ $event->device?->name ?? 'устройство' }}</div>
                            <div class="crm-timeline__title">
                                {{ $event->employee?->last_name ?? 'НЕ ОПОЗНАН' }}
                                <span class="crm-chip {{ $event->direction === 'in' ? 'crm-chip--green' : 'crm-chip--grey' }} ms-1">{{ $event->direction }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="crm-timeline__item">
                            <div class="crm-timeline__text">Событий за сегодня нет</div>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const list = document.getElementById('live-events');
    const counter = document.getElementById('live-counter');
    if (list === null) { return; }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = String(text ?? '');
        return div.innerHTML;
    }

    async function refresh() {
        try {
            const response = await fetch('{{ route('attendance.liveFeed') }}', {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) { return; }

            const data = await response.json();

            list.innerHTML = '';
            if (data.events.length === 0) {
                list.innerHTML = '<div class="crm-timeline__item"><div class="crm-timeline__text">Событий за сегодня нет</div></div>';
            }
            for (const ev of data.events) {
                const item = document.createElement('div');
                item.className = 'crm-timeline__item';
                const chip = ev.direction === 'in' ? 'crm-chip--green' : 'crm-chip--grey';
                item.innerHTML = '<div class="crm-timeline__time">' + escapeHtml(ev.time) + ' · ' + escapeHtml(ev.device) + '</div>'
                    + '<div class="crm-timeline__title">' + escapeHtml(ev.employee)
                    + '<span class="crm-chip ' + chip + ' ms-1">' + escapeHtml(ev.direction) + '</span></div>';
                list.appendChild(item);
            }

            if (counter !== null) {
                counter.textContent = 'обновлено ' + data.generated_at;
            }
        } catch (e) {
            // тихо повторяем через 10 с
        }
    }

    setInterval(refresh, 10000);
    refresh();
})();
</script>
@endpush
@endsection
