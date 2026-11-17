<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Inventory\Services\InventoryService;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Shared\Enums\PurchaseOrderStatus;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
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

        $order->load(['supplier', 'warehouse', 'items.product']);

        return view('purchases.show', [
            'order' => $order,
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
            return back()->withErrors(['number' => 'У сотрудника не указана компания'])->withInput();
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
        ], [], ['number' => 'номер', 'items' => 'позиции']);

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
            return back()->withErrors(['number' => 'Заказ с таким номером уже существует'])->withInput();
        }

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

        $this->audit->log($request->user(), 'purchase.created', 'purchase_order', $order->id, null, [
            'number' => $order->number,
            'total' => (string) $order->total,
        ]);

        return redirect()->route('purchases.show', $order)->with('status', 'Закупочный заказ '.$order->number.' создан');
    }

    /** Размещение заказа (draft → ordered). */
    public function order(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($order, $me);

        if ($order->status !== PurchaseOrderStatus::Draft) {
            return back()->withErrors(['status' => 'Разместить можно только черновик']);
        }

        $order->status = PurchaseOrderStatus::Ordered;
        $order->save();

        $this->audit->log($request->user(), 'purchase.ordered', 'purchase_order', $order->id, null, [
            'number' => $order->number,
        ]);

        return back()->with('status', 'Заказ '.$order->number.' размещён');
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
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($order, $me);

        if (! in_array($order->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Ordered], true)) {
            return back()->withErrors(['status' => 'Отменить можно только черновик или размещённый заказ']);
        }

        $order->status = PurchaseOrderStatus::Cancelled;
        $order->save();

        $this->audit->log($request->user(), 'purchase.cancelled', 'purchase_order', $order->id, null, [
            'number' => $order->number,
        ]);

        return back()->with('status', 'Заказ '.$order->number.' отменён');
    }

    /** Добавление позиции в черновик заказа. */
    public function addItem(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($order, $me);

        if ($order->status !== PurchaseOrderStatus::Draft) {
            return back()->withErrors(['status' => 'Позиции можно менять только в черновике']);
        }

        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ], [], ['product_id' => 'товар', 'quantity' => 'количество']);

        TenantGuard::ensureReference('products', (int) $validated['product_id'], $me);

        $this->inventory->addItem($order, $validated);

        $this->audit->log($request->user(), 'purchase.item_added', 'purchase_order', $order->id, null, [
            'number' => $order->number,
            'total' => (string) $order->fresh()->total,
        ]);

        return back()->with('status', 'Позиция добавлена в заказ '.$order->number);
    }

    /** Удаление позиции из черновика (только draft). */
    public function removeItem(Request $request, PurchaseOrder $order, int $item): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($order, $me);

        if ($order->status !== PurchaseOrderStatus::Draft) {
            return back()->withErrors(['status' => 'Позиции можно менять только в черновике']);
        }

        $line = $order->items()->whereKey($item)->first();

        if ($line === null) {
            return back()->withErrors(['status' => 'Позиция не найдена']);
        }

        $line->delete();
        $this->inventory->recalcTotal($order);

        return back()->with('status', 'Позиция удалена');
    }
}
