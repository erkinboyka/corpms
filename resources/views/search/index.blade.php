@extends('layouts.tabler')

@section('title', 'Поиск' . ($q !== '' ? ' — ' . $q : ''))

@section('content')
<div class="page-header mb-2">
    <div class="row align-items-center">
        <div class="col"><h2 class="page-title">Поиск</h2></div>
        <div class="col-auto">
            <form method="get" class="row g-2">
                <div class="col-auto">
                    <div class="input-icon">
                        <input type="text" name="q" value="{{ $q }}" class="form-control crm-search-input" placeholder="ФИО, задача, документ…" autofocus aria-label="Поиск по системе">
                        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
                    </div>
                </div>
                <div class="col-auto"><button class="btn btn-primary">Найти</button></div>
            </form>
        </div>
    </div>
</div>

@if ($q === '')
    <div class="card"><div class="card-body text-secondary">Введите запрос: ФИО, таб.№, номер или название задачи/документа.</div></div>
@else
    @php($totalCount = $employees->count() + $tasks->count() + $documents->count())

    {{-- при нулевом результате карточки разделов не показываем: ниже выводится
         общее состояние «ничего не найдено», иначе было бы дублирование --}}
    @if ($totalCount > 0)
    <div class="row row-cards">
        @if (app(\App\Support\PermissionService::class)->check(auth()->user(), 'employees.view'))
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><strong>Сотрудники</strong> <span class="badge bg-blue-lt ms-2">{{ $employees->count() }}</span></div>
                    <div class="card-body">
                        @forelse ($employees as $employee)
                            <a class="d-block py-1" href="{{ route('employees.show', $employee) }}">{{ $employee->last_name }} {{ $employee->first_name }} <span class="text-secondary small">({{ $employee->employee_no }})</span></a>
                        @empty
                            @include('partials.empty-state', ['icon' => 'users', 'title' => 'Не найдено'])
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        @if (app(\App\Support\PermissionService::class)->check(auth()->user(), 'tasks.view'))
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><strong>Задачи</strong> <span class="badge bg-blue-lt ms-2">{{ $tasks->count() }}</span></div>
                    <div class="card-body">
                        @forelse ($tasks as $task)
                            <a class="d-block py-1" href="{{ route('tasks.show', $task) }}">{{ $task->number }} — {{ \Illuminate\Support\Str::limit($task->title, 40) }}</a>
                        @empty
                            @include('partials.empty-state', ['icon' => 'list-check', 'title' => 'Не найдено'])
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        @if (app(\App\Support\PermissionService::class)->check(auth()->user(), 'documents.view'))
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><strong>Документы</strong> <span class="badge bg-blue-lt ms-2">{{ $documents->count() }}</span></div>
                    <div class="card-body">
                        @forelse ($documents as $document)
                            <a class="d-block py-1" href="{{ route('documents.show', $document) }}">{{ $document->document_number }} — {{ \Illuminate\Support\Str::limit($document->title, 40) }}</a>
                        @empty
                            @include('partials.empty-state', ['icon' => 'file-off', 'title' => 'Не найдено'])
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
                'title' => 'По запросу «' . $q . '» ничего не найдено',
                'text' => 'Проверьте написание ФИО, табельного номера или названия задачи/документа.',
            ])
        </div></div>
    @endif
@endif
@endsection
