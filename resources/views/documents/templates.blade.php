@extends('layouts.tabler')
@section('workspace_layout', 'directory')

@section('title', 'Шаблоны документов')

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Документооборот</div>
            <h1 class="page-title mt-1">Шаблоны документов</h1>
            <div class="text-secondary mt-1">Категории, шаблоны и генерация с подстановкой переменных</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('documents.index') }}"><i class="ti ti-arrow-left me-1"></i>Документы</a>
            @if ($canManageCategories)
                <button class="btn btn-ghost-secondary me-1" data-bs-toggle="modal" data-bs-target="#newCategory">
                    <i class="ti ti-folder-plus me-1"></i>Категория
                </button>
            @endif
            @if ($canManageTemplates)
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newTemplate">
                    <i class="ti ti-plus me-1"></i>Новый шаблон
                </button>
            @endif
        </div>
    </div>
</div>

<div class="row g-3">
    {{-- ===== Категории ===== --}}
    <div class="col-lg-4">
        <div class="crm-form__section">
            <h3>Категории <span class="text-secondary fw-normal">({{ $categories->count() }})</span></h3>
            <div class="crm-form__desc">Разделы каталога документов.</div>
            <div class="crm-queue">
                @forelse ($categories as $category)
                    <div class="crm-queue__item" style="--c: var(--crm-info);">
                        <span class="crm-entity__logo" style="width:36px;height:36px;font-size:13px;border-radius:10px;background:color-mix(in srgb, var(--crm-info) 12%, transparent);color:var(--crm-info);">
                            <i class="ti ti-folder"></i>
                        </span>
                        <div class="crm-queue__body">
                            <div class="crm-queue__title">{{ $category->name }}</div>
                            <div class="crm-queue__sub"><code>{{ $category->code }}</code> · {{ $category->documents_count }} док.</div>
                        </div>
                    </div>
                @empty
                    <div class="crm-queue__item" style="--c: var(--crm-text-secondary);">
                        <div class="crm-queue__body">
                            <div class="crm-queue__title">Категорий нет</div>
                            <div class="crm-queue__sub">Создайте категорию — документы распределятся по ним.</div>
                        </div>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ===== Шаблоны ===== --}}
    <div class="col-lg-8">
        <div class="crm-form__section">
            <h3>Шаблоны <span class="text-secondary fw-normal">({{ $templates->count() }})</span></h3>
            <div class="crm-form__desc">Готовые тексты с переменными — генерация создаёт документ одной кнопкой.</div>
            <div class="row g-3">
                @forelse ($templates as $template)
                    <div class="col-md-6">
                        <div class="crm-entity h-100">
                            <div class="crm-entity__logo" style="background: color-mix(in srgb, var(--crm-success) 12%, transparent); color: var(--crm-success);">
                                <i class="ti ti-template"></i>
                            </div>
                            <div class="crm-entity__body">
                                <div class="d-flex align-items-center gap-1 flex-wrap mb-1">
                                    <span class="crm-chip crm-chip--grey">{{ $template->category?->name ?? 'без категории' }}</span>
                                    @if ($template->is_active === false)
                                        <span class="crm-chip crm-chip--red">откл.</span>
                                    @else
                                        <span class="crm-chip crm-chip--green">активен</span>
                                    @endif
                                </div>
                                <div class="crm-entity__title">{{ $template->name }}</div>
                                <div class="crm-entity__meta" style="white-space: normal;">{{ Str::limit(strip_tags($template->body), 90) }}</div>
                                @if ($template->variables)
                                    <div class="d-flex flex-wrap gap-1 mt-2">
                                        @foreach ($template->variables as $variable)
                                            <span class="crm-chip crm-chip--cyan">{{ '${' . $variable . '}' }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                <div class="crm-entity__footer">
                                    <span class="text-secondary small"><i class="ti ti-git-branch me-1"></i>{{ $template->workflow?->name ?? 'без маршрута' }}</span>
                                    @if ($canCreate)
                                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#gen-{{ $template->id }}">
                                            <i class="ti ti-sparkles me-1"></i>Сгенерировать
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-12">
                        @include('partials.empty-state', ['icon' => 'template', 'title' => 'Шаблонов пока нет', 'text' => 'Создайте шаблон, чтобы документы собирались из готового текста.'])
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>

{{-- ===== Модалка категории ===== --}}
@if ($canManageCategories)
<div class="modal modal-blur fade" id="newCategory" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-sm" role="document">
        <form class="modal-content" method="post" action="{{ route('documents.categories.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новая категория</h5></div>
            <div class="modal-body">
                <div class="crm-form__field">
                    <label class="form-label" for="nc-code">Код<span class="req">*</span></label>
                    <input type="text" id="nc-code" name="code" class="form-control" placeholder="HR" required>
                </div>
                <div class="crm-form__field mb-0">
                    <label class="form-label" for="nc-name">Название<span class="req">*</span></label>
                    <input type="text" id="nc-name" name="name" class="form-control" placeholder="Кадровые документы" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Добавить</button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- ===== Модалка шаблона ===== --}}
@if ($canManageTemplates)
<div class="modal modal-blur fade" id="newTemplate" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('documents.templates.store') }}" data-submit-guard>
            @csrf
            <div class="modal-header"><h5 class="modal-title">Новый шаблон</h5></div>
            <div class="modal-body">
                <div class="crm-form__section">
                    <h3>Шаблон</h3>
                    <div class="crm-form__desc">Название и категория — как документ находят в списке.</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ntpl-name">Название<span class="req">*</span></label>
                            <input type="text" id="ntpl-name" name="name" class="form-control" placeholder="Заявление на отпуск" required maxlength="150">
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ntpl-cat">Категория<span class="req">*</span></label>
                            <select id="ntpl-cat" name="category_id" class="form-select" required>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="crm-form__section">
                    <h3>Текст шаблона</h3>
                    <div class="crm-form__desc">Переменные вида {{ '${' }} $employee_name {{ '}' }} подставятся при генерации.</div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="ntpl-body">Текст шаблона<span class="req">*</span></label>
                        <textarea data-crm-rich id="ntpl-body" name="body" class="form-control" rows="7" required placeholder="Заявление {{ '${' }} $employee_name {{ '}' }} о..."></textarea>
                    </div>
                </div>

                <div class="crm-form__section mb-0">
                    <h3>Дополнительные настройки</h3>
                    <div class="crm-form__desc">Формат, маршрут согласования и список переменных.</div>
                    <div class="crm-form__row">
                        <div class="crm-form__field">
                            <label class="form-label" for="ntpl-kind">Формат</label>
                            <select id="ntpl-kind" name="kind" class="form-select">
                                <option value="blade">текст</option>
                                <option value="docx">docx</option>
                            </select>
                        </div>
                        <div class="crm-form__field">
                            <label class="form-label" for="ntpl-wf">Маршрут согласования</label>
                            <select id="ntpl-wf" name="workflow_id" class="form-select">
                                <option value="">— без маршрута —</option>
                                @foreach ($workflows as $workflow)
                                    <option value="{{ $workflow->id }}">{{ $workflow->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="ntpl-vars">Переменные</label>
                        <input type="text" id="ntpl-vars" name="variables" class="form-control" placeholder="employee_name, date_from"
                               autocomplete="off"
                               data-crm-tags data-crm-tags-hint="Enter — добавить переменную">
                        <div class="form-text" id="ntpl-vars-hint">Автодетект: напишите в тексте слово с $ (например $employee_name) — переменная появится здесь сама.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Создать шаблон</button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- ===== Модалки генерации ===== --}}
@foreach ($templates as $template)
    @if ($canCreate)
        <div class="modal modal-blur fade" id="gen-{{ $template->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <form class="modal-content" method="post" action="{{ route('documents.templates.create', $template) }}" data-submit-guard>
                    @csrf
                    <div class="modal-header"><h5 class="modal-title">Документ из «{{ $template->name }}»</h5></div>
                    <div class="modal-body">
                        <div class="crm-form__section">
                            <h3>Параметры документа</h3>
                            <div class="crm-form__desc">Что за документ и к какому сроку.</div>
                            <div class="crm-form__field">
                                <label class="form-label" for="gen-title-{{ $template->id }}">Заголовок</label>
                                <input type="text" id="gen-title-{{ $template->id }}" name="title" class="form-control" value="{{ $template->name }}">
                            </div>
                            <div class="crm-form__row">
                                <div class="crm-form__field">
                                    <label class="form-label" for="gen-prio-{{ $template->id }}">Приоритет</label>
                                    <select id="gen-prio-{{ $template->id }}" name="priority" class="form-select">
                                        <option value="low">низкий</option>
                                        <option value="medium" selected>обычный</option>
                                        <option value="high">высокий</option>
                                        <option value="critical">критичный</option>
                                    </select>
                                </div>
                                <div class="crm-form__field">
                                    <label class="form-label" for="gen-due-{{ $template->id }}">Срок</label>
                                    <input type="date" id="gen-due-{{ $template->id }}" name="due_at" class="form-control">
                                </div>
                            </div>
                        </div>
                        @if ($template->variables)
                            <div class="crm-form__section mb-0">
                                <h3>Переменные</h3>
                                <div class="crm-form__desc">Значения подставятся в текст при генерации.</div>
                                <div class="crm-form__row">
                                    @foreach ($template->variables as $variable)
                                        <div class="crm-form__field">
                                            <label class="form-label" for="gen-var-{{ $template->id }}-{{ $variable }}">{{ $variable }}<span class="req">*</span></label>
                                            <input type="text" id="gen-var-{{ $template->id }}-{{ $variable }}" name="variables[{{ $variable }}]" class="form-control" required>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary"><i class="ti ti-sparkles me-1"></i>Создать документ</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endforeach
@endsection

@push('scripts')
<script>
    /* Автодетект переменных: слова вида $name в тексте шаблона (TinyMCE
       шлёт crm:rich-input, см. corpms.js) автоматически попадают в поле
     «Переменные». Ручные значения (не из текста) сохраняются. */
    (function () {
        var modal = document.getElementById('newTemplate');
        var bodyField = document.getElementById('ntpl-body');
        var varsField = document.getElementById('ntpl-vars');
        var hint = document.getElementById('ntpl-vars-hint');
        if (!modal || !bodyField || !varsField) { return; }

        var timer = null;

        function detect(content) {
            var found = [];
            var re = /\$([A-Za-z_][A-Za-z0-9_]*)/g;
            var match;
            var html = String(content || '');
            while ((match = re.exec(html)) !== null) {
                if (found.indexOf(match[1]) === -1) { found.push(match[1]); }
            }

            if (hint) {
                hint.textContent = found.length > 0
                    ? 'Автоопределено из текста: ' + found.map(function (v) { return '$' + v; }).join(', ')
                    : 'Автодетект: напишите в тексте слово с $ (например $employee_name) — переменная появится здесь сама.';
            }
            if (found.length > 0) {
                /* значения уходят в виджет тегов (corpms.js, crm:tags-add) —
                   он сам дедуплицирует и подставляет в input при отправке */
                varsField.dispatchEvent(new CustomEvent('crm:tags-add', {
                    bubbles: false,
                    detail: { values: found }
                }));
                var adv = document.getElementById('ntpl-advanced');
                if (adv && !adv.classList.contains('show') && window.bootstrap) {
                    window.bootstrap.Collapse.getOrCreateInstance(adv).show();
                }
            }
        }

        bodyField.addEventListener('crm:rich-input', function (event) {
            clearTimeout(timer);
            var content = event.detail && event.detail.content;
            timer = setTimeout(function () { detect(content); }, 500);
        });
    })();
</script>
@endpush
