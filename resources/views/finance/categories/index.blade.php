@extends('layouts.tabler')

@section('title', 'Финансы — Статьи')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Финансы</div>
            <h2 class="page-title mt-1">Статьи доходов и расходов</h2>
            <div class="text-secondary mt-1">Аналитика операций и бюджетов · {{ $categories->count() }}</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('finance.transactions.index') }}"><i class="ti ti-receipt me-1"></i>Операции</a>
            <a class="btn btn-ghost-secondary me-1" href="{{ route('finance.budgets.index') }}"><i class="ti ti-target me-1"></i>Бюджеты</a>
            @can('checkBoxPermission', 'finance.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newCategory"><i class="ti ti-plus me-1"></i>Новая статья</button>
            @endcan
        </div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th>Статья</th>
                    <th>Направление</th>
                    <th class="text-center">Операций</th>
                    <th>Статус</th>
                    <th class="text-end">Действия</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($categories as $category)
                    <tr>
                        <td><div class="crm-table__main">{{ $category->name }}</div></td>
                        <td>
                            <span class="crm-chip {{ $category->direction->value === 'income' ? 'crm-chip--green' : 'crm-chip--red' }}">
                                {{ $crm_label('fin_direction', $category->direction->value) }}
                            </span>
                        </td>
                        <td class="text-center">{{ $category->transactions()->count() }}</td>
                        <td>
                            <span class="crm-chip {{ $category->is_active ? 'crm-chip--green' : 'crm-chip--grey' }}">{{ $category->is_active ? 'активна' : 'выключена' }}</span>
                        </td>
                        <td class="text-end">
                            @can('checkBoxPermission', 'finance.manage')
                            <form method="post" action="{{ route('finance.categories.toggle', $category) }}" class="d-inline"
                                  onsubmit="return confirm('{{ $category->is_active ? 'Выключить' : 'Включить' }} статью «{{ $category->name }}»?')">
                                @csrf
                                <button class="btn btn-sm btn-ghost-secondary" title="{{ $category->is_active ? 'Выключить' : 'Включить' }}">
                                    <i class="ti ti-{{ $category->is_active ? 'toggle-right' : 'toggle-left' }}"></i>
                                </button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'category', 'title' => 'Статей нет', 'text' => 'Создайте статьи доходов и расходов — они используются в операциях и бюджетах.'])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('checkBoxPermission', 'finance.manage')
<div class="modal modal-blur fade" id="newCategory" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('finance.categories.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новая статья</h5></div>
            <div class="modal-body">
                <div class="crm-form__field">
                    <label class="form-label" for="fc-name">Название<span class="req">*</span></label>
                    <input type="text" id="fc-name" name="name" class="form-control" value="{{ old('name') }}" placeholder="Аренда, реклама, выручка…" required>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="fc-direction">Направление<span class="req">*</span></label>
                    <select id="fc-direction" name="direction" class="form-select" required>
                        @foreach ($directions as $direction)
                            <option value="{{ $direction->value }}" @selected(old('direction', 'expense') === $direction->value)>{{ $crm_label('fin_direction', $direction->value) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Создать</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
