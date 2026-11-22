@extends('layouts.tabler')
@section('workspace_layout', 'lookup')

@section('title', __('Поиск') . ($q !== '' ? ' — ' . $q : ''))

@section('content')
<x-page-header eyebrow="{{ __('СИСТЕМА') }}" title="{{ __('Поиск') }}" subtitle="{{ __('Единый запрос: сотрудники, задачи, документы') }}">
    <form method="get" class="d-flex gap-2">
        <div class="input-icon">
            <input type="text" name="q" value="{{ $q }}" class="form-control crm-search-input crm-search-page-input" placeholder="{{ __('ФИО, задача, документ…') }}" aria-label="{{ __('Поиск по системе') }}">
            <span class="input-icon-addon"><i class="ti ti-search"></i></span>
        </div>
        <button class="btn btn-primary">{{ __('Найти') }}</button>
    </form>
</x-page-header>

@if ($q === '')
    <div class="card"><div class="card-body text-secondary">{{ __('Введите запрос: ФИО, таб.№, номер или название задачи/документа') }}</div></div>
@else
    @php($totalCount = $employees->count() + $tasks->count() + $documents->count())

    {{-- при нулевом результате карточки разделов не показываем: ниже выводится
         общее состояние «ничего не найдено», иначе было бы дублирование --}}
    @if ($totalCount > 0)
    <div class="row row-cards">
        @if (app(\App\Support\PermissionService::class)->check(auth()->user(), 'employees.view'))
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title bm-section-title">{{ __('Сотрудники') }}</h2> <span class="badge bg-blue-lt ms-2">{{ $employees->count() }}</span></div>
                    <div class="card-body">
                        @forelse ($employees as $employee)
                            <a class="d-block py-1" href="{{ route('employees.show', $employee) }}">{{ $employee->last_name }} {{ $employee->first_name }} <span class="text-secondary small">({{ $employee->employee_no }})</span></a>
                        @empty
                            @include('partials.empty-state', ['icon' => 'users', 'title' => __('Не найдено')])
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        @if (app(\App\Support\PermissionService::class)->check(auth()->user(), 'tasks.view'))
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title bm-section-title">{{ __('Задачи') }}</h2> <span class="badge bg-blue-lt ms-2">{{ $tasks->count() }}</span></div>
                    <div class="card-body">
                        @forelse ($tasks as $task)
                            <a class="d-block py-1" href="{{ route('tasks.show', $task) }}">{{ $task->number }} — {{ \Illuminate\Support\Str::limit($task->title, 40) }}</a>
                        @empty
                            @include('partials.empty-state', ['icon' => 'list-check', 'title' => __('Не найдено')])
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        @if (app(\App\Support\PermissionService::class)->check(auth()->user(), 'documents.view'))
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title bm-section-title">{{ __('Документы') }}</h2> <span class="badge bg-blue-lt ms-2">{{ $documents->count() }}</span></div>
                    <div class="card-body">
                        @forelse ($documents as $document)
                            <a class="d-block py-1" href="{{ route('documents.show', $document) }}">{{ $document->document_number }} — {{ \Illuminate\Support\Str::limit($document->title, 40) }}</a>
                        @empty
                            @include('partials.empty-state', ['icon' => 'file-off', 'title' => __('Не найдено')])
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </div>
    @endif

    {{-- ни по одному разделу нет совпадений: показываем общее пустое состояние --}}
    @if ($totalCount === 0)
        <div class="card"><div class="card-body">
            @include('partials.empty-state', [
                'icon' => 'search',
                'title' => __('По запросу «') . $q . __('» ничего не найдено'), 'text' => __('Проверьте написание ФИО, табельного номера или названия задачи/документа.'),
            ])
        </div></div>
    @endif
@endif
@endsection
