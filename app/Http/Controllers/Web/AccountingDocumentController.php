<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\AccDocument;
use App\Models\AccEntry;
use App\Shared\Enums\AccDocumentKind;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Первичные документы (Этап E4): счета, акты, платёжные поручения.
 *
 * Документ может быть связан с проводкой (entry_id) — тогда он виден
 * в карточке проводки как основание.
 */
class AccountingDocumentController extends Controller
{
    public function __construct(
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /** Реестр первичных документов. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;
        $search = trim((string) $request->query('q'));
        $kind = (string) $request->query('kind');

        $documents = AccDocument::query()
            ->where('company_id', $companyId)
            ->with(['entry'])
            ->when(in_array($kind, array_column(AccDocumentKind::cases(), 'value'), true), static fn ($query) => $query->where('kind', $kind))
            ->when($search !== '', static function ($query) use ($search): void {
                $query->where(static function ($sub) use ($search): void {
                    $sub->where('number', 'like', '%'.$search.'%')
                        ->orWhere('counterparty', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('document_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('accounting.documents.index', [
            'documents' => $documents,
            'search' => $search,
            'kind' => $kind,
            'kinds' => AccDocumentKind::cases(),
            'entries' => $this->entries($companyId),
            'canManage' => $this->permissions->check($request->user(), 'accounting.manage'),
        ]);
    }

    /** Создание первичного документа. */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['number' => __('У сотрудника не указана компания')])->withInput();
        }

        $validated = $request->validate([
            'number' => [
                'required', 'string', 'max:32',
                Rule::unique('acc_documents', 'number')->where('company_id', $me->company_id),
            ],
            'kind' => ['required', Rule::enum(AccDocumentKind::class)],
            'document_date' => ['required', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'counterparty' => ['nullable', 'string', 'max:255'],
            'entry_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['number' => __('номер'), 'kind' => __('вид'), 'document_date' => __('дата')]);

        $entryId = $validated['entry_id'] ?? null;

        if ($entryId !== null && $entryId !== '') {
            TenantGuard::ensureReference('acc_entries', (int) $entryId, $me);
        }

        $document = AccDocument::query()->create([
            'company_id' => $me->company_id,
            'number' => $validated['number'],
            'kind' => AccDocumentKind::from($validated['kind']),
            'document_date' => $validated['document_date'],
            'amount' => $validated['amount'] ?? 0,
            'counterparty' => $validated['counterparty'] ?? null,
            'entry_id' => $entryId ?: null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $me->user_id,
        ]);

        $this->audit->log($request->user(), 'accounting.document_created', 'acc_document', $document->id, null, [
            'number' => $document->number,
            'kind' => $document->kind->value,
        ]);

        return back()->with('status', 'Документ '.$document->number.' добавлен');
    }

    /** Удаление первичного документа (проводка остаётся). */
    public function destroy(Request $request, AccDocument $document): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($document, $me);

        $document->delete();

        $this->audit->log($request->user(), 'accounting.document_deleted', 'acc_document', $document->id, null, [
            'number' => $document->number,
        ]);

        return back()->with('status', 'Документ '.$document->number.' удалён');
    }

    /** @return Collection<int, AccEntry> */
    private function entries(int $companyId): Collection
    {
        return AccEntry::query()
            ->where('company_id', $companyId)
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }
}
