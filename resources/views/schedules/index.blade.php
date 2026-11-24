@extends('layouts.tabler')
@section('workspace_layout', 'planning')

@section('title', __('Графики работы'))

@php
    $typeChips = ['standard' => 'crm-chip--cyan', 'shift' => 'crm-chip--orange', 'flexible' => 'crm-chip'];
    $typeIcons = ['standard' => 'clock', 'shift' => 'arrows-exchange', 'flexible' => 'relax'];
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Планирование') }}</div>
            <h1 class="page-title mt-1">{{ __('Графики работы') }}</h1>
            <div class="text-secondary mt-1">{{ __('Шаблоны рабочего времени') }} {{ $schedules->count() }}</div>
        </div>
        @if ($canManage)
        <div class="col-auto d-print-none">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newSchedule">
                <i class="ti ti-plus me-1"></i>{{ __('Добавить график') }}
            </button>
        </div>
        @endif
    </div>
</div>

<div class="row g-3">
    @forelse ($schedules as $schedule)
        <div class="col-md-6 col-xl-4">
            <div class="crm-entity h-100">
                <div class="crm-entity__logo" style="background: color-mix(in srgb, var(--crm-info) 12%, transparent); color: var(--crm-info);">
                    <i class="ti ti-{{ $typeIcons[$schedule->type->value] ?? 'clock' }}"></i>
                </div>
                <div class="crm-entity__body">
                    <div class="d-flex align-items-center gap-1 flex-wrap mb-1">
                        <span class="crm-chip {{ $typeChips[$schedule->type->value] ?? 'crm-chip--grey' }}">{{ $crm_label('schedule_type', $schedule->type->value) }}</span>
                        <span class="crm-chip crm-chip--grey">{{ __('допуск') }} {{ $schedule->grace_minutes ?? 10 }} {{ __('мин') }}</span>
                    </div>
                    <div class="crm-entity__title">{{ $schedule->name }}</div>
                    <div class="fw-bold mt-1" style="font-size: 1.25rem; letter-spacing: -.01em; font-variant-numeric: tabular-nums;">
                        {{ \Carbon\Carbon::parse($schedule->start_time)->format('H:i') }}
                        <span class="text-secondary fw-normal mx-1">–</span>
                        {{ \Carbon\Carbon::parse($schedule->end_time)->format('H:i') }}
                    </div>
                    <div class="crm-entity__meta">
                        @if ($schedule->break_start !== null)
                            <i class="ti ti-coffee me-1"></i>{{ __('обед') }} {{ \Carbon\Carbon::parse($schedule->break_start)->format('H:i') }}–{{ \Carbon\Carbon::parse($schedule->break_end)->format('H:i') }}
                        @else
                            <i class="ti ti-coffee me-1"></i>{{ __('без обеденного перерыва') }}
                        @endif
                    </div>
                    <div class="crm-entity__footer">
                        <span class="text-secondary small"><i class="ti ti-bed me-1"></i>{{ __('отдых ≥') }} {{ $schedule->min_rest_hours ?? 8 }} {{ __('ч') }}</span>
                        @if ($canManage)
                            <span class="d-flex gap-1">
                                <button class="btn btn-sm btn-ghost-secondary" title="{{ __("Изменить график") }}" data-bs-toggle="modal" data-bs-target="#editSchedule-{{ $schedule->id }}"><i class="ti ti-pencil"></i></button>
                                <form method="post" action="{{ route('schedules.destroy', $schedule) }}" data-confirm="{{ __('Удалить график') }} «{{ $schedule->name }}»? {{ __('Допустимо только без назначений сотрудникам') }}." data-confirm-danger="1" data-submit-guard>
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-ghost-danger" title="{{ __("Удалить график") }}"><i class="ti ti-trash"></i></button>
                                </form>
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            @include('partials.empty-state', ['icon' => 'clock', 'title' => __('Графиков пока нет'), 'text' => __('Создайте шаблон рабочего времени — его можно назначать сотрудникам.')])
        </div>
    @endforelse
</div>

@if ($canManage)
{{-- ===== Новый график ===== --}}
<div class="modal modal-blur fade" id="newSchedule" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('schedules.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Добавить график') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Суть графика') }}</h3>
                    <div class="crm-form__desc">{{ __('Название и тип — как смена будет назначаться') }}</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ns-name">{{ __('Название') }}<span class="req">*</span></label>
                        <input type="text" id="ns-name" name="name" class="form-control" placeholder="{{ __("Стандарт 08:00–17:00") }}" required>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="ns-type">{{ __('Тип') }}</label>
                        <select id="ns-type" class="form-select" name="type">
                            @foreach (['standard', 'shift', 'flexible'] as $type)
                                <option value="{{ $type }}">{{ $crm_label('schedule_type', $type) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Рабочее время') }}</h3>
                    <div class="crm-form__desc">{{ __('Часы смены, обед и правила для опозданий') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ns-start">{{ __('Начало') }}</label>
                            <input type="time" id="ns-start" class="form-control" name="start_time" value="08:00" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ns-end">{{ __('Конец') }}</label>
                            <input type="time" id="ns-end" class="form-control" name="end_time" value="17:00" required>
                        </div>
                    </div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ns-bs">{{ __('Обед с') }}</label>
                            <input type="time" id="ns-bs" class="form-control" name="break_start" value="12:00">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ns-be">{{ __('Обед до') }}</label>
                            <input type="time" id="ns-be" class="form-control" name="break_end" value="13:00">
                        </div>
                    </div>
                    <div class="crm-form__row mb-0">
                        <div class="crm-form__field">
                            <label class="form-label" for="ns-grace">{{ __('Допуск на опоздание (мин)') }}</label>
                            <input type="number" id="ns-grace" class="form-control" min="0" name="grace_minutes" value="10">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ns-rest">{{ __('Мин. отдых между сменами (ч)') }}</label>
                            <input type="number" id="ns-rest" class="form-control" min="0" max="24" name="min_rest_hours" value="8">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Создать график') }}</button>
            </div>
        </form>
    </div>
</div>

{{-- правка — модалка на каждый график --}}
@foreach ($schedules as $schedule)
<div class="modal modal-blur fade" id="editSchedule-{{ $schedule->id }}" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('schedules.update', $schedule) }}" data-submit-guard>
            @csrf @method('PATCH')
            <div class="modal-header"><h5 class="modal-title">{{ __('Правка графика') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Суть графика') }}</h3>
                    <div class="crm-form__desc">{{ __('Название и тип — как смена будет назначаться') }}</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="es{{ $schedule->id }}-name">{{ __('Название') }}<span class="req">*</span></label>
                        <input type="text" id="es{{ $schedule->id }}-name" name="name" class="form-control" value="{{ $schedule->name }}" required>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="es{{ $schedule->id }}-type">{{ __('Тип') }}</label>
                        <select id="es{{ $schedule->id }}-type" class="form-select" name="type">
                            @foreach (['standard', 'shift', 'flexible'] as $type)
                                <option value="{{ $type }}" @selected($schedule->type->value === $type)>{{ $crm_label('schedule_type', $type) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Рабочее время') }}</h3>
                    <div class="crm-form__desc">{{ __('Часы смены, обед и правила для опозданий') }}</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="es{{ $schedule->id }}-start">{{ __('Начало') }}</label>
                            <input type="time" id="es{{ $schedule->id }}-start" class="form-control" name="start_time" value="{{ \Carbon\Carbon::parse($schedule->start_time)->format('H:i') }}" required>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="es{{ $schedule->id }}-end">{{ __('Конец') }}</label>
                            <input type="time" id="es{{ $schedule->id }}-end" class="form-control" name="end_time" value="{{ \Carbon\Carbon::parse($schedule->end_time)->format('H:i') }}" required>
                        </div>
                    </div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="es{{ $schedule->id }}-bs">{{ __('Обед с') }}</label>
                            <input type="time" id="es{{ $schedule->id }}-bs" class="form-control" name="break_start" value="{{ $schedule->break_start !== null ? \Carbon\Carbon::parse($schedule->break_start)->format('H:i') : '' }}">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="es{{ $schedule->id }}-be">{{ __('Обед до') }}</label>
                            <input type="time" id="es{{ $schedule->id }}-be" class="form-control" name="break_end" value="{{ $schedule->break_end !== null ? \Carbon\Carbon::parse($schedule->break_end)->format('H:i') : '' }}">
                        </div>
                    </div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <input type="hidden" name="allow_overtime" value="0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="allow_overtime" value="1" id="es{{ $schedule->id }}-overtime" @checked($schedule->allow_overtime)>
                                <label class="form-check-label" for="es{{ $schedule->id }}-overtime">{{ __('Разрешить сверхурочные') }}</label>
                            </div>
                        </div>
                        <div class="crm-form__field">
                            <input type="hidden" name="is_active" value="0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="es{{ $schedule->id }}-active" @checked($schedule->is_active)>
                                <label class="form-check-label" for="es{{ $schedule->id }}-active">{{ __('График активен') }}</label>
                            </div>
                        </div>
                    </div>
                    <div class="crm-form__row mb-0">
                        <div class="crm-form__field">
                            <label class="form-label" for="es{{ $schedule->id }}-grace">{{ __('Допуск на опоздание (мин)') }}</label>
                            <input type="number" id="es{{ $schedule->id }}-grace" class="form-control" min="0" name="grace_minutes" value="{{ $schedule->grace_minutes ?? 10 }}">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="es{{ $schedule->id }}-rest">{{ __('Мин. отдых между сменами (ч)') }}</label>
                            <input type="number" id="es{{ $schedule->id }}-rest" class="form-control" min="0" max="24" name="min_rest_hours" value="{{ $schedule->min_rest_hours ?? 8 }}">
                        </div>
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
@endif
@endsection

@push('scripts')
<script>
(function () {
    // ScheduleController ждёт H:i:s, а <input type="time"> отдаёт H:i —
    // дополняем секунды перед отправкой (повторный падинг безопасен: 8 символов не трогаем).
    function padTimeValues(form) {
        var inputs = form.querySelectorAll('input[type="time"]');
        for (var i = 0; i < inputs.length; i++) {
            if (/^\d{2}:\d{2}$/.test(inputs[i].value)) {
                inputs[i].value = inputs[i].value + ':00';
            }
        }
    }

    var forms = document.querySelectorAll('form');
    for (var j = 0; j < forms.length; j++) {
        if (forms[j].querySelector('input[type="time"]') !== null) {
            forms[j].addEventListener('submit', function () {
                padTimeValues(this);
            });
        }
    }
})();
</script>
@endpush
