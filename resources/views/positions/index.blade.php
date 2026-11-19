@extends('layouts.tabler')
@section('workspace_layout', 'directory')

@section('title', 'Должности')

@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Организация</div>
            <h1 class="page-title mt-1">Должности</h1>
            <div class="text-secondary mt-1">Каталог позиций и грейдов · {{ $positions->count() }}</div>
        </div>
        @can('checkBoxPermission', 'positions.manage')
        <div class="col-auto d-print-none">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newPosition">
                <i class="ti ti-plus me-1"></i>Добавить должность
            </button>
        </div>
        @endcan
    </div>
</div>

<div class="crm-toolbar">
    <div class="input-icon">
        <input type="search" class="form-control crm-search-input" placeholder="Поиск: название, код, грейд…" aria-label="Поиск должностей"
               data-crm-filter=".crm-positions > *" data-crm-filter-count="#positions-count">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <div class="crm-toolbar__spacer"></div>
    <span class="crm-toolbar__count" id="positions-count">Показано {{ $positions->count() }}</span>
</div>

<div class="row row-cards g-3 crm-positions">
    @forelse ($positions as $position)
        <div class="col-12 col-md-6 col-xl-4">
            <div class="crm-entity h-100">
                <div class="crm-entity__head">
                    <span class="crm-entity__logo"><i class="ti ti-id-badge-2"></i></span>
                    <div class="min-w-0">
                        <div class="crm-entity__name">{{ $position->name }}</div>
                        <div class="crm-entity__sub">код {{ $position->code }}</div>
                    </div>
                    @if ($position->grade)
                        <span class="crm-chip ms-auto">грейд {{ $position->grade }}</span>
                    @endif
                </div>
                <div class="crm-entity__sub">
                    @if ($position->description)
                        {{ Str::limit($position->description, 140) }}
                    @else
                        <span class="text-secondary">Описание не заполнено</span>
                    @endif
                </div>
                <div class="crm-entity__footer">
                    @can('checkBoxPermission', 'positions.manage')
                    <div class="d-inline-flex gap-1 crm-row-actions">
                        <button class="btn btn-sm btn-ghost-secondary" title="Изменить должность" data-bs-toggle="modal" data-bs-target="#editPosition-{{ $position->id }}"><i class="ti ti-pencil"></i></button>
                        <form method="post" action="{{ route('positions.destroy', $position) }}" onsubmit="return confirm('Удалить должность «{{ $position->name }}»? Допустимо только если за ней не закреплены сотрудники.')" title="Удалить">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-ghost-danger" title="Удалить должность"><i class="ti ti-trash"></i></button>
                        </form>
                    </div>
                    @endcan
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            @include('partials.empty-state', ['icon' => 'id-badge', 'title' => 'Должностей пока нет', 'text' => 'Создайте должность — она станет основой для штатного расписания.'])
        </div>
    @endforelse
</div>

@can('checkBoxPermission', 'positions.manage')
{{-- создание --}}
<div class="modal modal-blur fade" id="newPosition" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('positions.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новая должность</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>Должность</h3>
                    <div class="crm-form__desc">Название и код — как в штатном расписании и документах.</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="np-name">Название<span class="req">*</span></label>
                        <input type="text" id="np-name" name="name" class="form-control" placeholder="Ведущий разработчик" required>
                    </div>
                    <div class="crm-form__row mb-0">
                        <div class="crm-form__field">
                            <label class="form-label" for="np-code">Код<span class="req">*</span></label>
                            <input type="text" id="np-code" name="code" class="form-control" placeholder="DEV2" required
                                   data-crm-tags data-crm-tags-hint="Коды — по одному, Enter">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="np-grade">Грейд</label>
                            <input type="text" id="np-grade" name="grade" class="form-control" placeholder="S2"
                                   data-crm-tags data-crm-tags-hint="Грейды — по одному, Enter">
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>Описание</h3>
                    <div class="crm-form__desc">Зона ответственности и требования к кандидату.</div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="np-desc">Описание</label>
                        <textarea id="np-desc" name="description" class="form-control" rows="4" data-crm-rich placeholder="Зона ответственности, требования…"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Добавить</button>
            </div>
        </form>
    </div>
</div>

{{-- правка — по строке --}}
@foreach ($positions as $position)
<div class="modal modal-blur fade" id="editPosition-{{ $position->id }}" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" method="post" action="{{ route('positions.update', $position) }}" data-submit-guard>
            @csrf @method('PATCH')
            <div class="modal-header"><h5 class="modal-title">Правка должности</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>Должность</h3>
                    <div class="crm-form__desc">Название и код — выровнено с формой создания.</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="ep{{ $position->id }}-name">Название<span class="req">*</span></label>
                        <input type="text" id="ep{{ $position->id }}-name" name="name" class="form-control" value="{{ $position->name }}" required>
                    </div>
                    <div class="crm-form__row mb-0">
                        <div class="crm-form__field">
                            <label class="form-label" for="ep{{ $position->id }}-code">Код<span class="req">*</span></label>
                            <input type="text" id="ep{{ $position->id }}-code" name="code" class="form-control" value="{{ $position->code }}" required
                                   data-crm-tags data-crm-tags-hint="Коды — по одному, Enter">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ep{{ $position->id }}-grade">Грейд</label>
                            <input type="text" id="ep{{ $position->id }}-grade" name="grade" class="form-control" value="{{ $position->grade }}"
                                   data-crm-tags data-crm-tags-hint="Грейды — по одному, Enter">
                        </div>
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>Описание</h3>
                    <div class="crm-form__desc">Зона ответственности и требования к кандидату.</div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="ep{{ $position->id }}-desc">Описание</label>
                        <textarea id="ep{{ $position->id }}-desc" name="description" class="form-control" rows="4" data-crm-rich>{{ $position->description }}</textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>
@endforeach
@endcan
@endsection
