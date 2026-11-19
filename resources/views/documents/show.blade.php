@extends('layouts.tabler')
@section('workspace_layout', 'record')

@section('title', $document->title)

@php
    $statusChip = [
        'draft' => 'crm-chip--grey',
        'submitted' => 'crm-chip--cyan',
        'review' => 'crm-chip--orange',
        'revision' => 'crm-chip--red',
        'approved' => 'crm-chip--cyan',
        'signing' => 'crm-chip--orange',
        'signed' => 'crm-chip--green',
        'registered' => 'crm-chip--green',
        'archived' => 'crm-chip--grey',
    ][$document->status->value] ?? 'crm-chip--grey';

    $confChip = [
        'public' => 'crm-chip--green',
        'internal' => 'crm-chip--cyan',
        'confidential' => 'crm-chip--orange',
        'secret' => 'crm-chip--red',
    ][$document->confidentiality->value] ?? 'crm-chip--grey';

    $approvalChips = [
        'approved' => 'crm-chip--green',
        'rejected' => 'crm-chip--red',
        'revision_requested' => 'crm-chip--orange',
        'skipped' => 'crm-chip--grey',
    ];

    $currentVersion = $document->currentVersion()->with('createdBy')->first();
    $isEditable = in_array($document->status->value, ['draft', 'revision'], true);
@endphp

@section('content')
{{-- ===== Шапка документа ===== --}}
<div class="crm-profile-hero card mb-3">
    <div class="card-body">
        <div class="d-flex flex-column flex-md-row align-items-md-start gap-3">
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="crm-chip crm-chip--grey">{{ $document->document_number }}</span>
                    <span class="crm-chip {{ $statusChip }}">{{ $crm_label('document_status', $document->status->value) }}</span>
                    <span class="crm-chip {{ $confChip }}">{{ $crm_label('confidentiality', $document->confidentiality->value) }}</span>
                    @if ($document->reg_number)
                        <span class="crm-chip crm-chip--cyan">рег. {{ $document->reg_number }}</span>
                    @endif
                </div>
                <h1 class="page-title mt-2 mb-1">{{ $document->title }}</h1>
                <div class="text-secondary">
                    <i class="ti ti-folder me-1"></i>{{ $document->category?->name ?? 'без категории' }}
                    <span class="mx-1">·</span>
                    <i class="ti ti-user me-1"></i>автор: {{ $document->author?->last_name }} {{ $document->author?->first_name }}
                    <span class="mx-1">·</span>
                    <i class="ti ti-history me-1"></i>обновлён {{ $document->updated_at?->format('d.m.Y H:i') }}
                </div>
            </div>
            <div class="d-flex gap-2 d-print-none">
                <a class="btn btn-ghost-secondary" href="{{ route('documents.index') }}"><i class="ti ti-arrow-left me-1"></i>К документам</a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    {{-- ===== Рабочая колонка ===== --}}
    <div class="col-lg-7">
        <div class="crm-form__section">
            <h3>Текущая версия</h3>
            <div class="crm-form__desc">Актуальное содержимое документа.</div>
            @if ($currentVersion !== null && $currentVersion->file_path !== null)
                <div class="d-flex align-items-center gap-2 fw-semibold">
                    <i class="ti ti-file"></i>{{ $currentVersion->file_name }}
                    <span class="text-secondary small fw-normal">{{ number_format((float) $currentVersion->size / 1024, 1) }} КБ</span>
                    <a href="{{ route('documents.version.file', [$document, $currentVersion]) }}" class="crm-link small" title="Скачать файл версии">
                        <i class="ti ti-download"></i>Скачать
                    </a>
                </div>
            @elseif ($currentVersion !== null || $document->body)
                @if (\App\Support\HtmlSanitizer::looksLikeHtml($document->body))
                    {{-- тело с форматированием (TinyMCE) — выводим безопасную разметку --}}
                    <div class="crm-doc-body">{!! \App\Support\HtmlSanitizer::sanitize($document->body) !!}</div>
                @else
                    <div class="crm-note" style="white-space: pre-wrap;">{{ $document->body ?? '—' }}</div>
                @endif
            @else
                <div class="crm-queue__sub">Версий нет.</div>
            @endif
            @if ($currentVersion !== null)
                <div class="text-secondary small mt-2">
                    sha256: <code>{{ Str::limit($currentVersion->sha256, 32, '…') }}</code>
                    @if ($currentVersion->immutable)
                        <span class="crm-chip crm-chip--green ms-1">неизменяемая</span>
                    @endif
                </div>
            @endif
        </div>

        <div class="crm-form__section">
            <h3>Версии <span class="text-secondary fw-normal">({{ $document->versions->count() }})</span></h3>
            <div class="crm-form__desc">История правок с хешем целостности.</div>
            <div class="table-responsive">
                <table data-record-key="0" class="table table-hover table-vcenter card-table mb-0">
                    <thead><tr><th scope="col">#</th><th scope="col">Автор</th><th scope="col">sha256</th><th scope="col">Комментарий</th><th scope="col">Когда</th></tr></thead>
                    <tbody>
                        @forelse ($document->versions as $version)
                            <tr>
                                <td>
                                    <strong>v{{ $version->version }}</strong>
                                    @if ($document->current_version_id === $version->id)
                                        <span class="crm-chip crm-chip--green ms-1">текущая</span>
                                    @endif
                                </td>
                                <td>{{ $version->createdBy?->last_name ?? '—' }}</td>
                                <td class="text-secondary"><code class="small">{{ Str::limit($version->sha256, 16, '…') }}</code></td>
                                <td class="text-secondary">{{ Str::limit($version->change_note ?? '', 60) }}
                                    @if ($version->file_path !== null && $version->file_path !== '')
                                        <a href="{{ route('documents.version.file', [$document, $version]) }}" class="ms-1" title="Скачать файл этой версии"><i class="ti ti-download"></i></a>
                                    @endif
                                </td>
                                <td class="text-secondary crm-table-num">{{ $version->created_at?->format('d.m H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-secondary">Версий нет</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($isEditable)
                <form method="post" action="{{ route('documents.version', $document) }}" enctype="multipart/form-data" class="crm-form__row mt-3 mb-0" data-submit-guard>
                    @csrf
                    <div class="crm-form__field">
                        <label class="form-label" for="dv-file">Новый файл<span class="req">*</span></label>
                        <input type="file" id="dv-file" name="file" class="form-control" required>
                        <div class="form-text">Файл новой версии документа</div>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="dv-note">Комментарий к версии</label>
                        <input type="text" id="dv-note" name="change_note" class="form-control" placeholder="Что изменилось">
                    </div>
                    <div class="crm-form__field d-flex align-items-end">
                        <button class="btn btn-primary w-100 justify-content-center"><i class="ti ti-plus me-1"></i>Версия</button>
                    </div>
                </form>
            @endif
        </div>

        <div class="crm-form__section">
            <h3>Согласование</h3>
            <div class="crm-form__desc">
                @if ($document->workflowState)
                    Маршрут «{{ $document->workflowState->workflow?->name }}» · шаг {{ $document->workflowState->current_step }} · состояние: {{ $document->workflowState->state }}
                @else
                    Маршрут не назначен.
                @endif
            </div>

            @if ($document->approvals->isNotEmpty())
                <div class="crm-queue mb-3">
                    @foreach ($document->approvals as $approval)
                        <div class="crm-queue__item" style="--c: {{ $approval->status->value === 'approved' ? 'var(--crm-success)' : ($approval->status->value === 'rejected' ? 'var(--crm-danger)' : 'var(--crm-warning)') }};">
                            <div class="crm-queue__body">
                                <div class="crm-queue__title">
                                    <strong>{{ $approval->workflowStep?->name ?? 'шаг' }}</strong>
                                    @if ($approval->approver) — {{ $approval->approver->last_name }}@endif
                                </div>
                                @if ($approval->comment)
                                    <div class="crm-queue__sub">«{{ Str::limit($approval->comment, 70) }}»</div>
                                @endif
                            </div>
                            <span class="crm-chip {{ $approvalChips[$approval->status->value] ?? 'crm-chip--cyan' }}">{{ $approval->status->value }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="crm-queue__sub mb-3">Согласований пока нет — документ ждёт отправки на маршрут.</div>
            @endif

            <div class="d-flex gap-2 flex-wrap">
                @if ($canSubmit && $isEditable)
                    <form method="post" action="{{ route('documents.submit', $document) }}" class="d-inline" data-submit-guard>
                        @csrf
                        <button class="btn btn-primary"><i class="ti ti-send me-1"></i>Отправить на согласование</button>
                    </form>
                @endif
                @if ($canDecide && $document->status->value === 'review')
                    <form method="post" action="{{ route('documents.decide', $document) }}" class="d-inline-flex gap-2" data-submit-guard>
                        @csrf
                        <input type="text" name="comment" class="form-control" placeholder="Комментарий (для отказа обязателен)" aria-label="Комментарий решения" style="min-width: 14rem;">
                        <button class="btn btn-success" name="decision" value="approved"><i class="ti ti-check me-1"></i>Согласовать</button>
                        <button class="btn btn-ghost-danger" name="decision" value="rejected">Отклонить</button>
                    </form>
                @endif
                @if ($document->status->value === 'approved' && $canRegister)
                    <form method="post" action="{{ route('documents.register', $document) }}" class="d-inline" data-submit-guard>
                        @csrf
                        <button class="btn btn-outline-primary"><i class="ti ti-stamp me-1"></i>Зарегистрировать</button>
                    </form>
                @endif
                @if ($canArchive && in_array($document->status->value, ['registered', 'signed'], true))
                    <form method="post" action="{{ route('documents.archive', $document) }}" class="d-inline" data-submit-guard
                          onsubmit="return confirm('Отправить документ «{{ $document->title }}» в архив? Восстановить его можно будет только через администратора.')">
                        @csrf
                        <button class="btn btn-ghost-secondary"><i class="ti ti-archive me-1"></i>В архив</button>
                    </form>
                @endif
            </div>
        </div>

        @if ($history->isNotEmpty())
            <div class="crm-form__section">
                <h3>История согласования</h3>
                <div class="crm-form__desc">Единый журнал переходов статусной машины.</div>
                <div class="crm-timeline">
                    @foreach ($history as $transition)
                        <div class="crm-timeline__item">
                            <div class="crm-timeline__time">{{ $transition->created_at->format('d.m.Y H:i') }}</div>
                            <div class="crm-timeline__title"><strong>{{ $crm_label('workflow_action', $transition->action) }}</strong></div>
                            <div class="crm-timeline__text">
                                {{ $transition->actor?->name ?? 'Система' }}
                                <span class="mx-1">·</span>
                                @if ($transition->from_status !== null)
                                    {{ $crm_label('document_status', $transition->from_status) }} → {{ $crm_label('document_status', $transition->to_status) }}
                                @else
                                    {{ $crm_label('document_status', $transition->to_status) }}
                                @endif
                                @if ($transition->comment)
                                    <span class="mx-1">·</span>«{{ Str::limit($transition->comment, 70) }}»
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- ===== Контекстная колонка ===== --}}
    <div class="col-lg-5">
        <div class="crm-form__section">
            <h3>Электронная подпись</h3>
            <div class="crm-form__desc">Запросы подписи и ваша подпись по документу.</div>

            @if ($mySignatureRequest !== null && in_array($mySignatureRequest->status->value, ['pending', 'partial'], true))
                <div class="alert alert-primary d-flex align-items-center gap-2">
                    <span class="crm-live-dot"></span>
                    <div>Документ ждёт вашей подписи @if ($mySignatureRequest->requires_otp)(с OTP-кодом)@endif.</div>
                </div>
                @if ($canSign)
                    <form method="post" action="{{ route('documents.signature.sign', [$document, $mySignatureRequest]) }}" class="crm-form__row" data-submit-guard>
                        @csrf
                        <div class="crm-form__field">
                            <label class="form-label" for="ds-otp">{{ $mySignatureRequest->requires_otp ? 'OTP-код' : 'Код подтверждения' }}</label>
                            <input type="text" id="ds-otp" class="form-control" name="otp_code" placeholder="из SMS/почты">
                        </div>
                        <div class="crm-form__field d-flex align-items-end">
                            <button class="btn btn-primary w-100 justify-content-center"><i class="ti ti-signature me-1"></i>Подписать</button>
                        </div>
                    </form>
                    <form method="post" action="{{ route('documents.signature.decline', [$document, $mySignatureRequest]) }}" class="d-flex gap-2 mt-2" data-submit-guard>
                        @csrf
                        <input type="text" name="reason" class="form-control" placeholder="Причина отказа" required aria-label="Причина отказа">
                        <button class="btn btn-ghost-danger text-nowrap">Отказаться</button>
                    </form>
                @endif
            @endif

            @if ($canRequestSignature && in_array($document->status->value, ['approved', 'signing'], true))
                <form method="post" action="{{ route('documents.signature.request', $document) }}" class="mt-3 pt-3 border-top" data-submit-guard>
                    @csrf
                    <div class="crm-form__field">
                        <label class="form-label" for="ds-signers">Кого запросить подписать</label>
                        <select id="ds-signers" name="signer_ids[]" class="form-select" multiple size="4" required>
                            @foreach ($employees as $option)
                                <option value="{{ $option->id }}">{{ $option->last_name }} {{ $option->first_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="requires_otp" value="1" id="requiresOtp">
                        <label class="form-check-label text-secondary" for="requiresOtp">Требовать OTP-код при подписи</label>
                    </div>
                    <div class="mt-2">
                        <button class="btn btn-outline-primary"><i class="ti ti-send me-1"></i>Запросить подписи</button>
                    </div>
                </form>
            @endif
        </div>

        @if ($canViewSignatures ?? true)
            <div class="crm-form__section">
                <h3>Подписи <span class="text-secondary fw-normal">({{ $signatures->count() }})</span></h3>
                <div class="crm-form__desc">Совершённые подписи и их статус.</div>
                <div class="table-responsive">
                    <table data-record-key="0" class="table table-hover table-vcenter card-table mb-0">
                        <thead><tr><th scope="col">Кто</th><th scope="col">Статус</th><th scope="col">Когда</th><th scope="col"></th></tr></thead>
                        <tbody>
                            @forelse ($signatures as $signature)
                                <tr>
                                    <td>
                                        <div class="crm-table__main">{{ $signature->signer?->last_name }} {{ $signature->signer?->first_name }}</div>
                                        <div class="crm-table__sub"><code class="small">{{ Str::limit($signature->signature_id, 14, '…') }}</code></div>
                                    </td>
                                    <td>
                                        <span class="crm-chip {{ [
                                            'valid' => 'crm-chip--green',
                                            'revoked' => 'crm-chip--orange',
                                        ][$signature->status->value] ?? 'crm-chip--red' }}">{{ $crm_label('signature_request_status', $signature->status->value) }}</span>
                                    </td>
                                    <td class="text-secondary crm-table-num">{{ $signature->signed_at?->format('d.m H:i') }}</td>
                                    <td class="text-end text-nowrap">
                                        @if ($canVerify)
                                            <form method="post" action="{{ route('documents.signature.verify', $signature) }}" class="d-inline" data-submit-guard>
                                                @csrf
                                                <button class="btn btn-sm btn-ghost-secondary" title="Проверить подпись"><i class="ti ti-shield-check"></i></button>
                                            </form>
                                        @endif
                                        @if ($canSign && $signature->status->value === 'valid')
                                            <form method="post" action="{{ route('documents.signature.revoke', $signature) }}" class="d-inline" data-submit-guard>
                                                @csrf
                                                <button class="btn btn-sm btn-ghost-danger" title="Отозвать подпись"><i class="ti ti-x"></i></button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-secondary">Подписей нет</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
