@extends('layouts.tabler')

@section('title', 'RFID-карты')

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
            <div class="crm-eyebrow">Доступ</div>
            <h2 class="page-title mt-1">RFID-карты</h2>
            <div class="text-secondary mt-1">Пропуска сотрудников и журнал проходов на ридерах</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('biometrics.index') }}"><i class="ti ti-arrow-left me-1"></i>К устройствам</a>
            @if ($canManage)
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newCard">
                    <i class="ti ti-plus me-1"></i>Выдать карту
                </button>
            @endif
        </div>
    </div>
</div>

<div class="crm-metrics mb-3" data-crm-sec="metrics" data-crm-sec-label="Метрики">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #4299e1;"><i class="ti ti-credit-card"></i>Карт в системе</div>
        <div class="crm-metric__value">{{ $cards->count() }}</div>
        <div class="crm-metric__hint">{{ $cards->filter(fn ($c) => $c->status->value === 'active')->count() }} активных</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #0b9b72;"><i class="ti ti-door-enter"></i>Проходов в журнале</div>
        <div class="crm-metric__value">{{ $events->count() }}</div>
        <div class="crm-metric__hint">последние события ридеров</div>
    </div>
</div>

<div class="row g-3">
    {{-- ===== Карты ===== --}}
    <div class="col-xl-5" data-crm-sec="cards">
        <div class="crm-form__section">
            <h3>Карты <span class="text-secondary fw-normal">({{ $cards->count() }})</span></h3>
            <div class="crm-form__desc">Выданные пропуска, сроки действия и блокировки.</div>
            <div class="table-responsive">
                <table class="table table-hover table-vcenter card-table mb-0">
                    <thead><tr><th>UID</th><th>Сотрудник</th><th>Действ. до</th><th>Статус</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($cards as $card)
                            <tr>
                                <td>
                                    <code class="small">{{ $card->card_uid }}</code>
                                    <div class="crm-table__sub">{{ $card->label ?? 'без подписи' }}</div>
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
                                        <button class="btn btn-sm btn-ghost-danger" title="Отозвать"
                                                data-bs-toggle="modal" data-bs-target="#revokeCard-{{ $card->id }}">
                                            <i class="ti ti-ban"></i>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'credit-card', 'title' => 'Карт нет', 'text' => 'Выдайте первую карту — она появится в списке со статусом активна.'])</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ===== Журнал проходов ===== --}}
    <div class="col-xl-7" data-crm-sec="log">
        <div class="crm-form__section">
            <h3>Журнал проходов <span class="text-secondary fw-normal">({{ $events->count() }})</span></h3>
            <div class="crm-form__desc">События ридеров: карта, сотрудник, результат обработки.</div>
            <div class="table-responsive">
                <table class="table table-hover table-vcenter card-table mb-0">
                    <thead>
                        <tr><th>Время</th><th>Карта</th><th>Сотрудник</th><th>Напр.</th><th>Результат</th></tr>
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
                                        <span class="crm-chip crm-chip--orange">не опознан</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="crm-chip {{ $event->direction === 'in' ? 'crm-chip--green' : 'crm-chip--orange' }}">
                                        {{ $event->direction === 'in' ? 'вход' : 'выход' }}
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
                            <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'door-enter', 'title' => 'Событий нет', 'text' => 'Журнал наполнится после первых проходов по картам.'])</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
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
            <div class="modal-header"><h5 class="modal-title">Отозвать карту</h5></div>
            <div class="modal-body">
                Отозвать карту <code>{{ $card->card_uid }}</code> у {{ $card->employee->last_name }} {{ $card->employee->first_name }}?
                <div class="text-secondary small mt-2">Карта перестанет открывать двери.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-danger">Отозвать</button>
            </div>
        </form>
    </div>
</div>
@endforeach

<div class="modal modal-blur fade" id="newCard" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('rfid.issue') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Выдать карту</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>Кому</h3>
                    <div class="crm-form__desc">Сотрудник — владелец пропуска.</div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="rc-empl">Сотрудник<span class="req">*</span></label>
                        <select id="rc-empl" name="employee_id" class="form-select" required>
                            <option value="">— выбрать —</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->last_name }} {{ $employee->first_name }} ({{ $employee->employee_no }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>Карта</h3>
                    <div class="crm-form__desc">UID считывается с карты, подпись — внутренний номер.</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="rc-uid">UID карты<span class="req">*</span></label>
                        <input type="text" id="rc-uid" name="card_uid" class="form-control font-monospace" placeholder="04 A3 7B ..." required>
                    </div>
                    <div class="crm-form__row mb-0">
                        <div class="crm-form__field">
                            <label class="form-label" for="rc-label">Подпись</label>
                            <input type="text" id="rc-label" name="label" class="form-control" placeholder="пропуск №">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="rc-exp">Действ. до</label>
                            <input type="date" id="rc-exp" name="expires_at" class="form-control">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Выдать</button>
            </div>
        </form>
    </div>
</div>
@endif
@endsection
