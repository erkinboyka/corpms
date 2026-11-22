@extends('layouts.tabler')
@section('workspace_layout', 'stream')

@section('title', __('RFID-карты'))

@php
    $procChips = [
        'processed' => 'crm-chip--green',
        'failed' => 'crm-chip--red',
        'skipped' => 'crm-chip--grey',
    ];
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Доступ') }}</div>
            <h1 class="page-title mt-1">{{ __('RFID-карты') }}</h1>
            <div class="text-secondary mt-1">{{ __('Пропуска сотрудников и журнал проходов на ридерах') }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('biometrics.index') }}"><i class="ti ti-arrow-left me-1"></i>{{ __('К устройствам') }}</a>
            @if ($canManage)
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newCard">
                    <i class="ti ti-plus me-1"></i>{{ __('Выдать карту') }}
                </button>
            @endif
        </div>
    </div>
</div>

<div class="crm-metrics mb-3" data-crm-sec="metrics" data-crm-sec-label="{{ __("Метрики") }}">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-info);"><i class="ti ti-credit-card"></i>{{ __('Карт в системе') }}</div>
        <div class="crm-metric__value">{{ $cards->total() }}</div>
        <div class="crm-metric__hint">{{ $activeCardsCount }} {{ __('активных') }}</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: var(--crm-success);"><i class="ti ti-door-enter"></i>{{ __('Проходов в журнале') }}</div>
        <div class="crm-metric__value">{{ $events->total() }}</div>
        <div class="crm-metric__hint">{{ __('последние события ридеров') }}</div>
    </div>
</div>

<div class="row g-3">
    {{-- ===== Карты ===== --}}
    <div class="col-xl-5" data-crm-sec="cards">
        <div class="crm-form__section">
            <h3>{{ __('Карты') }} <span class="text-secondary fw-normal">({{ $cards->total() }})</span></h3>
            <div class="crm-form__desc">{{ __('Выданные пропуска, сроки действия и блокировки') }}</div>
            <div class="table-responsive">
                <table data-record-key="0" class="table table-hover table-vcenter card-table mb-0">
                    <thead><tr><th scope="col">UID</th><th scope="col">{{ __('Сотрудник') }}</th><th scope="col">{{ __('Действ. до') }}</th><th scope="col">{{ __('Статус') }}</th><th scope="col"></th></tr></thead>
                    <tbody>
                        @forelse ($cards as $card)
                            <tr>
                                <td>
                                    <code class="small">{{ $card->card_uid }}</code>
                                    <div class="crm-table__sub">{{ $card->label ?? __('без подписи') }}</div>
                                </td>
                                <td>
                                    <span class="crm-table__main">{{ $card->employee->last_name }} {{ $card->employee->first_name }}</span>
                                    <div class="crm-table__sub">{{ $card->employee->employee_no ?? '' }}</div>
                                </td>
                                <td class="text-secondary crm-table-num">{{ $card->expires_at?->format('d.m.Y') ?? '∞' }}</td>
                                <td>
                                    <span class="crm-chip {{ $card->status->value === 'active' ? 'crm-chip--green' : 'crm-chip--grey' }}">
                                        {{ $crm_label('rfid_card_status', $card->status->value) }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    @if ($canManage && $card->status->value === 'active')
                                        <button class="btn btn-sm btn-ghost-danger" title="{{ __("Отозвать") }}"
                                                data-bs-toggle="modal" data-bs-target="#revokeCard-{{ $card->id }}">
                                            <i class="ti ti-ban"></i>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'credit-card', 'title' => __('Карт нет'), 'text' => __('Выдайте первую карту — она появится в списке со статусом активна.')])</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-2">{{ $cards->links() }}</div>
        </div>
    </div>

    {{-- ===== Журнал проходов ===== --}}
    <div class="col-xl-7" data-crm-sec="log">
        <div class="crm-form__section">
            <h3>{{ __('Журнал проходов') }} <span class="text-secondary fw-normal">({{ $events->total() }})</span></h3>
            <div class="crm-form__desc">{{ __('События ридеров: карта, сотрудник, результат обработки') }}</div>
            <div class="table-responsive">
                <table data-record-key="0" class="table table-hover table-vcenter card-table mb-0">
                    <thead>
                        <tr><th scope="col">{{ __('Время') }}</th><th scope="col">{{ __('Карта') }}</th><th scope="col">{{ __('Сотрудник') }}</th><th scope="col">{{ __('Напр') }}</th><th scope="col">{{ __('Результат') }}</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($events as $event)
                            <tr>
                                <td class="crm-table-num text-secondary">{{ $event->occurred_at->format('d.m H:i:s') }}</td>
                                <td><code class="small">{{ $event->raw_payload['card_uid'] ?? '—' }}</code></td>
                                <td>
                                    @if ($event->employee)
                                        <span class="crm-table__main">{{ $event->employee->last_name }} {{ $event->employee->first_name }}</span>
                                    @else
                                        <span class="crm-chip crm-chip--orange">{{ __('не опознан') }}</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="crm-chip {{ $event->direction === 'in' ? 'crm-chip--green' : 'crm-chip--orange' }}">
                                        {{ $event->direction === 'in' ? __('вход') : __('выход') }}
                                    </span>
                                </td>
                                <td>
                                    <span class="crm-chip {{ $procChips[$event->processing_status->value] ?? 'crm-chip--cyan' }}">{{ $crm_label('biometric_event_status', $event->processing_status->value) }}</span>
                                    @if ($event->failure_reason)
                                        <div class="text-danger small mt-1">{{ $event->failure_reason }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'door-enter', 'title' => __('Событий нет'), 'text' => __('Журнал наполнится после первых проходов по картам.')])</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-2">{{ $events->links() }}</div>
        </div>
    </div>
</div>

{{-- ===== Отзыв карты ===== --}}
@if ($canManage)
@foreach ($cards as $card)
<div class="modal modal-blur fade" id="revokeCard-{{ $card->id }}" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-sm" role="document">
        <form class="modal-content" method="post" action="{{ route('rfid.revoke', $card) }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Отозвать карту') }}</h5></div>
            <div class="modal-body">
                {{ __('Отозвать карту') }} <code>{{ $card->card_uid }}</code> {{ __('у') }} {{ $card->employee->last_name }} {{ $card->employee->first_name }}?
                <div class="text-secondary small mt-2">{{ __('Карта перестанет открывать двери') }}</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-danger">{{ __('Отозвать') }}</button>
            </div>
        </form>
    </div>
</div>
@endforeach

<div class="modal modal-blur fade" id="newCard" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('rfid.issue') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">{{ __('Выдать карту') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>{{ __('Кому') }}</h3>
                    <div class="crm-form__desc">{{ __('Сотрудник — владелец пропуска') }}</div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="rc-empl">{{ __('Сотрудник') }}<span class="req">*</span></label>
                        <select id="rc-empl" name="employee_id" class="form-select" required>
                            <option value="">{{ __('выбрать') }}</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->last_name }} {{ $employee->first_name }} ({{ $employee->employee_no }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>{{ __('Карта') }}</h3>
                    <div class="crm-form__desc">{{ __('UID считывается с карты, подпись — внутренний номер') }}</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="rc-uid">{{ __('UID карты') }}<span class="req">*</span></label>
                        <input type="text" id="rc-uid" name="card_uid" class="form-control font-monospace" placeholder="04 A3 7B ..." required>
                    </div>
                    <div class="crm-form__row mb-0">
                        <div class="crm-form__field">
                            <label class="form-label" for="rc-label">{{ __('Подпись') }}</label>
                            <input type="text" id="rc-label" name="label" class="form-control" placeholder="{{ __("пропуск №") }}">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="rc-exp">{{ __('Действ. до') }}</label>
                            <input type="date" id="rc-exp" name="expires_at" class="form-control">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Выдать') }}</button>
            </div>
        </form>
    </div>
</div>
@endif
@endsection
