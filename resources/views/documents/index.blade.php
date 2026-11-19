@extends('layouts.tabler')
@section('workspace_layout', 'directory')

@section('title', request()->routeIs('documents.mine') ? 'Мои документы' : 'Документы')

@php
    $isMine = request()->routeIs('documents.mine');

    $statusChips = [
        'draft' => 'crm-chip--grey',
        'submitted' => 'crm-chip--cyan',
        'review' => 'crm-chip--orange',
        'revision' => 'crm-chip--red',
        'approved' => 'crm-chip--cyan',
        'signing' => 'crm-chip--orange',
        'signed' => 'crm-chip--green',
        'registered' => 'crm-chip--green',
        'archived' => 'crm-chip--grey',
    ];
    $confChips = [
        'public' => 'crm-chip--green',
        'internal' => 'crm-chip--cyan',
        'confidential' => 'crm-chip--orange',
        'secret' => 'crm-chip--red',
    ];

    // Метрики считает контроллер по всем записям scope (не по странице).
    $search = $search ?? '';
    $total = $documents->total();
    $shown = $documents->count();
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Документооборот</div>
            <h1 class="page-title mt-1">{{ $isMine ? 'Мои документы' : 'Документы' }}</h1>
            <div class="text-secondary mt-1">Версии, согласование и подписи · всего {{ $total }} · показано {{ $shown }}</div>
        </div>
        <div class="col-auto d-print-none">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newDocument"><i class="ti ti-plus me-1"></i>Новый документ</button>
        </div>
    </div>
</div>

@if (count($metrics) > 0)
    <dl class="bm-document-summary" aria-label="Документы по статусам">
        @foreach ($metrics as $metric)
            <div>
                <dt>{{ $metric['label'] }}</dt>
                <dd>{{ $metric['value'] }}</dd>
            </div>
        @endforeach
    </dl>
@endif

<form method="get" class="crm-toolbar">
    <div class="input-icon">
        <input type="text" name="q" value="{{ $search }}" class="form-control crm-search-input" placeholder="Поиск по названию или номеру" aria-label="Поиск документов">
        <span class="input-icon-addon"><i class="ti ti-search"></i></span>
    </div>
    <select name="status" class="form-select" aria-label="Статус" onchange="this.form.submit()">
        <option value="">Все статусы</option>
        @foreach ($statuses as $s)
            <option value="{{ $s }}" @selected($status === $s)>{{ $crm_label('document_status', $s) }}</option>
        @endforeach
    </select>
    <button class="btn btn-primary">Найти</button>
    <div class="crm-toolbar__spacer"></div>
    <div class="crm-switch" data-crm-switch="docs" role="group" aria-label="Вид документов">
        <button type="button" data-view="list"><i class="ti ti-list"></i>Список</button>
        <button type="button" data-view="cards"><i class="ti ti-layout-grid"></i>Карты</button>
    </div>
</form>

<div data-crm-view-container="docs" data-view-default="list">
    {{-- ===== Вид: список ===== --}}
    <div data-view-only="list">
        <div class="card">
            <div class="table-responsive">
                <table data-record-key="1" class="table table-hover table-vcenter card-table crm-thead-sticky">
                    <thead><tr><th scope="col">№</th><th scope="col">Документ</th><th scope="col">Автор</th><th scope="col">Конфиденциальность</th><th scope="col">Статус</th><th scope="col">Обновлён</th><th scope="col"></th></tr></thead>
                    <tbody>
                        @forelse ($documents as $document)
                            <tr>
                                <td class="text-secondary crm-table-num">{{ $document->document_number }}</td>
                                <td>
                                    <a href="{{ route('documents.show', $document) }}" class="text-reset text-decoration-none">
                                        <span class="crm-table__main">{{ $document->title }}</span>
                                    </a>
                                    <div class="crm-table__sub">
                                        {{ $document->category?->name ?? 'без категории' }}
                                        @if ($document->reg_number) · рег. {{ $document->reg_number }}@endif
                                    </div>
                                </td>
                                <td>{{ $document->author?->last_name ?? '—' }}</td>
                                <td><span class="crm-chip {{ $confChips[$document->confidentiality->value] ?? 'crm-chip--grey' }}">{{ $crm_label('confidentiality', $document->confidentiality->value) }}</span></td>
                                <td><span class="crm-chip {{ $statusChips[$document->status->value] ?? 'crm-chip--grey' }}">{{ $crm_label('document_status', $document->status->value) }}</span></td>
                                <td class="text-secondary crm-table-num">{{ $document->updated_at?->format('d.m.Y') }}</td>
                                <td class="text-end"><a class="btn btn-sm btn-ghost-secondary crm-row-actions" href="{{ route('documents.show', $document) }}" title="Открыть"><i class="ti ti-chevron-right"></i></a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7">@include('partials.empty-state', ['icon' => 'file-off', 'title' => 'Документов нет', 'text' => 'Создайте первый документ или снимите фильтр статуса.'])</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($documents->hasPages())
                <div class="card-footer">{{ $documents->links('pagination::tabler') }}</div>
            @endif
        </div>
    </div>

    {{-- ===== Вид: карты ===== --}}
    <div data-view-only="cards">
        <div class="row g-3">
            @forelse ($documents as $document)
                <div class="col-md-6 col-xl-4">
                    <div class="crm-entity h-100">
                        <div class="crm-entity__logo" style="background: color-mix(in srgb, var(--crm-info) 12%, transparent); color: var(--crm-info);">
                            <i class="ti ti-file-text"></i>
                        </div>
                        <div class="crm-entity__body">
                            <div class="d-flex align-items-center gap-1 flex-wrap mb-1">
                                <span class="crm-chip crm-chip--grey">{{ $document->document_number }}</span>
                                <span class="crm-chip {{ $statusChips[$document->status->value] ?? 'crm-chip--grey' }}">{{ $crm_label('document_status', $document->status->value) }}</span>
                            </div>
                            <div class="crm-entity__title">
                                <a href="{{ route('documents.show', $document) }}" class="text-reset text-decoration-none">{{ $document->title }}</a>
                            </div>
                            <div class="crm-entity__meta">
                                <i class="ti ti-folder me-1"></i>{{ $document->category?->name ?? 'без категории' }}
                                <span class="mx-1">·</span>
                                <i class="ti ti-user me-1"></i>{{ $document->author?->last_name ?? '—' }}
                            </div>
                            <div class="crm-entity__footer">
                                <span class="crm-chip {{ $confChips[$document->confidentiality->value] ?? 'crm-chip--grey' }}">{{ $crm_label('confidentiality', $document->confidentiality->value) }}</span>
                                <span class="text-secondary small ms-auto">{{ $document->updated_at?->format('d.m.Y') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12">
                    @include('partials.empty-state', ['icon' => 'file-off', 'title' => 'Документов нет', 'text' => 'Создайте первый документ или снимите фильтр статуса.'])
                </div>
            @endforelse
        </div>
    </div>
</div>

{{-- ===== Модалка создания ===== --}}
<div class="modal modal-blur fade" id="newDocument" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новый документ</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>Суть документа</h3>
                    <div class="crm-form__desc">Название и категория — по ним документ находят в поиске.</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nd-title">Заголовок<span class="req">*</span></label>
                        <input type="text" id="nd-title" name="title" class="form-control" value="{{ old('title') }}" placeholder="Например: Заявление на отпуск" required>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nd-category">Категория<span class="req">*</span></label>
                        <select id="nd-category" name="category_id" class="form-select" required>
                            <option value="">— выбрать категорию —</option>
                            @foreach (\App\Models\DocumentCategory::query()->where('is_active', true)->get() as $category)
                                <option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>Содержание</h3>
                    <div class="crm-form__desc">Текст прямо в системе или загруженный файл — хотя бы одно.</div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nd-body">Текст документа</label>
                        <textarea data-crm-rich id="nd-body" name="body" class="form-control" rows="5" placeholder="Текст документа (или приложите файл)">{{ old('body') }}</textarea>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="nd-file">Файл</label>
                        <input type="file" id="nd-file" name="file" class="form-control">
                    </div>
                </div>
                <div class="crm-form__section mb-0">
                    <h3>Классификация</h3>
                    <div class="crm-form__desc">Приоритет обработки и уровень допуска к содержимому.</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="nd-priority">Приоритет</label>
                            <select id="nd-priority" name="priority" class="form-select">
                                @foreach (['low', 'medium', 'high', 'critical'] as $p)
                                    <option value="{{ $p }}" @selected(old('priority', 'medium') === $p)>{{ $crm_label('document_priority', $p) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="nd-conf">Конфиденциальность</label>
                            <select id="nd-conf" name="confidentiality" class="form-select">
                                @foreach (['public', 'internal', 'confidential', 'secret'] as $c)
                                    <option value="{{ $c }}" @selected(old('confidentiality', 'internal') === $c)>{{ $crm_label('confidentiality', $c) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary"><i class="ti ti-plus me-1"></i>Создать документ</button>
            </div>
        </form>
    </div>
</div>
@endsection
