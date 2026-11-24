<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Inventory\Services\InventoryService;
use App\Domains\Purchasing\Services\PurchaseObligationService;
use App\Http\Controllers\Controller;
use App\Models\FinAccount;
use App\Models\FinCategory;
use App\Models\PurchaseObligationPayment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Shared\Enums\PurchaseObligationStatus;
use App\Shared\Enums\PurchaseOrderStatus;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;

/**
 * Закупки (Этап E1: ERP Core): заказы поставщику, позиции, приём на склад.
 *
 * Приём заказа создаёт движения in и не даёт принять заказ дважды;
 * отмена доступна только для черновика/размещённого заказа.
 */
class PurchaseController extends Controller
{
    public function __construct(
        protected InventoryService $inventory,
        protected PermissionService $permissions,
        protected AuditService $audit,
        protected PurchaseObligationService $obligations,
    ) {}

    /** Список закупочных заказов компании. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;

        $query = PurchaseOrder::query()
            ->where('company_id', $companyId)
            ->with(['supplier', 'warehouse']);

        if ($status = (string) $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('number', 'like', '%'.$search.'%');
            });
        }

        $orders = $query->orderByDesc('id')->paginate(30)->withQueryString();

        return view('purchases.index', [
            'orders' => $orders,
            'suppliers' => Supplier::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(),
            'warehouses' => $this->inventory->warehouses($companyId),
            'products' => $this->inventory->productsWithStock($companyId),
            'statusFilter' => $status,
            'search' => $search,
            'canManage' => $this->permissions->check($request->user(), 'purchases.manage'),
            'statuses' => PurchaseOrderStatus::cases(),
        ]);
    }

    /** Детали заказа: позиции и статус. */
    public function show(Request $request, PurchaseOrder $order): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($order, $me);

        $order->load(['supplier', 'warehouse', 'items.product', 'obligation.payments']);

        return view('purchases.show', [
            'order' => $order,
            'obligation' => $order->obligation,
            'accounts' => FinAccount::query()->where('company_id', $me->company_id)->where('is_active', true)->orderBy('name')->get(),
            'categories' => FinCategory::query()->where('company_id', $me->company_id)->where('is_active', true)->where('direction', 'expense')->orderBy('name')->get(),
            'canPay' => $this->permissions->check($request->user(), 'finance.manage'),
            'canManage' => $this->permissions->check($request->user(), 'purchases.manage'),
            'products' => $this->inventory->productsWithStock((int) $me->company_id),
        ]);
    }

    /** Создание заказа с позициями (массив items). */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['number' => __('У сотрудника не указана компания')])->withInput();
        }

        $validated = $request->validate([
            'number' => ['required', 'string', 'max:32'],
            'supplier_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'order_date' => ['nullable', 'date'],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ], [], ['number' => __('номер'), 'items' => __('позиции')]);

        TenantGuard::ensureReference('suppliers', (int) $validated['supplier_id'], $me);
        TenantGuard::ensureReference('warehouses', (int) $validated['warehouse_id'], $me);

        foreach ($validated['items'] as $item) {
            TenantGuard::ensureReference('products', (int) $item['product_id'], $me);
        }

        $exists = PurchaseOrder::query()
            ->where('company_id', $me->company_id)
            ->where('number', $validated['number'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['number' => __('Заказ с таким номером уже существует')])->withInput();
        }

        $order = DB::transaction(function () use ($validated, $me): PurchaseOrder {
            $order = PurchaseOrder::query()->create([
                'company_id' => $me->company_id,
                'supplier_id' => (int) $validated['supplier_id'],
                'warehouse_id' => (int) $validated['warehouse_id'],
                'number' => $validated['number'],
                'status' => PurchaseOrderStatus::Draft,
                'total' => 0,
                'order_date' => $validated['order_date'] ?? now()->toDateString(),
                'expected_at' => $validated['expected_at'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $me->user_id,
            ]);

            foreach ($validated['items'] as $item) {
                $order->items()->create([
                    'product_id' => (int) $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'] ?? 0,
                ]);
            }

            $this->inventory->recalcTotal($order);

            return $order;
        });

        $this->audit->log($request->user(), 'purchase.created', 'purchase_order', $order->id, null, [
            'number' => $order->number,
            'total' => (string) $order->total,
        ]);

        return redirect()->route('purchases.show', $order)->with('status', 'Закупочный заказ '.$order->number.' создан');
    }

    /** Размещение заказа (draft → ordered). */
    public function order(Request $request, PurchaseOrder $order): RedirectResponse
    {
        return DB::transaction(function () use ($request, $order): RedirectResponse {
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $me = $request->user()->employee;
            \assert($me !== null);
            TenantGuard::ensure($order, $me);

            if ($order->status !== PurchaseOrderStatus::Draft) {
                return back()->withErrors(['status' => __('Разместить можно только черновик')]);
            }

            $order->status = PurchaseOrderStatus::Ordered;
            $order->save();

            // PROC-01: обязательство появляется в той же транзакции, что и статус.
            $this->obligations->ensureForOrder($order, $request->user());

            $this->audit->log($request->user(), 'purchase.ordered', 'purchase_order', $order->id, null, [
                'number' => $order->number,
            ]);

            return back()->with('status', 'Заказ '.$order->number.' размещён');
        });
    }

    /**
     * PROC-01: оплата обязательства расходом из журнала финансов.
     * Повтор того же reference не создаёт вторую операцию (идемпотентность).
     */
    public function payObligation(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($order, $me);

        $obligation = $order->obligation()->first();

        if ($obligation === null) {
            return back()->withErrors(['status' => __('У заказа нет финансового обязательства')]);
        }

        TenantGuard::ensure($obligation, $me);

        $validated = $request->validate([
            'account_id' => ['required', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'paid_at' => ['required', 'date'],
            'method' => ['required', 'in:cash,bank_transfer,card,other'],
            'reference' => ['required', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'account_id' => __('счёт'), 'amount' => __('сумма'), 'reference' => __('номер платёжки'),
        ]);

        TenantGuard::ensureReference('fin_accounts', (int) $validated['account_id'], $me);

        $categoryId = $validated['category_id'] ?? null;

        if ($categoryId !== null && $categoryId !== '') {
            TenantGuard::ensureReference('fin_categories', (int) $categoryId, $me);

            $category = FinCategory::query()->find((int) $categoryId);

            if ($category === null || $category->direction->value !== 'expense') {
                return back()->withErrors(['category_id' => __('Статья должна быть расходной')])->withInput();
            }
        }

        $account = FinAccount::query()->findOrFail((int) $validated['account_id']);

        if ($account->currency !== $obligation->currency) {
            return back()->withErrors(['account_id' => __('Валюта счёта не совпадает с валютой обязательства')])->withInput();
        }

        $reference = trim((string) $validated['reference']);
        $duplicate = PurchaseObligationPayment::query()
            ->where('obligation_id', $obligation->id)
            ->where('reference', $reference)
            ->exists();

        if (! $duplicate && round((float) $validated['amount'], 2) > $obligation->remainingAmount()) {
            return back()->withErrors(['amount' => __('Сумма превышает задолженность по обязательству')])->withInput();
        }

        try {
            $payment = $this->obligations->pay($obligation, [
                ...$validated,
                'category_id' => $categoryId,
                'reference' => $reference,
            ], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Оплата '.$payment->reference.' проведена');
    }

    /** Приём заказа на склад: движения in, статус → received. */
    public function receive(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($order, $me);

        try {
            $this->inventory->receive($order, $me->user_id);
        } catch (LogicException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        $this->audit->log($request->user(), 'purchase.received', 'purchase_order', $order->id, null, [
            'number' => $order->number,
            'total' => (string) $order->total,
        ]);

        return back()->with('status', 'Заказ '.$order->number.' принят на склад');
    }

    /** Отмена заказа (draft/ordered → cancelled), позиции не оприходуются. */
    public function cancel(Request $request, PurchaseOrder $order): RedirectResponse
    {
        return DB::transaction(function () use ($request, $order): RedirectResponse {
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $me = $request->user()->employee;
            \assert($me !== null);
            TenantGuard::ensure($order, $me);

            if (! in_array($order->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Ordered], true)) {
                return back()->withErrors(['status' => __('Отменить можно только черновик или размещённый заказ')]);
            }

            $obligation = $order->obligation()->first();

            // PROC-01: оплаченное обязательство нельзя тихо «забыть» при отмене заказа.
            if ($obligation !== null && ($obligation->payments()->exists() || $obligation->status === PurchaseObligationStatus::PartiallyPaid)) {
                return back()->withErrors(['status' => __('Нельзя отменить заказ с оплаченным обязательством')]);
            }

            if ($obligation !== null && $obligation->status->isPayable()) {
                $this->obligations->cancel($obligation, $request->user());
            }

            $order->status = PurchaseOrderStatus::Cancelled;
            $order->save();

            $this->audit->log($request->user(), 'purchase.cancelled', 'purchase_order', $order->id, null, [
                'number' => $order->number,
            ]);

            return back()->with('status', 'Заказ '.$order->number.' отменён');
        });
    }

    /** Добавление позиции в черновик заказа. */
    public function addItem(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($order, $me);

        if ($order->status !== PurchaseOrderStatus::Draft) {
            return back()->withErrors(['status' => __('Позиции можно менять только в черновике')]);
        }

        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ], [], ['product_id' => __('товар'), 'quantity' => __('количество')]);

        TenantGuard::ensureReference('products', (int) $validated['product_id'], $me);

        try {
            $this->inventory->addItem($order, $validated);
        } catch (LogicException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

        $this->audit->log($request->user(), 'purchase.item_added', 'purchase_order', $order->id, null, [
            'number' => $order->number,
            'total' => (string) $order->fresh()->total,
        ]);

        return back()->with('status', 'Позиция добавлена в заказ '.$order->number);
    }

    /** Удаление позиции из черновика (только draft). */
    public function removeItem(Request $request, PurchaseOrder $order, int $item): RedirectResponse
    {
        return DB::transaction(function () use ($request, $order, $item): RedirectResponse {
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $me = $request->user()->employee;
            \assert($me !== null);
            TenantGuard::ensure($order, $me);

            if ($order->status !== PurchaseOrderStatus::Draft) {
                return back()->withErrors(['status' => __('Позиции можно менять только в черновике')]);
            }

            $line = $order->items()->whereKey($item)->first();

            if ($line === null) {
                return back()->withErrors(['status' => __('Позиция не найдена')]);
            }

            $line->delete();
            $this->inventory->recalcTotal($order);

            return back()->with('status', __('Позиция удалена'));
        });
    }
}
