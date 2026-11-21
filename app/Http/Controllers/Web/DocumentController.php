<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Documents\Services\DocumentService;
use App\Domains\Documents\Services\SignatureService;
use App\Domains\Workflow\WorkflowEngine;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\DocumentSignatureRequest;
use App\Models\DocumentVersion;
use App\Models\Employee;
use App\Shared\Enums\DocumentType;
use App\Support\PermissionService;
use App\Support\SettingsService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Панель управления документами (п.51–63 ТЗ): списки, карточка, версия, согласование, регистрация, подписи, архив.
 */
class DocumentController extends Controller
{
    public function __construct(
        protected DocumentService $documents,
        protected SignatureService $signatures,
        protected PermissionService $permissions,
        protected AuditService $audit,
        protected WorkflowEngine $engine,
    ) {}

    /* -------------------- списки -------------------- */

    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        if ($me === null) {
            abort(403, __('Нет карточки сотрудника'));
        }

        $scope = $this->permissions->can($request->user(), 'documents.view');
        \assert($scope !== false);

        // межтенантная изоляция: список только своей компании (company-scope
        // подразумевает «вся своя компания», а не «все компании»)
        $query = Document::query()->where('company_id', $me->company_id)->with(['category', 'author']);

        if ($scope->value === 'own') {
            $query->where(function ($q) use ($me) {
                $q->where('author_id', $me->id)
                    ->orWhereHas('signatureRequests', fn ($r) => $r->where('signer_id', $me->id));
            });
        } elseif ($scope->value === 'department') {
            $query->where(function ($q) use ($me) {
                $q->where('department_id', $me->department_id)
                    ->orWhere('author_id', $me->id);
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Поиск по номеру/названию/регномеру (в рамках своей компании и scope)
        $search = trim((string) $request->input('q'));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('document_number', 'like', "%{$search}%")
                    ->orWhere('reg_number', 'like', "%{$search}%");
            });
        }

        // Метрики считаются по всем записям scope, а не по текущей странице
        // (и без учёта выбранного статуса — иначе цифры «врать» при фильтре)
        $metricsSource = Document::query()->where('company_id', $me->company_id);
        if ($scope->value === 'own') {
            $metricsSource->where(function ($q) use ($me) {
                $q->where('author_id', $me->id)
                    ->orWhereHas('signatureRequests', fn ($r) => $r->where('signer_id', $me->id));
            });
        } elseif ($scope->value === 'department') {
            $metricsSource->where(function ($q) use ($me) {
                $q->where('department_id', $me->department_id)
                    ->orWhere('author_id', $me->id);
            });
        }

        $byStatus = $metricsSource->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $metrics = [
            ['label' => __('Всего документов'), 'value' => (int) $byStatus->sum(), 'icon' => 'file-text', 'tone' => '#0b9b72'],
            ['label' => __('На согласовании'), 'value' => (int) ($byStatus['submitted'] ?? 0) + (int) ($byStatus['review'] ?? 0) + (int) ($byStatus['revision'] ?? 0), 'icon' => 'checklist', 'tone' => '#f76707'],
            ['label' => __('Подписано и зарегистрировано'), 'value' => (int) ($byStatus['signed'] ?? 0) + (int) ($byStatus['registered'] ?? 0), 'icon' => 'signature', 'tone' => '#4299e1'],
            ['label' => __('В архиве'), 'value' => (int) ($byStatus['archived'] ?? 0), 'icon' => 'archive', 'tone' => '#6c7a91'],
        ];

        $documents = $query->orderByDesc('id')->paginate(25)->withQueryString();
        $statuses = ['draft', 'submitted', 'review', 'revision', 'approved', 'signing', 'signed', 'registered', 'archived'];

        return view('documents.index', compact('documents', 'statuses', 'status', 'metrics', 'search'));
    }

    public function mine(Request $request): View
    {
        return $this->index($request);
    }

    /* -------------------- карточка -------------------- */

    public function show(Request $request, Document $document): View
    {
        $document->load([
            'category', 'author', 'versions.createdBy', 'workflowState.workflow',
            'approvals.approver', 'signatureRequests.signer',
        ]);

        $me = $request->user()->employee;
        if ($me === null) {
            abort(403, __('Нет карточки сотрудника'));
        }
        TenantGuard::ensureDocument($document, $me);

        $this->ensureVisible($request, $document, $me);

        $canSubmit = $this->permissions->check($request->user(), 'documents.submit');
        $canDecide = $this->permissions->check($request->user(), 'documents.approve');
        $canRegister = $this->permissions->check($request->user(), 'documents.approve');
        $canRequestSignature = $this->permissions->check($request->user(), 'signatures.request');
        $canSign = $this->permissions->check($request->user(), 'signatures.sign');
        $canArchive = $this->permissions->check($request->user(), 'documents.archive');
        $canVerify = $this->permissions->check($request->user(), 'signatures.verify');

        $mySignatureRequest = $document->signatureRequests
            ->firstWhere('signer_id', $me->id)
            ?->loadMissing('documentVersion');
        \assert($mySignatureRequest === null || $mySignatureRequest instanceof DocumentSignatureRequest);

        $signatures = DocumentSignature::query()
            ->where('document_id', $document->id)
            ->with('signer')
            ->get();

        // блок подписей виден только holders signatures.view (signer документа всегда видит свой блок)
        $canViewSignatures = $this->permissions->check($request->user(), 'signatures.view')
            || $mySignatureRequest !== null;

        $employees = Employee::query()
            ->where('company_id', $me->company_id)
            ->where('status', 'active')
            ->orderBy('last_name')
            ->get();

        // единый журнал переходов статусной машины (Этап B)
        $history = $this->engine->history(WorkflowEngine::SUBJECT_DOCUMENT, $document->id);

        return view('documents.show', compact(
            'document', 'canSubmit', 'canDecide', 'canRegister', 'canRequestSignature',
            'canSign', 'canArchive', 'canVerify', 'mySignatureRequest', 'signatures', 'employees', 'me',
            'canViewSignatures', 'history',
        ));
    }

    /* -------------------- создание -------------------- */

    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        if ($me === null) {
            abort(403, __('Нет карточки сотрудника'));
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'exists:document_categories,id'],
            'body' => ['nullable', 'string'],
            'file' => ['nullable', 'file', 'max:'.(int) app(SettingsService::class)->get('corpms.documents.max_file_size_mb', 25) * 1024,
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,rtf,odt,ods,jpg,jpeg,png,webp,zip,7z'],

            // значения — только из enum DocumentType (раньше 'order'/'contract' проходили
            // валидацию и падали при записи в enum-колонку/каст модели)
            'type' => ['nullable', Rule::enum(DocumentType::class)],
            'priority' => ['nullable', 'in:low,medium,high,critical'],
            'confidentiality' => ['nullable', 'in:public,internal,confidential,secret'],
        ]);

        $document = $this->documents->create($me, $validated, $request->file('file'));

        return redirect()->route('documents.show', $document)->with('status', 'Документ создан: '.$document->document_number);
    }

    /** Новая версия (документооборот с контролем целостности, п.51 ТЗ). */
    public function addVersion(Request $request, Document $document): RedirectResponse
    {
        $me = $request->user()->employee;
        if ($me === null) {
            abort(403, __('Нет карточки сотрудника'));
        }
        TenantGuard::ensureDocument($document, $me);

        if ((int) $document->author_id !== (int) $me->id
            && $this->permissions->check($request->user(), 'documents.edit') === false) {
            throw new LogicException(__('Новую версию может загрузить автор или документы.edit'));
        }

        $validated = $request->validate([
            'body' => ['nullable', 'string'],
            'file' => ['nullable', 'file', 'required_without:body', 'max:'.(int) app(SettingsService::class)->get('corpms.documents.max_file_size_mb', 25) * 1024,
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,rtf,odt,ods,jpg,jpeg,png,webp,zip,7z'],
            'change_note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->documents->addVersion($document, $me, $request->file('file'), $validated['body'] ?? null, $validated['change_note'] ?? null);

        return back()->with('status', __('Новая версия добавлена'));
    }

    /* -------------------- согласование -------------------- */

    public function submit(Request $request, Document $document): RedirectResponse
    {
        $me = $request->user()->employee;
        if ($me === null) {
            abort(403, __('Нет карточки сотрудника'));
        }
        TenantGuard::ensureDocument($document, $me);

        try {
            $this->documents->submit($me, $document);
        } catch (LogicException $e) {
            return back()->withErrors(['field' => $e->getMessage()]);
        }

        return back()->with('status', __('Документ отправлен на согласование'));
    }

    public function decide(Request $request, Document $document): RedirectResponse
    {
        $me = $request->user()->employee;
        if ($me === null) {
            abort(403, __('Нет карточки сотрудника'));
        }
        TenantGuard::ensureDocument($document, $me);

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            if ($validated['decision'] === 'approved') {
                $this->documents->approve($me, $document, $validated['comment'] ?? null);
                $message = __('Шаг согласования пройден');
            } else {
                // отказ — отдельное право (documents.reject), маршрут гейтит только approve
                if (app(PermissionService::class)->check($request->user(), 'documents.reject') === false) {
                    abort(403, __('Нет права отказа в согласовании'));
                }
                if ($validated['comment'] === null || trim($validated['comment']) === '') {
                    return back()->withErrors(['comment' => __('Отказ требует комментария')]);
                }
                $this->documents->reject($me, $document, $validated['comment']);
                $message = __('Документ отклонён — отправлен автору на доработку');
            }
        } catch (LogicException $e) {
            return back()->withErrors(['field' => $e->getMessage()]);
        }

        return back()->with('status', $message);
    }

    public function register(Document $document): RedirectResponse
    {
        $me = auth()->user()->employee;
        if ($me === null) {
            abort(403, __('Нет карточки сотрудника'));
        }
        TenantGuard::ensureDocument($document, $me);

        try {
            $this->documents->register($me, $document);
        } catch (LogicException $e) {
            return back()->withErrors(['field' => $e->getMessage()]);
        }

        return back()->with('status', __('Документ зарегистрирован'));
    }

    public function archive(Document $document): RedirectResponse
    {
        $me = auth()->user()->employee;
        if ($me === null) {
            abort(403, __('Нет карточки сотрудника'));
        }
        TenantGuard::ensureDocument($document, $me);

        try {
            $this->documents->archive($me, $document);
        } catch (LogicException $e) {
            return back()->withErrors(['field' => $e->getMessage()]);
        }

        return back()->with('status', __('Документ в архиве'));
    }

    /* -------------------- подписи -------------------- */

    public function requestSignatures(Request $request, Document $document): RedirectResponse
    {
        $me = $request->user()->employee;
        if ($me === null) {
            abort(403, __('Нет карточки сотрудника'));
        }
        TenantGuard::ensureDocument($document, $me);

        $validated = $request->validate([
            'signer_ids' => ['required', 'array', 'min:1'],
            'signer_ids.*' => ['exists:employees,id'],
            'requires_otp' => ['nullable', 'bool'],
        ]);

        // подписанты — только сотрудники своей компании
        foreach (array_map('intval', $validated['signer_ids']) as $signerId) {
            TenantGuard::ensureReference('employees', $signerId, $me, __('Подписант из другой компании'));
        }

        try {
            $this->signatures->requestSignatures($me, $document, array_map('intval', $validated['signer_ids']), (bool) ($validated['requires_otp'] ?? false));
        } catch (LogicException $e) {
            return back()->withErrors(['field' => $e->getMessage()]);
        }

        return back()->with('status', __('Запросы подписи созданы'));
    }

    public function sign(Request $request, Document $document, DocumentSignatureRequest $signatureRequest): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $me = $request->user()->employee;
        if ($me === null) {
            abort(403, __('Нет карточки сотрудника'));
        }
        TenantGuard::ensureDocument($document, $me);
        TenantGuard::ensureSignatureRequest($signatureRequest, $document, $me);

        $otp = $signatureRequest->requires_otp ? $request->input('otp_code') : null;

        try {
            $signature = $this->signatures->sign($me, $signatureRequest, $otp);
        } catch (LogicException|\RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return back()->withErrors(['field' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Документ подписан. Номер подписи: '.$signature->signature_id]);
        }
        return back()->with('status', __('Документ подписан'));
    }

    public function declineSignature(Request $request, Document $document, DocumentSignatureRequest $signatureRequest): RedirectResponse
    {
        $me = $request->user()->employee;
        if ($me === null) {
            abort(403, __('Нет карточки сотрудника'));
        }
        TenantGuard::ensureDocument($document, $me);
        TenantGuard::ensureSignatureRequest($signatureRequest, $document, $me);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->signatures->decline($me, $signatureRequest, $validated['reason']);
        } catch (LogicException $e) {
            return back()->withErrors(['field' => $e->getMessage()]);
        }

        return back()->with('status', __('Подпись отклонена — документ ушёл на доработку'));
    }

    public function verifySignature(DocumentSignature $signature): RedirectResponse
    {
        $me = auth()->user()->employee;
        if ($me === null) {
            abort(403, __('Нет карточки сотрудника'));
        }
        TenantGuard::ensureSignature($signature, $me);

        $verified = $this->signatures->verify($signature);

        return back()->with('status', 'Подпись '.$verified->signature_id.': '.$verified->status->value);
    }

    public function revokeSignature(DocumentSignature $signature): RedirectResponse
    {
        $me = auth()->user()->employee;
        if ($me === null) {
            abort(403, __('Нет карточки сотрудника'));
        }
        TenantGuard::ensureSignature($signature, $me);

        $this->signatures->revoke($me, $signature, __('Отзыв из панели'));

        return back()->with('status', __('Подпись отозвана'));
    }

    /**
     * Scope-ограничение просмотра документа (own / department).
     * company-scope — без дополнительных ограничений (tenant уже проверен).
     */
    protected function ensureVisible(Request $request, Document $document, Employee $me): void
    {
        $scope = $this->permissions->can($request->user(), 'documents.view');
        \assert($scope !== false, __('маршрут уже проверяет право'));

        if ($scope->value === 'own') {
            $visible = (int) $document->author_id === (int) $me->id
                || $document->signatureRequests->firstWhere('signer_id', $me->id) !== null;
            if ($visible === false) {
                abort(403);
            }
        } elseif ($scope->value === 'department') {
            $visible = (int) $document->department_id === (int) $me->department_id
                || (int) $document->author_id === (int) $me->id;
            if ($visible === false) {
                abort(403);
            }
        }
    }

    /**
     * Скачивание файла версии документа с приватного диска (п.51–52 ТЗ:
     * хранение есть — должен быть и контролируемый доступ на скачивание).
     */
    public function downloadVersion(Request $request, Document $document, DocumentVersion $version): StreamedResponse
    {
        $me = $request->user()->employee;
        if ($me === null) {
            abort(403, __('Нет карточки сотрудника'));
        }
        TenantGuard::ensureDocument($document, $me);
        $this->ensureVisible($request, $document, $me);

        abort_unless((int) $version->document_id === (int) $document->id, 404);

        $path = $version->file_path;
        if ($path === null || $path === '') {
            abort(404, __('У этой версии нет файла'));
        }

        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return $disk->download($path, (string) ($version->file_name ?? basename($path)));
    }
}
