@extends('layouts.tabler')

@section('title', 'CRM — '.$client->name)

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">CRM · Клиент</div>
            <h2 class="page-title mt-1">{{ $client->name }}</h2>
            <div class="text-secondary mt-1 d-flex align-items-center gap-2 flex-wrap">
                <span class="crm-chip crm-chip--grey">{{ $crm_label('crm_client_kind', $client->kind->value) }}</span>
                <span class="crm-chip {{ $client->status->value === 'active' ? 'crm-chip--green' : 'crm-chip--grey' }}">{{ $crm_label('crm_client_status', $client->status->value) }}</span>
                @if ($client->inn)<span>ИНН {{ $client->inn }}</span>@endif
                @if ($client->phone)<span><i class="ti ti-phone me-1"></i>{{ $client->phone }}</span>@endif
                @if ($client->email)<span><i class="ti ti-mail me-1"></i>{{ $client->email }}</span>@endif
            </div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('crm.clients.index') }}"><i class="ti ti-arrow-left me-1"></i>К списку</a>
            @can('checkBoxPermission', 'crm.manage')
            <button class="btn btn-ghost-secondary me-1" data-bs-toggle="modal" data-bs-target="#editClient"><i class="ti ti-pencil me-1"></i>Изменить</button>
            <form method="post" action="{{ route('crm.clients.toggle', $client) }}" class="d-inline"
                  onsubmit="return confirm('{{ $client->status->value === 'active' ? 'Отправить клиента в архив?' : 'Вернуть клиента в работу?' }}')">
                @csrf
                <button class="btn btn-ghost-secondary"><i class="ti ti-{{ $client->status->value === 'active' ? 'archive' : 'archive-off' }} me-1"></i>{{ $client->status->value === 'active' ? 'В архив' : 'В работу' }}</button>
            </form>
            @endcan
            @can('checkBoxPermission', 'crm.deals.manage')
            <button class="btn btn-primary ms-1" data-bs-toggle="modal" data-bs-target="#newDeal"><i class="ti ti-plus me-1"></i>Сделка</button>
            @endcan
        </div>
    </div>
</div>

<div class="crm-metrics">
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #4299e1;"><i class="ti ti-chart-funnel"></i>Сделки в работе</div>
        <div class="crm-metric__value">{{ $metrics['deals_open'] }}</div>
        <div class="crm-metric__hint">открытых</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #0b9b72;"><i class="ti ti-currency-dollar"></i>Сумма в работе</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $metrics['amount_open'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">потенциал</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #ae3ec9;"><i class="ti ti-trophy"></i>Выиграно</div>
        <div class="crm-metric__value" style="font-size: 1.2rem; padding-top: 8px;">{{ number_format((float) $metrics['amount_won'], 0, ',', ' ') }}</div>
        <div class="crm-metric__hint">закрытые сделки</div>
    </div>
    <div class="crm-metric">
        <div class="crm-metric__label" style="color: #f76707;"><i class="ti ti-calendar-event"></i>Активности</div>
        <div class="crm-metric__value">{{ $metrics['activities_pending'] }}</div>
        <div class="crm-metric__hint">в работе</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">
                <div class="card-title">Сделки</div>
                <div class="card-actions text-secondary">{{ $client->deals->count() }}</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Сделка</th>
                            <th>Стадия</th>
                            <th class="text-end">Сумма</th>
                            <th>Срок</th>
                            <th>Ответственный</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($client->deals as $deal)
                            <tr>
                                <td>
                                    <div class="crm-table__main">{{ $deal->title }}</div>
                                    <div class="crm-table__sub">№{{ $deal->id }} · вероятность {{ $deal->probability }}%</div>
                                </td>
                                <td><span class="crm-chip {{ $deal->stage->value === 'won' ? 'crm-chip--green' : ($deal->stage->value === 'lost' ? 'crm-chip--red' : '') }}">{{ $crm_label('crm_deal_stage', $deal->stage->value) }}</span></td>
                                <td class="text-end">{{ number_format((float) $deal->amount, 0, ',', ' ') }}</td>
                                <td class="text-secondary">{{ $deal->expected_close_date?->format('d.m.Y') ?? '—' }}</td>
                                <td class="text-secondary">{{ $deal->owner ? $deal->owner->last_name : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'chart-funnel', 'title' => 'Сделок пока нет', 'text' => 'Создайте первую сделку по клиенту.'])</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">
                <div class="card-title">Контакты</div>
                <div class="card-actions">
                    @can('checkBoxPermission', 'crm.manage')
                    <button class="btn btn-sm btn-ghost-secondary" data-bs-toggle="modal" data-bs-target="#newContact"><i class="ti ti-plus me-1"></i>Контакт</button>
                    @endcan
                </div>
            </div>
            <div class="list-group list-group-flush">
                @forelse ($client->contacts->sortByDesc('is_primary') as $contact)
                    <div class="list-group-item d-flex align-items-start">
                        <div class="me-3">
                            <span class="avatar avatar-sm">{{ mb_strtoupper(mb_substr($contact->first_name, 0, 1)) }}</span>
                        </div>
                        <div class="flex-fill">
                            <div class="fw-semibold">
                                {{ $contact->fullName() }}
                                @if ($contact->is_primary)<span class="crm-chip crm-chip--cyan ms-1">основной</span>@endif
                            </div>
                            <div class="text-secondary small">{{ $contact->position ?? 'должность не указана' }}</div>
                            @if ($contact->phone || $contact->email)
                                <div class="text-secondary small">
                                    @if ($contact->phone)<i class="ti ti-phone me-1"></i>{{ $contact->phone }}@endif
                                    @if ($contact->email)<i class="ti ti-mail ms-2 me-1"></i>{{ $contact->email }}@endif
                                </div>
                            @endif
                        </div>
                        @can('checkBoxPermission', 'crm.manage')
                        <form method="post" action="{{ route('crm.clients.contacts.destroy', [$client, $contact]) }}"
                              onsubmit="return confirm('Удалить контакт «{{ $contact->fullName() }}»?')">
                            @csrf
                            @method('delete')
                            <button class="btn btn-sm btn-ghost-danger" title="Удалить"><i class="ti ti-trash"></i></button>
                        </form>
                        @endcan
                    </div>
                @empty
                    <div class="list-group-item text-secondary">Контактов пока нет.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <div class="card-title">Активности</div>
                <div class="card-actions"><a class="btn btn-sm btn-ghost-secondary" href="{{ route('crm.activities.index') }}">Все</a></div>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr><th>Активность</th><th>Вид</th><th class="text-end">Срок</th><th>Статус</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($client->activities as $activity)
                            <tr>
                                <td>
                                    <div class="crm-table__main">{{ $activity->subject }}</div>
                                    @if ($activity->deal)<div class="crm-table__sub">сделка «{{ $activity->deal->title }}»</div>@endif
                                </td>
                                <td><span class="crm-chip crm-chip--grey">{{ $crm_label('crm_activity_kind', $activity->kind->value) }}</span></td>
                                <td class="text-end {{ $activity->isOverdue() ? 'text-danger fw-semibold' : 'text-secondary' }}">{{ $activity->due_at?->format('d.m H:i') ?? '—' }}</td>
                                <td>
                                    @if ($activity->isCompleted())
                                        <span class="crm-chip crm-chip--green">выполнена</span>
                                    @elseif ($activity->isOverdue())
                                        <span class="crm-chip crm-chip--red">просрочена</span>
                                    @else
                                        <span class="crm-chip">в работе</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-secondary">Активностей пока нет.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><div class="card-title">Реквизиты и заметки</div></div>
            <div class="card-body">
                <div class="text-secondary small">Сайт</div>
                <div>{{ $client->website ?: '—' }}</div>
                <div class="text-secondary small mt-2">Адрес</div>
                <div>{{ $client->address ?: '—' }}</div>
                <div class="text-secondary small mt-2">Ответственный</div>
                <div>{{ $client->owner ? $client->owner->last_name.' '.$client->owner->first_name : '—' }}</div>
                <div class="text-secondary small mt-2">Примечание</div>
                <div class="text-secondary">{{ $client->notes ?: '—' }}</div>
            </div>
        </div>
    </div>
</div>

@can('checkBoxPermission', 'crm.manage')
<div class="modal modal-blur fade" id="editClient" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('crm.clients.update', $client) }}" data-submit-guard>
            @csrf
            @method('patch')
            <div class="modal-header"><h5 class="modal-title">Изменить клиента</h5></div>
            <div class="modal-body">
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="ec-name">Название<span class="req">*</span></label>
                        <input type="text" id="ec-name" name="name" class="form-control" value="{{ $client->name }}" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ec-kind">Вид<span class="req">*</span></label>
                        <select id="ec-kind" name="kind" class="form-select" required>
                            @foreach ($kinds as $kind)
                                <option value="{{ $kind->value }}" @selected($client->kind === $kind)>{{ $crm_label('crm_client_kind', $kind->value) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ec-owner">Ответственный</label>
                        <select id="ec-owner" name="owner_id" class="form-select">
                            <option value="">— не назначен —</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected((int) $client->owner_id === (int) $employee->id)>{{ $employee->last_name }} {{ $employee->first_name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="ec-inn">ИНН</label>
                        <input type="text" id="ec-inn" name="inn" class="form-control" value="{{ $client->inn }}" maxlength="32">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ec-phone">Телефон</label>
                        <input type="text" id="ec-phone" name="phone" class="form-control" value="{{ $client->phone }}" maxlength="32">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ec-email">E-mail</label>
                        <input type="email" id="ec-email" name="email" class="form-control" value="{{ $client->email }}">
                    </div>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="ec-website">Сайт</label>
                        <input type="text" id="ec-website" name="website" class="form-control" value="{{ $client->website }}">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ec-address">Адрес</label>
                        <input type="text" id="ec-address" name="address" class="form-control" value="{{ $client->address }}" maxlength="1000">
                    </div>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="ec-notes">Примечание</label>
                    <textarea id="ec-notes" name="notes" class="form-control" rows="2" maxlength="2000">{{ $client->notes }}</textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<div class="modal modal-blur fade" id="newContact" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('crm.clients.contacts.store', $client) }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новый контакт</h5></div>
            <div class="modal-body">
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="nct-first">Имя<span class="req">*</span></label>
                        <input type="text" id="nct-first" name="first_name" class="form-control" value="{{ old('first_name') }}" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nct-last">Фамилия</label>
                        <input type="text" id="nct-last" name="last_name" class="form-control" value="{{ old('last_name') }}">
                    </div>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="nct-position">Должность</label>
                    <input type="text" id="nct-position" name="position" class="form-control" value="{{ old('position') }}">
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="nct-phone">Телефон</label>
                        <input type="text" id="nct-phone" name="phone" class="form-control" value="{{ old('phone') }}" maxlength="32">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nct-email">E-mail</label>
                        <input type="email" id="nct-email" name="email" class="form-control" value="{{ old('email') }}">
                    </div>
                </div>
                <label class="form-check">
                    <input type="checkbox" class="form-check-input" name="is_primary" value="1" @checked(old('is_primary'))>
                    <span class="form-check-label">Основной контакт</span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Добавить</button>
            </div>
        </form>
    </div>
</div>
@endcan

@can('checkBoxPermission', 'crm.deals.manage')
<div class="modal modal-blur fade" id="newDeal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('crm.deals.store') }}" data-submit-guard>
            @csrf
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <div class="modal-header"><h5 class="modal-title">Сделка по клиенту</h5></div>
            <div class="modal-body">
                <div class="crm-form__field">
                    <label class="form-label" for="nd-title">Название<span class="req">*</span></label>
                    <input type="text" id="nd-title" name="title" class="form-control" value="{{ old('title') }}" placeholder="Поставка офисной техники" required>
                </div>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="nd-amount">Сумма</label>
                        <input type="number" id="nd-amount" name="amount" class="form-control" value="{{ old('amount', 0) }}" step="0.01" min="0">
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nd-stage">Стадия</label>
                        <select id="nd-stage" name="stage" class="form-select">
                            @foreach ($stages as $stage)
                                <option value="{{ $stage->value }}" @selected(old('stage', 'new') === $stage->value)>{{ $crm_label('crm_deal_stage', $stage->value) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nd-expected">Ожидаемое закрытие</label>
                        <input type="date" id="nd-expected" name="expected_close_date" class="form-control" value="{{ old('expected_close_date') }}">
                    </div>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="nd-owner">Ответственный</label>
                    <select id="nd-owner" name="owner_id" class="form-select">
                        <option value="">— не назначен —</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected((string) old('owner_id') === (string) $employee->id)>{{ $employee->last_name }} {{ $employee->first_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="nd-notes">Примечание</label>
                    <textarea id="nd-notes" name="notes" class="form-control" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Создать сделку</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
