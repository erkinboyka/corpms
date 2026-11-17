<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Inventory\Services\InventoryService;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Shared\Enums\ProductKind;
use App\Shared\Enums\StockMovementType;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use LogicException;

/**
 * Склад (Этап E1: ERP Core): остатки, товары, движения, склады, поставщики.
 *
 * Остаток считается суммой журнала движений (InventoryService); списание
 * не уходит в минус. Изоляция по компаниям: company-скоуп в списках +
 * EnsureTenantIsolation на маршрутах с моделями.
 */
class InventoryController extends Controller
{
    public function __construct(
        protected InventoryService $inventory,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /** Остатки по складу + метрики компании. */
    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;
        $warehouses = $this->inventory->warehouses($companyId);

        $warehouse = $warehouses->first(
            static fn (Warehouse $w): bool => (string) $w->id === (string) $request->query('warehouse', ''),
        ) ?? $warehouses->first();

        $stock = $warehouse === null
            ? collect()
            : $this->inventory->stockByWarehouse($companyId, $warehouse->id);

        $products = $this->inventory->productsWithStock($companyId, onlyGoods: true);

        $lowStock = $products->filter(
            static fn (Product $p): bool => $p->min_stock > 0 && (float) $p->getAttribute('stock_total') < (float) $p->min_stock,
        );

        return view('inventory.index', [
            'warehouses' => $warehouses,
            'warehouse' => $warehouse,
            'products' => $products,
            'stock' => $stock,
            'lowStock' => $lowStock,
            'canMove' => $this->permissions->check($request->user(), 'inventory.move'),
            'canManage' => $this->permissions->check($request->user(), 'inventory.manage'),
            'metrics' => [
                'products' => $products->count(),
                'warehouses' => $warehouses->count(),
                'low' => $lowStock->count(),
                'stockValue' => round($products->sum(static fn (Product $p): float => (float) $p->getAttribute('stock_total') * (float) $p->price), 2),
            ],
        ]);
    }

    /** Справочник товаров компании с совокупным остатком. */
    public function products(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $search = trim((string) $request->query('q'));

        $products = $this->inventory->productsWithStock((int) $me->company_id)
            ->when($search !== '', static function ($collection) use ($search) {
                return $collection->filter(static fn (Product $p): bool => mb_stripos($p->name, $search) !== false
                    || mb_stripos($p->sku, $search) !== false);
            })
            ->sortBy('name')
            ->values();

        return view('inventory.products', [
            'products' => $products,
            'search' => $search,
            'canManage' => $this->permissions->check($request->user(), 'inventory.manage'),
            'units' => ['шт', 'кг', 'л', 'м', 'м2', 'м3', 'упак', 'компл'],
            'kinds' => ProductKind::cases(),
        ]);
    }

    /** Создание товара. */
    public function storeProduct(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['name' => 'У сотрудника не указана компания'])->withInput();
        }

        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['nullable', Rule::enum(ProductKind::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit' => ['required', 'string', 'max:16'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'min_stock' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ], [], ['sku' => 'артикул', 'kind' => 'вид номенклатуры']);

        $exists = Product::query()
            ->where('company_id', $me->company_id)
            ->where('sku', $validated['sku'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['sku' => 'Товар с таким артикулом уже существует'])->withInput();
        }

        $product = Product::query()->create([
            'company_id' => $me->company_id,
            'sku' => $validated['sku'],
            'name' => $validated['name'],
            'kind' => ProductKind::from($validated['kind'] ?? ProductKind::Goods->value),
            'description' => $validated['description'] ?? null,
            'unit' => $validated['unit'],
            'price' => $validated['price'],
            'min_stock' => $validated['min_stock'] ?? 0,
            'is_active' => true,
        ]);

        $this->audit->log($request->user(), 'inventory.product_created', 'product', $product->id, null, [
            'sku' => $product->sku,
            'name' => $product->name,
        ]);

        return redirect()->route('inventory.products')->with('status', 'Товар «'.$product->name.'» создан');
    }

    /** Изменение карточки товара (артикул и вид номенклатуры неизменны). */
    public function updateProduct(Request $request, Product $product): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($product, $me);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit' => ['required', 'string', 'max:16'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'min_stock' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ], [], ['name' => 'название']);

        $before = [
            'name' => $product->name,
            'unit' => $product->unit,
            'price' => (string) $product->price,
            'min_stock' => (string) $product->min_stock,
        ];

        $product->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'unit' => $validated['unit'],
            'price' => $validated['price'],
            'min_stock' => $validated['min_stock'] ?? 0,
        ])->save();

        $this->audit->log($request->user(), 'inventory.product_updated', 'product', $product->id, $before, [
            'name' => $product->name,
            'unit' => $product->unit,
            'price' => (string) $product->price,
            'min_stock' => (string) $product->min_stock,
        ]);

        return redirect()->route('inventory.products')->with('status', 'Товар «'.$product->name.'» обновлён');
    }

    /** Включение/выключение товара (без удаления — история движений сохраняется). */
    public function toggleProduct(Request $request, Product $product): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($product, $me);

        $product->is_active = ! $product->is_active;
        $product->save();

        $this->audit->log($request->user(), 'inventory.product_toggled', 'product', $product->id, null, [
            'is_active' => $product->is_active,
        ]);

        return back()->with('status', 'Товар «'.$product->name.'» '.($product->is_active ? 'включён' : 'выключен'));
    }

    /** Склады компании. */
    public function warehouses(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;
        $warehouses = Warehouse::query()->where('company_id', $companyId)->orderBy('name')->get();

        $stocks = $warehouses->mapWithKeys(function (Warehouse $warehouse) use ($companyId): array {
            $sum = $this->inventory->stockByWarehouse($companyId, $warehouse->id)->sum();

            return [$warehouse->id => $sum];
        });

        return view('inventory.warehouses', [
            'warehouses' => $warehouses,
            'stocks' => $stocks,
            'canManage' => $this->permissions->check($request->user(), 'inventory.manage'),
        ]);
    }

    /** Создание склада. */
    public function storeWarehouse(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['name' => 'У сотрудника не указана компания'])->withInput();
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
        ], [], ['code' => 'код']);

        $exists = Warehouse::query()
            ->where('company_id', $me->company_id)
            ->where('code', $validated['code'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['code' => 'Склад с таким кодом уже существует'])->withInput();
        }

        $warehouse = Warehouse::query()->create([
            'company_id' => $me->company_id,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'address' => $validated['address'] ?? null,
            'is_active' => true,
        ]);

        $this->audit->log($request->user(), 'inventory.warehouse_created', 'warehouse', $warehouse->id, null, [
            'code' => $warehouse->code,
            'name' => $warehouse->name,
        ]);

        return redirect()->route('inventory.warehouses')->with('status', 'Склад «'.$warehouse->name.'» создан');
    }

    /** Включение/выключение склада. */
    public function toggleWarehouse(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($warehouse, $me);

        $warehouse->is_active = ! $warehouse->is_active;
        $warehouse->save();

        $this->audit->log($request->user(), 'inventory.warehouse_toggled', 'warehouse', $warehouse->id, null, [
            'is_active' => $warehouse->is_active,
        ]);

        return back()->with('status', 'Склад «'.$warehouse->name.'» '.($warehouse->is_active ? 'включён' : 'выключен'));
    }

    /** Поставщики компании. */
    public function suppliers(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $query = Supplier::query()->where('company_id', $me->company_id);

        if ($search = trim((string) $request->query('q'))) {
            $query->where('name', 'like', '%'.$search.'%');
        }

        return view('inventory.suppliers', [
            'suppliers' => $query->orderBy('name')->limit(200)->get(),
            'search' => $search,
            'canManage' => $this->permissions->check($request->user(), 'inventory.manage'),
        ]);
    }

    /** Создание поставщика. */
    public function storeSupplier(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['name' => 'У сотрудника не указана компания'])->withInput();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'inn' => ['nullable', 'string', 'max:32'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => 'название']);

        $exists = Supplier::query()
            ->where('company_id', $me->company_id)
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['name' => 'Поставщик с таким названием уже существует'])->withInput();
        }

        $supplier = Supplier::query()->create([
            'company_id' => $me->company_id,
            ...$validated,
            'is_active' => true,
        ]);

        $this->audit->log($request->user(), 'inventory.supplier_created', 'supplier', $supplier->id, null, [
            'name' => $supplier->name,
        ]);

        return redirect()->route('inventory.suppliers')->with('status', 'Поставщик «'.$supplier->name.'» создан');
    }

    /** Включение/выключение поставщика. */
    public function toggleSupplier(Request $request, Supplier $supplier): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);
        TenantGuard::ensure($supplier, $me);

        $supplier->is_active = ! $supplier->is_active;
        $supplier->save();

        $this->audit->log($request->user(), 'inventory.supplier_toggled', 'supplier', $supplier->id, null, [
            'is_active' => $supplier->is_active,
        ]);

        return back()->with('status', 'Поставщик «'.$supplier->name.'» '.($supplier->is_active ? 'включён' : 'выключен'));
    }

    /** Журнал движений склада. */
    public function movements(Request $request): View
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        $companyId = (int) $me->company_id;

        $movements = StockMovement::query()
            ->where('company_id', $companyId)
            ->with(['product', 'warehouse', 'creator'])
            ->orderByDesc('moved_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('inventory.movements', [
            'movements' => $movements,
            'warehouses' => $this->inventory->warehouses($companyId),
            'products' => $this->inventory->productsWithStock($companyId, onlyGoods: true),
            'canMove' => $this->permissions->check($request->user(), 'inventory.move'),
            'types' => StockMovementType::cases(),
        ]);
    }

    /** Запись движения (приход/расход/корректировка/списание). */
    public function storeMovement(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;
        \assert($me !== null);

        if ($me->company_id === null) {
            return back()->withErrors(['quantity' => 'У сотрудника не указана компания'])->withInput();
        }

        $validated = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_column(StockMovementType::cases(), 'value'))],
            'warehouse_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:-999999999', 'max:999999999', 'not_in:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // Справочники обязаны принадлежать компании текущего пользователя.
        TenantGuard::ensureReference('warehouses', (int) $validated['warehouse_id'], $me);
        TenantGuard::ensureReference('products', (int) $validated['product_id'], $me);

        $type = StockMovementType::from($validated['type']);

        // Корректировка — со знаком; приход всегда положительный, расход/списание — отрицательный.
        $quantity = (float) $validated['quantity'];
        if ($type === StockMovementType::In) {
            $quantity = abs($quantity);
        } elseif ($type === StockMovementType::Out || $type === StockMovementType::WriteOff) {
            $quantity = -abs($quantity);
        } elseif ($quantity < 0) {
            return back()->withErrors(['quantity' => 'Корректировка списания должна быть отрицательной, прихода — положительной'])->withInput();
        }

        try {
            $movement = $this->inventory->move([
                'type' => $type,
                'company_id' => (int) $me->company_id,
                'warehouse_id' => (int) $validated['warehouse_id'],
                'product_id' => (int) $validated['product_id'],
                'quantity' => $quantity,
                'unit_price' => $validated['unit_price'] ?? 0,
                'note' => $validated['note'] ?? null,
                'created_by' => $me->user_id,
                'moved_at' => now(),
            ]);
        } catch (LogicException $e) {
            return back()->withErrors(['quantity' => $e->getMessage()])->withInput();
        }

        $this->audit->log($request->user(), 'inventory.movement_created', 'stock_movement', $movement->id, null, [
            'type' => $type->value,
            'quantity' => (string) $movement->quantity,
        ]);

        return redirect()->route('inventory.movements')->with('status', 'Движение записано');
    }
}
