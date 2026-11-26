@extends('layouts.tabler')
@section('workspace_layout', 'record')

@section('title', $document->title)

@push('styles')
    <style>
        .signature-qr-panel { border: 1px dashed var(--tblr-primary, #206bc4); border-radius: 12px; padding: 14px; background: var(--tblr-bg-surface-secondary, rgba(32, 107, 196, .04)); }
        .signature-qr-panel svg { max-width: 100%; height: auto; background: #fff; border-radius: 8px; padding: 6px; }
        .signature-qr-quick__icon {
            width: 40px; height: 40px; flex: 0 0 40px; border-radius: 12px;
            display: grid; place-items: center; font-size: 20px;
            background: rgba(32, 107, 196, .12); color: var(--tblr-primary, #206bc4);
        }
        .signature-qr-steps { padding-left: 20px; margin-bottom: 0; color: var(--tblr-secondary, #7c8693); font-size: .875rem; line-height: 1.7; }
        .signature-qr-code { letter-spacing: .16em; font-variant-numeric: tabular-nums; }
    </style>
@endpush

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
            @if ($document->photo_path)
                @include('partials.entity-photo-display', ['photoPath' => $document->photo_path])
            @endif
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="crm-chip crm-chip--grey">{{ $document->document_number }}</span>
                    <span class="crm-chip {{ $statusChip }}">{{ $crm_label('document_status', $document->status->value) }}</span>
                    <span class="crm-chip {{ $confChip }}">{{ $crm_label('confidentiality', $document->confidentiality->value) }}</span>
                    @if ($document->reg_number)
                        <span class="crm-chip crm-chip--cyan">{{ __('рег') }} {{ $document->reg_number }}</span>
                    @endif
                </div>
                <h1 class="page-title mt-2 mb-1">{{ $document->title }}</h1>
                <div class="text-secondary">
                    <i class="ti ti-folder me-1"></i>{{ $document->category?->name ?? __('без категории') }}
                    <span class="mx-1">·</span>
                    <i class="ti ti-user me-1"></i>{{ __('автор') }} {{ $document->author?->last_name }} {{ $document->author?->first_name }}
                    <span class="mx-1">·</span>
                    <i class="ti ti-history me-1"></i>{{ __('обновлён') }} {{ $document->updated_at?->format('d.m.Y H:i') }}
                </div>
            </div>
            <div class="d-flex gap-2 d-print-none">
                <a class="btn btn-ghost-secondary" href="{{ route('documents.index') }}"><i class="ti ti-arrow-left me-1"></i>{{ __('К документам') }}</a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    {{-- ===== Рабочая колонка ===== --}}
    <div class="col-lg-7">
        <div class="crm-form__section">
            <h3>{{ __('Текущая версия') }}</h3>
            <div class="crm-form__desc">{{ __('Актуальное содержимое документа') }}</div>
            @if ($currentVersion !== null && $currentVersion->file_path !== null)
                <div class="d-flex align-items-center gap-2 fw-semibold">
                    <i class="ti ti-file"></i>{{ $currentVersion->file_name }}
                    <span class="text-secondary small fw-normal">{{ number_format((float) $currentVersion->size / 1024, 1) }} {{ __('КБ') }}</span>
                    <a href="{{ route('documents.version.file', [$document, $currentVersion]) }}" class="crm-link small" title="{{ __("Скачать файл версии") }}">
                        <i class="ti ti-download"></i>{{ __('Скачать') }}
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
                <div class="crm-queue__sub">{{ __('Версий нет') }}</div>
            @endif
            @if ($currentVersion !== null)
                <div class="text-secondary small mt-2">
                    sha256: <code>{{ Str::limit($currentVersion->sha256, 32, '…') }}</code>
                    @if ($currentVersion->immutable)
                        <span class="crm-chip crm-chip--green ms-1">{{ __('неизменяемая') }}</span>
                    @endif
                </div>
            @endif
        </div>

        <div class="crm-form__section">
            <h3>{{ __('Версии') }} <span class="text-secondary fw-normal">({{ $document->versions->count() }})</span></h3>
            <div class="crm-form__desc">{{ __('История правок с хешем целостности') }}</div>
            <div class="table-responsive">
                <table data-record-key="0" class="table table-hover table-vcenter card-table mb-0">
                    <thead><tr><th scope="col">#</th><th scope="col">{{ __('Автор') }}</th><th scope="col">sha256</th><th scope="col">{{ __('Комментарий') }}</th><th scope="col">{{ __('Когда') }}</th></tr></thead>
                    <tbody>
                        @forelse ($document->versions as $version)
                            <tr>
                                <td>
                                    <strong>v{{ $version->version }}</strong>
                                    @if ($document->current_version_id === $version->id)
                                        <span class="crm-chip crm-chip--green ms-1">{{ __('текущая') }}</span>
                                    @endif
                                </td>
                                <td>{{ $version->createdBy?->last_name ?? '—' }}</td>
                                <td class="text-secondary"><code class="small">{{ Str::limit($version->sha256, 16, '…') }}</code></td>
                                <td class="text-secondary">{{ Str::limit($version->change_note ?? '', 60) }}
                                    @if ($version->file_path !== null && $version->file_path !== '')
                                        <a href="{{ route('documents.version.file', [$document, $version]) }}" class="ms-1" title="{{ __("Скачать файл этой версии") }}"><i class="ti ti-download"></i></a>
                                    @endif
                                </td>
                                <td class="text-secondary crm-table-num">{{ $version->created_at?->format('d.m H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-secondary">{{ __('Версий нет') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($isEditable)
                <form method="post" action="{{ route('documents.version', $document) }}" enctype="multipart/form-data" class="crm-form__row mt-3 mb-0" data-submit-guard>
                    @csrf
                    <div class="crm-form__field">
                        <label class="form-label" for="dv-file">{{ __('Новый файл') }}<span class="req">*</span></label>
                        <input type="file" id="dv-file" name="file" class="form-control" required>
                        <div class="form-text">{{ __('Файл новой версии документа') }}</div>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="dv-note">{{ __('Комментарий к версии') }}</label>
                        <input type="text" id="dv-note" name="change_note" class="form-control" placeholder="{{ __("Что изменилось") }}">
                    </div>
                    <div class="crm-form__field d-flex align-items-end">
                        <button class="btn btn-primary w-100 justify-content-center"><i class="ti ti-plus me-1"></i>{{ __('Версия') }}</button>
                    </div>
                </form>
            @endif
        </div>

        <div class="crm-form__section">
            <h3>{{ __('Согласование') }}</h3>
            <div class="crm-form__desc">
                @if ($document->workflowState)
                    {{ __('Маршрут') }} «{{ $document->workflowState->workflow?->name }}{{ __('шаг') }} {{ $document->workflowState->current_step }} {{ __('состояние') }} {{ $document->workflowState->state }}
                @else
                    {{ __('Маршрут не назначен') }}
                @endif
            </div>

            @if ($document->approvals->isNotEmpty())
                <div class="crm-queue mb-3">
                    @foreach ($document->approvals as $approval)
                        <div class="crm-queue__item" style="--c: {{ $approval->status->value === 'approved' ? 'var(--crm-success)' : ($approval->status->value === 'rejected' ? 'var(--crm-danger)' : 'var(--crm-warning)') }};">
                            <div class="crm-queue__body">
                                <div class="crm-queue__title">
                                    <strong>{{ $approval->workflowStep?->name ?? __('шаг') }}</strong>
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
                <div class="crm-queue__sub mb-3">{{ __('Согласований пока нет — документ ждёт отправки на маршрут') }}</div>
            @endif

            <div class="d-flex gap-2 flex-wrap">
                @if ($canSubmit && $isEditable)
                    <form method="post" action="{{ route('documents.submit', $document) }}" class="d-inline" data-submit-guard>
                        @csrf
                        <button class="btn btn-primary"><i class="ti ti-send me-1"></i>{{ __('Отправить на согласование') }}</button>
                    </form>
                @endif
                @if ($canDecide && $document->status->value === 'review')
                    <form method="post" action="{{ route('documents.decide', $document) }}" class="d-inline-flex gap-2" data-submit-guard>
                        @csrf
                        <input type="text" name="comment" class="form-control" placeholder="{{ __("Комментарий (для отказа обязателен)") }}" aria-label="{{ __("Комментарий решения") }}" style="min-width: 14rem;">
                        <button class="btn btn-success" name="decision" value="approved"><i class="ti ti-check me-1"></i>{{ __('Согласовать') }}</button>
                        <button class="btn btn-ghost-danger" name="decision" value="rejected">{{ __('Отклонить') }}</button>
                    </form>
                @endif
                @if ($document->status->value === 'approved' && $canRegister)
                    <form method="post" action="{{ route('documents.register', $document) }}" class="d-inline" data-submit-guard>
                        @csrf
                        <button class="btn btn-outline-primary"><i class="ti ti-stamp me-1"></i>{{ __('Зарегистрировать') }}</button>
                    </form>
                @endif
                @if ($canArchive && in_array($document->status->value, ['registered', 'signed'], true))
                    <form method="post" action="{{ route('documents.archive', $document) }}" class="d-inline" data-submit-guard data-confirm="{{ __('Отправить документ') }} «{{ $document->title }}» {{ __('в архив? Восстановить его можно будет только через администратора') }}." data-confirm-danger="1">
                        @csrf
                        <button class="btn btn-ghost-secondary"><i class="ti ti-archive me-1"></i>{{ __('В архив') }}</button>
                    </form>
                @endif
            </div>
        </div>

        @if ($history->isNotEmpty())
            <div class="crm-form__section">
                <h3>{{ __('История согласования') }}</h3>
                <div class="crm-form__desc">{{ __('Единый журнал переходов статусной машины') }}</div>
                <div class="crm-timeline">
                    @foreach ($history as $transition)
                        <div class="crm-timeline__item">
                            <div class="crm-timeline__time">{{ $transition->created_at->format('d.m.Y H:i') }}</div>
                            <div class="crm-timeline__title"><strong>{{ $crm_label('workflow_action', $transition->action) }}</strong></div>
                            <div class="crm-timeline__text">
                                {{ $transition->actor?->name ?? __('Система') }}
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
            <h3>{{ __('Электронная подпись') }}</h3>
            <div class="crm-form__desc">{{ __('Один QR — одна подпись. Код подтверждения придёт в уведомлении.') }}</div>

            {{-- Быстрая подпись: одна кнопка и три понятных шага --}}
            @if ($canSign && ! in_array($document->status->value, ['signed', 'archived'], true))
                <div class="signature-qr-quick mt-3 pt-3 border-top">
                    <div class="d-flex align-items-start gap-3">
                        <span class="signature-qr-quick__icon"><i class="ti ti-qrcode"></i></span>
                        <div>
                            <div class="fw-semibold">{{ __('Подпись за 3 шага') }}</div>
                            <ol class="signature-qr-steps mt-2 mb-3">
                                <li>{{ __('Нажмите «Подписать по QR»') }}</li>
                                <li>{{ __('Отсканируйте QR телефоном или откройте ссылку') }}</li>
                                <li>{{ __('Введите код из уведомления и подтвердите') }}</li>
                            </ol>
                        </div>
                    </div>
                    <form method="post" action="{{ route('documents.signature.qr', $document) }}" data-submit-guard>
                        @csrf
                        <button class="btn btn-primary w-100 justify-content-center" type="submit">
                            <i class="ti ti-qrcode me-1"></i>{{ __('Подписать по QR') }}
                        </button>
                    </form>

                    @if (session('signature_qr'))
                        @php $qr = session('signature_qr'); @endphp
                        <div class="signature-qr-panel mt-3 text-center">
                            <div class="d-flex justify-content-between align-items-center mb-2 gap-2 text-start">
                                <strong class="small">{{ __('QR-код подписи') }}</strong>
                                <span class="text-secondary small">{{ $qr['expires_at'] }}</span>
                            </div>
                            {!! $qr['svg'] !!}
                            <a class="small d-block mt-2" href="{{ $qr['url'] }}" target="_blank" rel="noopener">{{ __('Открыть ссылку на телефоне') }}</a>
                            <div class="alert alert-info small mt-3 mb-0 text-start">
                                <div class="text-secondary">{{ __('Код подтверждения (6 цифр)') }}</div>
                                <div class="small mt-1">{{ __('Код отправлен подписанту в уведомлении и в истории уведомлений документа') }}</div>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            @if ($mySignatureRequest !== null && in_array($mySignatureRequest->status->value, ['pending', 'partial'], true))
                <div class="alert alert-primary d-flex align-items-center gap-2">
                    <span class="crm-live-dot"></span>
                    <div>{{ __('Документ ждёт вашей подписи') }} @if ($mySignatureRequest->requires_otp)({{ __('с') }} OTP-{{ __('кодом') }})@endif.</div>
                </div>
                @if ($canSign)
                    <form method="post" action="{{ route('documents.signature.sign', [$document, $mySignatureRequest]) }}" class="crm-form__row" data-submit-guard>
                        @csrf
                        <div class="crm-form__field">
                            <label class="form-label" for="ds-otp">{{ $mySignatureRequest->requires_otp ? __('OTP-код') : __('Код подтверждения') }}</label>
                            <input type="text" id="ds-otp" class="form-control" name="otp_code" placeholder="{{ __("из SMS/почты") }}">
                        </div>
                        <div class="crm-form__field d-flex align-items-end">
                            <button class="btn btn-primary w-100 justify-content-center"><i class="ti ti-signature me-1"></i>{{ __('Подписать') }}</button>
                        </div>
                    </form>
                    <form method="post" action="{{ route('documents.signature.decline', [$document, $mySignatureRequest]) }}" class="d-flex gap-2 mt-2" data-submit-guard>
                        @csrf
                        <input type="text" name="reason" class="form-control" placeholder="{{ __("Причина отказа") }}" required aria-label="{{ __("Причина отказа") }}">
                        <button class="btn btn-ghost-danger text-nowrap">{{ __('Отказаться') }}</button>
                    </form>
                @endif
            @endif

            @if ($canRequestSignature && in_array($document->status->value, ['approved', 'signing'], true))
                <details class="mt-3 pt-3 border-top">
                    <summary class="small text-secondary">{{ __('Запросить подписи у коллег') }}</summary>
                    <form method="post" action="{{ route('documents.signature.request', $document) }}" class="mt-3" data-submit-guard>
                        @csrf
                        <div class="crm-form__field">
                            <label class="form-label" for="ds-signers">{{ __('Кого запросить подписать') }}</label>
                            <select id="ds-signers" name="signer_ids[]" class="form-select" multiple size="4" required>
                                @foreach ($employees as $option)
                                    <option value="{{ $option->id }}">{{ $option->last_name }} {{ $option->first_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="requires_otp" value="1" id="requiresOtp">
                            <label class="form-check-label text-secondary" for="requiresOtp">{{ __('Требовать OTP-код при подписи') }}</label>
                        </div>
                        <div class="mt-2">
                            <button class="btn btn-outline-primary"><i class="ti ti-send me-1"></i>{{ __('Запросить подписи') }}</button>
                        </div>
                    </form>
                </details>
            @endif
        </div>

        @if ($canViewSignatures ?? true)
            <div class="crm-form__section">
                <h3>{{ __('Подписи') }} <span class="text-secondary fw-normal">({{ $signatures->count() }})</span></h3>
                <div class="crm-form__desc">{{ __('Совершённые подписи и их статус') }}</div>
                <div class="table-responsive">
                    <table data-record-key="0" class="table table-hover table-vcenter card-table mb-0">
                        <thead><tr><th scope="col">{{ __('Кто') }}</th><th scope="col">{{ __('Статус') }}</th><th scope="col">{{ __('Когда') }}</th><th scope="col"></th></tr></thead>
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
                                        ][$signature->status->value] ?? 'crm-chip--red' }}">{{ $crm_label('signature_status', $signature->status->value) }}</span>
                                    </td>
                                    <td class="text-secondary crm-table-num">{{ $signature->signed_at?->format('d.m H:i') }}</td>
                                    <td class="text-end text-nowrap">
                                        @if ($canVerify)
                                            <form method="post" action="{{ route('documents.signature.verify', $signature) }}" class="d-inline" data-submit-guard>
                                                @csrf
                                                <button class="btn btn-sm btn-ghost-secondary" title="{{ __("Проверить подпись") }}"><i class="ti ti-shield-check"></i></button>
                                            </form>
                                        @endif
                                        @if ($canRevoke && $signature->status->value === 'valid')
                                            <form method="post" action="{{ route('documents.signature.revoke', $signature) }}" class="d-inline" data-submit-guard>
                                                @csrf
                                                <button class="btn btn-sm btn-ghost-danger" title="{{ __("Отозвать подпись") }}"><i class="ti ti-x"></i></button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-secondary">{{ __('Подписей нет') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('click', function (event) {
        var button = event.target && event.target.closest ? event.target.closest('[data-copy-code]') : null;
        if (!button || !navigator.clipboard) { return; }
        navigator.clipboard.writeText(button.getAttribute('data-copy-code') || '').then(function () {
            if (window.crmToast) { window.crmToast(@json(__('Код скопирован')), 'check'); }
        });
    });
</script>
@endpush
