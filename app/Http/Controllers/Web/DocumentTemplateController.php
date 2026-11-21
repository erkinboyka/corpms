<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Documents\Services\DocumentService;
use App\Http\Controllers\Controller;
use App\Models\DocumentCategory;
use App\Models\DocumentTemplate;
use App\Models\DocumentWorkflow;
use App\Support\PermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use LogicException;

/**
 * Шаблоны и категории документов (documents.templates.*, documents.categories.manage):
 * просмотр, создание, генерация документа из шаблона (п.34 ТЗ).
 */
class DocumentTemplateController extends Controller
{
    public function __construct(
        protected DocumentService $documents,
        protected AuditService $audit,
        protected PermissionService $permissions,
    ) {}

    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $categories = DocumentCategory::query()->withCount('documents')->orderBy('name')->get();
        // межтенантная изоляция (S2): шаблоны и маршруты своей компании
        $templates = DocumentTemplate::query()
            ->with(['category', 'workflow'])
            ->where('company_id', $me->company_id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('documents.templates', [
            'categories' => $categories,
            'templates' => $templates,
            'workflows' => DocumentWorkflow::query()
                ->where('company_id', $me->company_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'canManageTemplates' => $this->permissions->check($request->user(), 'documents.templates.manage'),
            'canManageCategories' => $this->permissions->check($request->user(), 'documents.categories.manage'),
            'canCreate' => $this->permissions->check($request->user(), 'documents.create'),
        ]);
    }

    /** Создание категории (documents.categories.manage делает и create, и список). */
    public function storeCategory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', 'alpha_dash', 'unique:document_categories,code'],
            'name' => ['required', 'string', 'max:150'],
        ]);

        $category = DocumentCategory::create($validated + ['is_active' => true]);

        $this->audit->log($request->user(), 'document_category.created', 'document_category', $category->id, null, [
            'code' => $validated['code'],
        ]);

        return redirect()->route('documents.templates')->with('status', __('Категория «').$category->name.__('» создана'));
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['required', 'exists:document_categories,id'],
            'kind' => ['required', 'in:blade,docx'],
            'body' => ['required', 'string', 'max:20000'],
            // список переменных: либо строка через запятую, либо массив
            'variables' => ['nullable'],
            // маршрут согласования — только своей компании (анти-кросс-тенантная ссылка)
            'workflow_id' => ['nullable', Rule::exists('document_workflows', 'id')->where('company_id', $me->company_id)],
        ]);

        $variables = $validated['variables'];
        if (is_string($variables)) {
            $variables = array_values(array_filter(array_map('trim', explode(',', $variables))));
        } else {
            $variables = array_values(array_filter(array_map('trim', (array) $variables)));
        }

        $template = DocumentTemplate::create([
            'company_id' => $me->company_id,
            'category_id' => (int) $validated['category_id'],
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'body' => $validated['body'],
            'variables' => $variables === [] ? null : $variables,
            'is_active' => true,
            'workflow_id' => $validated['workflow_id'] ?? null,
        ]);

        $this->audit->log($request->user(), 'document_template.created', 'document_template', $template->id, null, [
            'name' => $template->name,
            'variables' => $variables,
        ]);

        return redirect()->route('documents.templates')->with('status', __('Шаблон «').$template->name.__('» создан'));
    }

    /** Генерация документа из шаблона с подстановкой переменных. */
    public function createFromTemplate(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id !== null && (int) $template->company_id !== (int) $me->company_id) {
            abort(403, __('Шаблон другой компании'));
        }

        $validated = $request->validate([
            'variables' => ['nullable', 'array'],
            'variables.*' => ['nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:200'],
            'priority' => ['nullable', 'in:low,medium,high,critical'],
            'due_at' => ['nullable', 'date'],
        ]);

        $variables = $validated['variables'] ?? [];
        $overrides = array_filter([
            'title' => $validated['title'] ?? null,
            'priority' => $validated['priority'] ?? null,
            'due_at' => $validated['due_at'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        try {
            $document = $this->documents->createFromTemplate($me, $template, $variables, $overrides);
        } catch (LogicException $e) {
            return back()->withErrors(['variables' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('documents.show', $document)
            ->with('status', 'Документ '.$document->document_number.' создан из шаблона');
    }
}
