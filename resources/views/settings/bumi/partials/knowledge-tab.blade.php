@php
    $qaItems = $knowledgeItems->where('kind', 'qa')->values();
    $fileItems = $knowledgeItems->where('kind', 'file')->values();
    $hasText = trim(html_entity_decode(strip_tags((string) $agent->knowledge), ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== '';
@endphp

<div class="bumi-kb">
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                <div>
                    <div class="crm-eyebrow">Bumi · база знаний</div>
                    <h1 class="h3 mt-1 mb-1">{{ __('На чём отвечает ассистент') }}</h1>
                    <div class="text-secondary">
                        {{ __('Заполните три источника — Bumi будет отвечать по вашим данным, а не «от себя».') }}
                    </div>
                </div>
                @if ($canManage)
                    <form method="post" action="{{ route('bumi.settings.knowledge.pack', $agent) }}">
                        @csrf
                        <button class="btn btn-ghost-secondary" type="submit">
                            <i class="ti ti-library me-1"></i>{{ __('Добавить готовый пакет') }}
                        </button>
                    </form>
                @endif
            </div>

            <div class="row g-3 mt-3">
                <div class="col-sm-6 col-lg-4">
                    <div class="bumi-kb__stat">
                        <div class="bumi-kb__stat-icon"><i class="ti ti-notes"></i></div>
                        <div>
                            <div class="small text-secondary">{{ __('Общий текст') }}</div>
                            <div class="fw-semibold">{{ $hasText ? __('Заполнен') : __('Пусто') }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-4">
                    <div class="bumi-kb__stat">
                        <div class="bumi-kb__stat-icon"><i class="ti ti-message-circle"></i></div>
                        <div>
                            <div class="small text-secondary">{{ __('Готовые ответы') }}</div>
                            <div class="fw-semibold" data-bumi-counter="qa">{{ $qaItems->count() }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-4">
                    <div class="bumi-kb__stat">
                        <div class="bumi-kb__stat-icon"><i class="ti ti-file-description"></i></div>
                        <div>
                            <div class="small text-secondary">{{ __('Файлы в RAG') }}</div>
                            <div class="fw-semibold" data-bumi-counter="files">{{ $fileItems->count() }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Шаг 1 — общий текст базы знаний --}}
    <section class="crm-form__section bumi-kb__section mb-3">
        <div class="bumi-kb__head">
            <span class="bumi-kb__step">1</span>
            <div class="flex-grow-1">
                <h3>{{ __('Общие факты и правила') }}</h3>
                <div class="crm-form__desc mb-0">
                    {{ __('Коротко запишите часы работы, адрес, условия и правила. Форматирование сохраняется, клиенту уходит чистый текст.') }}
                </div>
            </div>
            @if ($canManage)
                <button type="submit" form="bumi-kb-text-form" class="btn btn-primary btn-sm text-nowrap flex-shrink-0">
                    <i class="ti ti-device-floppy me-1"></i>{{ __('Сохранить текст') }}
                </button>
            @endif
        </div>

        @if ($canManage)
            <form id="bumi-kb-text-form" method="post" action="{{ route('bumi.settings.agent.knowledge', $agent) }}" data-submit-guard class="mt-3">
                @csrf
                <div class="bumi-rich">
                    <textarea id="bumi-knowledge" name="knowledge" class="form-control" rows="12" maxlength="50000"
                              data-crm-rich data-rich-height="360"
                              placeholder="{{ __('Например: рабочие часы: пн–пт, 9:00–18:00') }}">{{ old('knowledge', $agent->knowledge) }}</textarea>
                </div>
                <div class="form-hint mt-2">
                    <i class="ti ti-info-circle me-1"></i>{{ __('Не сохраняйте здесь пароли и персональные данные: текст попадает в запрос к AI-провайдеру.') }}
                    @error('knowledge')<span class="text-danger ms-2">{{ $message }}</span>@enderror
                </div>
            </form>
        @else
            <div class="alert alert-info mb-0 mt-3">{{ __('Доступ только для чтения: нет права bumi.manage') }}</div>
        @endif
    </section>

    {{-- Шаг 2 — пары вопрос/ответ --}}
    <section class="crm-form__section bumi-kb__section mb-3">
        <div class="bumi-kb__head">
            <span class="bumi-kb__step">2</span>
            <div class="flex-grow-1">
                <h3>{{ __('Точные ответы на вопросы') }}</h3>
                <div class="crm-form__desc mb-0">
                    {{ __('Если вопрос совпадает с готовым, Bumi берёт этот ответ первым. Их можно включать, выключать и редактировать.') }}
                </div>
            </div>
            <span class="crm-chip crm-chip--cyan" data-bumi-counter="qa">{{ $qaItems->count() }}</span>
        </div>

        @if ($canManage)
            <form method="post" action="{{ route('bumi.settings.knowledge.items.store', $agent) }}" data-submit-guard class="bumi-kb__add mt-3">
                @csrf
                <div class="bumi-kb__add-title">
                    <i class="ti ti-plus me-1"></i>{{ __('Новая пара') }}
                </div>
                <div class="crm-form__row mt-3">
                    <div class="crm-form__field">
                        <label class="form-label" for="bumi-qa-question">{{ __('Вопрос клиента') }}<span class="req">*</span></label>
                        <input id="bumi-qa-question" name="question" class="form-control" maxlength="2000" value="{{ old('question') }}" required>
                        @error('question')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="bumi-qa-title">{{ __('Короткий заголовок') }}</label>
                        <input id="bumi-qa-title" name="title" class="form-control" maxlength="255" value="{{ old('title') }}"
                               placeholder="{{ __('Например: время работы') }}">
                    </div>
                </div>
                <div class="crm-form__field">
                    <label class="form-label" for="bumi-qa-answer">{{ __('Ответ Bumi') }}<span class="req">*</span></label>
                    <div class="bumi-rich">
                        <textarea id="bumi-qa-answer" name="answer" class="form-control" rows="8" maxlength="20000"
                                  data-crm-rich data-rich-height="280" required>{{ old('answer') }}</textarea>
                    </div>
                    @error('answer')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
                <div class="crm-form__footer crm-form__footer--card mt-2">
                    <div class="text-secondary small">{{ __('Пара сразу участвует в поиске ответов') }}</div>
                    <button type="submit" class="btn btn-primary"><i class="ti ti-plus me-1"></i>{{ __('Добавить ответ') }}</button>
                </div>
            </form>
        @endif

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-4 mb-2">
            <div class="d-flex align-items-center gap-2">
                <h3 class="h4 mb-0">{{ __('Список ответов') }}</h3>
                <span class="crm-chip {{ $qaItems->where('enabled', true)->count() > 0 ? 'crm-chip--green' : 'crm-chip--grey' }}">
                    <span data-bumi-counter="qa-active">{{ $qaItems->where('enabled', true)->count() }}</span> {{ __('в работе') }}
                </span>
            </div>
            <input type="search" class="form-control crm-search-input" style="max-width: 320px;"
                   placeholder="{{ __('Найти вопрос или ответ') }}"
                   data-crm-filter="[data-bumi-qa]" data-crm-filter-count="#bumi-qa-count">
        </div>
        <div class="small text-secondary mb-2" id="bumi-qa-count">{{ __('Показано') }} <span data-bumi-counter="qa">{{ $qaItems->count() }}</span></div>

        <div class="table-responsive">
            <table class="table table-vcenter card-table table-hover">
                <thead>
                    <tr>
                        <th style="width: 32%;">{{ __('Вопрос') }}</th>
                        <th>{{ __('Ответ') }}</th>
                        <th class="w-1">{{ __('Статус') }}</th>
                        <th class="w-1"></th>
                    </tr>
                </thead>
                <tbody data-bumi-qa-body>
                    @forelse ($qaItems as $item)
                        @php
                            $editing = (string) old('qa_id') === (string) $item->id;
                            $questionValue = $editing ? old('question', $item->question) : $item->question;
                            $titleValue = $editing ? old('title', $item->title) : $item->title;
                            $answerValue = $editing ? old('answer', $item->answer) : $item->answer;
                        @endphp
                        <tr data-bumi-qa data-bumi-qa-id="{{ $item->id }}">
                            <td>
                                <div class="fw-semibold">{{ $item->title ?: $item->plainQuestion() }}</div>
                                @if ($item->title !== '' && $item->plainQuestion() !== $item->title)
                                    <div class="text-secondary small">{{ $item->plainQuestion() }}</div>
                                @endif
                            </td>
                            <td class="text-break">@include('partials.rich-text', ['value' => $item->answer, 'class' => 'small'])</td>
                            <td>
                                <span class="crm-chip {{ $item->enabled ? 'crm-chip--green' : 'crm-chip--grey' }}" data-bumi-toggle-chip>
                                    {{ $item->enabled ? __('В работе') : __('Выключено') }}
                                </span>
                            </td>
                            <td>
                                @if ($canManage)
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-ghost-secondary"
                                                data-bs-toggle="modal" data-bs-target="#bumi-qa-edit-{{ $item->id }}"
                                                title="{{ __('Редактировать') }}"><i class="ti ti-pencil"></i></button>
                                        <form method="post" action="{{ route('bumi.settings.knowledge.item.toggle', $item) }}" data-bumi-toggle data-bumi-toggle-kind="knowledge" data-bumi-id="{{ $item->id }}">
                                            @csrf
                                            <button type="submit" class="btn btn-ghost-secondary" title="{{ __('Включить/выключить') }}" data-bumi-toggle-button><i class="ti ti-{{ $item->enabled ? 'eye-off' : 'eye' }}"></i></button>
                                        </form>
                                        <form method="post" action="{{ route('bumi.settings.knowledge.item.delete', $item) }}" data-confirm="{{ __('Удалить запись из базы знаний Bumi?') }}" data-confirm-danger="1" data-bumi-delete data-bumi-id="{{ $item->id }}">
                                            @csrf
                                            <button class="btn btn-ghost-danger" title="{{ __('Удалить') }}"><i class="ti ti-trash"></i></button>
                                        </form>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                @include('partials.empty-state', [
                                    'icon' => 'books',
                                    'title' => __('Готовых ответов нет'),
                                    'text' => __('Добавьте пару вручную или нажмите «Добавить готовый пакет».'),
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($canManage)
            @foreach ($qaItems as $item)
                @php
                    $editing = (string) old('qa_id') === (string) $item->id;
                    $questionValue = $editing ? old('question', $item->question) : $item->question;
                    $titleValue = $editing ? old('title', $item->title) : $item->title;
                    $answerValue = $editing ? old('answer', $item->answer) : $item->answer;
                @endphp
                <div class="modal modal-blur fade @if ($editing) bumi-kb-reopen @endif" id="bumi-qa-edit-{{ $item->id }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                        <form class="modal-content" method="post" action="{{ route('bumi.settings.knowledge.item.update', $item) }}" data-submit-guard>
                            @csrf
                            <input type="hidden" name="qa_id" value="{{ $item->id }}">
                            <div class="modal-header">
                                <h4 class="modal-title">{{ __('Редактировать ответ') }}</h4>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Закрыть') }}"></button>
                            </div>
                            <div class="modal-body">
                                <div class="crm-form__row">
                                    <div class="crm-form__field">
                                        <label class="form-label" for="bumi-qa-edit-question-{{ $item->id }}">{{ __('Вопрос клиента') }}<span class="req">*</span></label>
                                        <input id="bumi-qa-edit-question-{{ $item->id }}" name="question" class="form-control" maxlength="2000" value="{{ $questionValue }}" required>
                                    </div>
                                    <div class="crm-form__field">
                                        <label class="form-label" for="bumi-qa-edit-title-{{ $item->id }}">{{ __('Короткий заголовок') }}</label>
                                        <input id="bumi-qa-edit-title-{{ $item->id }}" name="title" class="form-control" maxlength="255" value="{{ $titleValue }}">
                                    </div>
                                </div>
                                <div class="crm-form__field mb-0">
                                    <label class="form-label" for="bumi-qa-edit-answer-{{ $item->id }}">{{ __('Ответ Bumi') }}<span class="req">*</span></label>
                                    <div class="bumi-rich">
                                        <textarea id="bumi-qa-edit-answer-{{ $item->id }}" name="answer" class="form-control" rows="8" maxlength="20000"
                                                  data-crm-rich data-rich-height="300" required>{{ $answerValue }}</textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                                <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>{{ __('Сохранить') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endforeach
        @endif
    </section>

    {{-- Шаг 3 — файлы --}}
    <section class="crm-form__section bumi-kb__section mb-3">
        <div class="bumi-kb__head">
            <span class="bumi-kb__step">3</span>
            <div class="flex-grow-1">
                <h3>{{ __('Файлы и документы') }}</h3>
                <div class="crm-form__desc mb-0">
                    {{ __('Файл сохраняется в документообороте и попадает в общий RAG-индекс. Текстовые файлы индексируются сразу.') }}
                </div>
            </div>
            <span class="crm-chip crm-chip--cyan">{{ $fileItems->count() }}</span>
        </div>

        @if ($canManage)
            <form method="post" action="{{ route('bumi.settings.knowledge.upload', $agent) }}" enctype="multipart/form-data"
                  data-submit-guard class="bumi-kb__upload mt-3">
                @csrf
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="bumi-kb-file">{{ __('Файл') }}<span class="req">*</span></label>
                        <input type="file" id="bumi-kb-file" name="file" class="form-control" required
                               accept=".pdf,.doc,.docx,.txt,.csv,.md,.json,.png,.jpg,.jpeg,.tif,.tiff,.bmp,.webp">
                        <div class="form-hint">{{ __('PDF, DOCX, TXT, CSV, MD, JSON и изображения') }}</div>
                        @error('file')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="bumi-kb-file-title">{{ __('Название в базе') }}</label>
                        <input id="bumi-kb-file-title" name="title" class="form-control" maxlength="255" value="{{ old('title') }}"
                               placeholder="{{ __('Например: прайс на 2026 год') }}">
                    </div>
                </div>
                <button class="btn btn-primary" type="submit"><i class="ti ti-upload me-1"></i>{{ __('Загрузить и проиндексировать') }}</button>
            </form>
        @endif

        <div class="table-responsive mt-4">
            <table class="table table-vcenter card-table table-hover">
                <thead>
                    <tr>
                        <th>{{ __('Файл') }}</th>
                        <th>{{ __('Название в базе') }}</th>
                        <th class="w-1">{{ __('Индекс') }}</th>
                        <th class="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($fileItems as $file)
                        <tr data-bumi-file data-bumi-qa-id="{{ $file->id }}">
                            <td class="text-break"><i class="ti ti-file me-1"></i>{{ $file->filename }}</td>
                            <td>{{ $file->title }}</td>
                            <td>
                                <span class="crm-chip {{ $file->enabled ? 'crm-chip--green' : 'crm-chip--grey' }}" data-bumi-toggle-chip>
                                    {{ $file->enabled ? __('в поиске') : __('выключен') }}
                                </span>
                            </td>
                            <td>
                                @if ($canManage)
                                    <div class="btn-group btn-group-sm">
                                        <form method="post" action="{{ route('bumi.settings.knowledge.item.toggle', $file) }}" data-bumi-toggle data-bumi-toggle-kind="knowledge" data-bumi-id="{{ $file->id }}">
                                            @csrf
                                            <button type="submit" class="btn btn-ghost-secondary" title="{{ __('Включить/выключить') }}" data-bumi-toggle-button><i class="ti ti-{{ $file->enabled ? 'eye-off' : 'eye' }}"></i></button>
                                        </form>
                                        <form method="post" action="{{ route('bumi.settings.knowledge.item.delete', $file) }}" data-confirm="{{ __('Удалить файл из базы знаний Bumi?') }}" data-confirm-danger="1">
                                            @csrf
                                            <button class="btn btn-ghost-danger" title="{{ __('Удалить') }}"><i class="ti ti-trash"></i></button>
                                        </form>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                @include('partials.empty-state', [
                                    'icon' => 'file-upload',
                                    'title' => __('Файлов пока нет'),
                                    'text' => __('Загрузите прайс, инструкцию или справку — Bumi сможет отвечать по нему.'),
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-3 mb-0">
        <div class="d-flex align-items-start gap-2">
            <i class="ti ti-sparkles mt-1"></i>
            <div>
                <div class="fw-semibold">{{ __('Проверьте результат') }}</div>
                <div class="small">{{ __('Задайте вопрос в «Тесте ответов» — там видно, что Bumi нашёл в базе знаний.') }}</div>
            </div>
        </div>
        <a class="btn btn-sm btn-ghost-primary" href="{{ route('bumi.settings.agent', [$agent, 'tab' => 'playground']) }}">
            {{ __('Открыть тест ответов') }}<i class="ti ti-arrow-right ms-1"></i>
        </a>
    </div>
</div>
