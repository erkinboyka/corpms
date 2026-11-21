@extends('layouts.tabler')
@section('workspace_layout', 'directory')

@section('title', __('Финансы — Статьи'))

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">{{ __('Финансы') }}</div>
            <h1 class="page-title mt-1">{{ __('Статьи доходов и расходов') }}</h1>
            <div class="text-secondary mt-1">{{ __('Аналитика операций и бюджетов') }} {{ $categories->count() }}</div>
        </div>
        <div class="col-auto d-print-none">


            @can('checkBoxPermission', 'finance.manage')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newCategory"><i class="ti ti-plus me-1"></i>{{ __('Новая статья') }}</button>
            @endcan
        </div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table data-record-key="0" class="table table-hover table-vcenter card-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('Статья') }}</th>
                    <th scope="col">{{ __('Направление') }}</th>
                    <th scope="col" class="text-center">{{ __('Операций') }}</th>
                    <th scope="col">{{ __('Статус') }}</th>
                    <th scope="col" class="text-end">{{ __('Действия') }}</th>
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
                            <span class="crm-chip {{ $category->is_active ? 'crm-chip--green' : 'crm-chip--grey' }}">{{ $category->is_active ? __('активна') : __('выключена') }}</span>
                        </td>
                        <td class="text-end">
                            @can('checkBoxPermission', 'finance.manage')
                            <form method="post" action="{{ route('finance.categories.toggle', $category) }}" class="d-inline"
                                  onsubmit="return confirm('{{ $category->is_active ? __('Выключить') : __('Включить') }} {{ __('статью') }} «{{ $category->name }}»?')">
                                @csrf
                                <button class="btn btn-sm btn-ghost-secondary" title="{{ $category->is_active ? __('Выключить') : __('Включить') }}">
                                    <i class="ti ti-{{ $category->is_active ? 'toggle-right' : 'toggle-left' }}"></i>
                                </button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">@include('partials.empty-state', ['icon' => 'category', 'title' => __('Статей нет'), 'text' => __('Создайте статьи доходов и расходов — они используются в операциях и бюджетах.')])</td></tr>
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
            <div class="modal-header"><h5 class="modal-title">{{ __('Новая статья') }}</h5></div>
            <div class="modal-body">
                <div class="crm-form__field">
                    <label class="form-label" for="fc-name">{{ __('Название') }}<span class="req">*</span></label>
                    <input type="text" id="fc-name" name="name" class="form-control" value="{{ old('name') }}" placeholder="{{ __("Аренда, реклама, выручка…") }}" required>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="fc-direction">{{ __('Направление') }}<span class="req">*</span></label>
                    <select id="fc-direction" name="direction" class="form-select" required>
                        @foreach ($directions as $direction)
                            <option value="{{ $direction->value }}" @selected(old('direction', 'expense') === $direction->value)>{{ $crm_label('fin_direction', $direction->value) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Создать') }}</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
