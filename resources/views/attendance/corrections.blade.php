@extends('layouts.tabler')

@section('title', 'Корректировки посещаемости')

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Посещаемость</div>
            <h2 class="page-title mt-1">Корректировки</h2>
            <div class="text-secondary mt-1">Заявки на исправление отметок: история изменений и согласование</div>
        </div>
        <div class="col-auto d-print-none">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newCorrection"><i class="ti ti-plus me-1"></i>Подать заявку</button>
        </div>
    </div>
</div>

@php
    $pendingCount = $corrections->filter(fn ($c) => $c->status->value === 'pending')->count();
    $approvedCount = $corrections->filter(fn ($c) => $c->status->value === 'approved')->count();
    $rejectedCount = $corrections->filter(fn ($c) => $c->status->value === 'rejected')->count();
@endphp

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-hourglass"></i>Ожидают решения</div>
        <div class="crm-metric__value">{{ $pendingCount }}</div>
        <div class="crm-metric__hint">требуют согласования</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-circle-check"></i>Согласовано</div>
        <div class="crm-metric__value">{{ $approvedCount }}</div>
        <div class="crm-metric__hint">исправления применены</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-circle-x"></i>Отклонено</div>
        <div class="crm-metric__value">{{ $rejectedCount }}</div>
        <div class="crm-metric__hint">остались без изменений</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label"><i class="ti ti-list"></i>Всего заявок</div>
        <div class="crm-metric__value">{{ $corrections->count() }}</div>
        <div class="crm-metric__hint">за всё время</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <strong>Заявки</strong>
        <span class="crm-chip ms-auto">{{ $corrections->count() }} всего</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-vcenter card-table mb-0">
            <thead>
                <tr>
                    <th>Заявка</th><th>Сотрудник</th><th>Дата</th><th>Поле</th><th>Было → станет</th><th>Причина</th><th>Статус</th>
                    @if ($canDecide) <th class="text-end">Решение</th> @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($corrections as $c)
                    @php
                        $fieldLabel = $c->field === 'clock_in_at' ? 'Приход' : 'Уход';
                        $statusChip = match ($c->status->value) {
                            'approved' => 'crm-chip--green',
                            'rejected' => 'crm-chip--red',
                            default => 'crm-chip--orange',
                        };
                    @endphp
                    <tr>
                        <td class="text-secondary crm-table-num">#{{ $c->id }}</td>
                        <td>
                            <div class="crm-table__main">{{ $c->employee?->last_name }} {{ $c->employee?->first_name }}</div>
                            <div class="crm-table__sub">{{ $c->employee?->employee_no ?? '' }}</div>
                        </td>
                        <td class="crm-table-num">{{ $c->date->format('d.m.Y') }}</td>
                        <td><span class="crm-chip crm-chip--grey">{{ $fieldLabel }}</span></td>
                        <td class="crm-table-num">
                            {{ $c->old_value?->format('H:i') ?? '—' }} → <strong class="text-primary">{{ $c->new_value?->format('H:i') ?? '—' }}</strong>
                        </td>
                        <td class="text-secondary small">{{ \Illuminate\Support\Str::limit($c->reason, 70) }}</td>
                        <td>
                            <span class="crm-chip {{ $statusChip }}">{{ $crm_label('correction_status', $c->status->value) }}</span>
                            @if ($c->status->value !== 'pending' && $c->decided_at)
                                <div class="crm-table__sub">{{ $c->decided_at->format('d.m H:i') }}</div>
                            @endif
                            @if ($c->decision_comment)
                                <div class="crm-table__sub">{{ \Illuminate\Support\Str::limit($c->decision_comment, 50) }}</div>
                            @endif
                        </td>
                        @if ($canDecide)
                            <td class="text-end">
                                @if ($c->status->value === 'pending')
                                    <div class="d-inline-flex gap-1 crm-row-actions">
                                        <form method="post" action="{{ route('attendance.corrections.approve', $c) }}" class="d-inline" data-submit-guard>
                                            @csrf
                                            <button class="btn btn-sm btn-primary" title="Согласовать"><i class="ti ti-check"></i> Согласовать</button>
                                        </form>
                                        <form method="post" action="{{ route('attendance.corrections.reject', $c) }}" class="d-inline" data-submit-guard>
                                            @csrf
                                            <input type="hidden" name="decision_comment" value="Отклонено по решению руководителя">
                                            <button class="btn btn-sm btn-ghost-danger" title="Отклонить"><i class="ti ti-x"></i></button>
                                        </form>
                                    </div>
                                @else
                                    <span class="text-secondary">—</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $canDecide ? 8 : 7 }}">
                            @include('partials.empty-state', ['icon' => 'edit', 'title' => 'Заявок нет', 'text' => 'Если вы забыли отметить приход или уход — подайте заявку на корректировку.', 'actionUrl' => '#newCorrection', 'actionLabel' => 'Подать заявку'])
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- модалка заявки --}}
<div class="modal modal-blur fade" id="newCorrection" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('attendance.corrections.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Заявка на корректировку</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>Что исправить</h3>
                    <div class="crm-form__desc">Дата и поле отметки, которую нужно поправить.</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-date">Дата<span class="req">*</span></label>
                            <input type="date" id="nc-date" name="date" class="form-control" required value="{{ now()->toDateString() }}">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-field">Поле<span class="req">*</span></label>
                            <select id="nc-field" name="field" class="form-select" required>
                                <option value="clock_in_at">Приход</option>
                                <option value="clock_out_at">Уход</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>Значения</h3>
                    <div class="crm-form__desc">Было — по данным устройства, станет — корректные дата и время.</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-old">Было</label>
                            <input type="datetime-local" id="nc-old" name="old_value" class="form-control">
                            <div class="form-text">Дата берётся из поля «Дата», укажите время отметки.</div>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nc-new">Стало (дата и время)<span class="req">*</span></label>
                            <input type="datetime-local" id="nc-new" name="new_value" class="form-control" required>
                            <div class="form-text">Дата берётся из поля «Дата», укажите время отметки.</div>
                        </div>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nc-reason">Причина<span class="req">*</span></label>
                        <textarea id="nc-reason" name="reason" class="form-control" rows="4" required maxlength="500"
                                  data-crm-rich placeholder="Забыл отметить уход и т. п."></textarea>
                        <div class="form-text">Причина видна согласующему и попадает в журнал аудита.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary"><i class="ti ti-send me-1"></i>Отправить</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    // Валидация ожидает полные «дата + время»: синхронизируем дату в datetime-полях
    // с полем «Дата» (рабочий день корректировки), сохраняя выбранное время.
    var modal = document.getElementById('newCorrection');
    var dateField = document.getElementById('nc-date');
    if (modal === null || dateField === null) { return; }

    var dtFields = ['nc-old', 'nc-new']
        .map(function (id) { return document.getElementById(id); })
        .filter(function (el) { return el !== null; });

    function syncDateParts() {
        var date = dateField.value;
        if (!date) { return; }
        for (var i = 0; i < dtFields.length; i++) {
            var value = dtFields[i].value;
            if (value && value.indexOf('T') !== -1) {
                dtFields[i].value = date + 'T' + value.split('T')[1];
            }
        }
    }

    dateField.addEventListener('change', syncDateParts);
    for (var i = 0; i < dtFields.length; i++) {
        dtFields[i].addEventListener('change', syncDateParts);
    }
    modal.addEventListener('show.bs.modal', syncDateParts);
})();
</script>
@endpush
