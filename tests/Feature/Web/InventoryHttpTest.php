<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Shared\Enums\PurchaseOrderStatus;
use App\Support\Sidebar;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Этап E1 (ERP): гейтинг прав, справочники склада, остатки не уходят в минус,
 * статусная машина закупок, межтенантная изоляция.
 */
class InventoryHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected Warehouse $warehouse;

    protected Product $product;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Inventory Co', 'code' => 'inventoryco']);
        $this->adminUser = User::factory()->create(['email' => 'inv-admin@corpms.demo']);
        $this->adminEmployee = $this->makeEmployee($this->company, 'I-1', 'Админ', 'Админов');
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);
        $this->adminEmployee->update(['user_id' => $this->adminUser->id]);
        $this->adminEmployee->refresh();

        $this->warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'MAIN',
            'name' => 'Основной склад',
            'is_active' => true,
        ]);
        $this->product = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'SKU-1',
            'name' => 'Бумага А4',
            'unit' => 'шт',
            'price' => 100,
            'min_stock' => 10,
            'is_active' => true,
        ]);
        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'name' => 'ООО Поставка',
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser);
    }

    protected function makeEmployee(Company $company, string $no, string $first, string $last, ?string $roleSlug = null): Employee
    {
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'hired_at' => '2025-01-01',
            'status' => 'active',
        ]);

        if ($roleSlug !== null) {
            EmployeeRole::create([
                'employee_id' => $employee->id,
                'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            ]);
        }

        return $employee;
    }

    protected function currentStock(): float
    {
        return (float) StockMovement::query()
            ->where('product_id', $this->product->id)
            ->sum('quantity');
    }

    /** Приход на склад. */
    protected function move(string $type, float $quantity): void
    {
        $this->post('/inventory/movements', [
            'type' => $type,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'unit_price' => 100,
        ])->assertSessionHasNoErrors();
    }

    /** Гейтинг: без inventory.* — 403; super_admin — 200 и пункт в сайдбаре. */
    public function test_permission_gating(): void
    {
        $worker = $this->makeEmployee($this->company, 'W-1', 'Работник', 'Работников', 'employee');
        $workerUser = User::findOrFail($worker->user_id);

        $this->actingAs($workerUser)->get('/inventory')->assertForbidden();
        $this->actingAs($workerUser)->get('/inventory/products')->assertForbidden();
        $this->actingAs($workerUser)->get('/purchases')->assertForbidden();
        $this->actingAs($workerUser)
            ->post('/inventory/movements', [
                'type' => 'in',
                'warehouse_id' => $this->warehouse->id,
                'product_id' => $this->product->id,
                'quantity' => 1,
            ])
            ->assertForbidden();

        $this->actingAs($this->adminUser)->get('/inventory')->assertOk()->assertSee('Остатки');
        $this->actingAs($this->adminUser)->get('/inventory/products')->assertOk()->assertSee('Бумага А4');
        $this->actingAs($this->adminUser)->get('/inventory/warehouses')->assertOk();
        $this->actingAs($this->adminUser)->get('/inventory/suppliers')->assertOk();
        $this->actingAs($this->adminUser)->get('/inventory/movements')->assertOk();
        $this->actingAs($this->adminUser)->get('/purchases')->assertOk();

        $routes = Sidebar::routes($this->adminUser);
        $this->assertContains('inventory.index', $routes);
        $this->assertContains('purchases.index', $routes);

        $workerRoutes = Sidebar::routes($workerUser);
        $this->assertNotContains('inventory.index', $workerRoutes);
    }

    /** Справочники создаются, уникальность кода/артикула/названия защищает компанию. */
    public function test_reference_crud_and_uniqueness(): void
    {
        $this->post('/inventory/products', [
            'sku' => 'SKU-1',
            'name' => 'Дубль',
            'unit' => 'шт',
            'price' => 10,
        ])->assertSessionHasErrors('sku');

        $this->post('/inventory/products', [
            'sku' => 'SKU-2',
            'name' => 'Степлер',
            'unit' => 'шт',
            'price' => 250,
            'min_stock' => 5,
        ])->assertSessionHasNoErrors()->assertRedirect(route('inventory.products'));
        $this->assertDatabaseHas('products', ['sku' => 'SKU-2', 'name' => 'Степлер']);

        $this->post('/inventory/warehouses', ['code' => 'MAIN', 'name' => 'Дубль'])
            ->assertSessionHasErrors('code');
        $this->post('/inventory/warehouses', ['code' => 'SEC', 'name' => 'Второй склад'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('warehouses', 2);

        $this->post('/inventory/suppliers', ['name' => 'ООО Поставка'])
            ->assertSessionHasErrors('name');
        $this->post('/inventory/suppliers', ['name' => 'ООО Логистика', 'inn' => '7701234567'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('suppliers', 2);

        // выключение не удаляет запись (история движений сохраняется)
        $this->post('/inventory/products/'.$this->product->id.'/toggle')->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'is_active' => false]);
        $this->assertDatabaseCount('products', 2);
    }

    /** Приход увеличивает остаток, расход уменьшает; сумма журнала = остаток. */
    public function test_movements_change_stock(): void
    {
        $this->move('in', 100);
        $this->assertSame(100.0, $this->currentStock());

        $this->move('out', 30);
        $this->assertSame(70.0, $this->currentStock());

        $this->move('write_off', 10);
        $this->assertSame(60.0, $this->currentStock());

        $this->assertDatabaseCount('stock_movements', 3);

        $this->get('/inventory')->assertOk()->assertSee('Бумага А4');
        $this->get('/inventory/movements')->assertOk()->assertSee('Приход');
    }

    /** Расход/списание больше остатка — отказ, остаток не уходит в минус. */
    public function test_stock_never_goes_negative(): void
    {
        $this->move('in', 10);

        $this->post('/inventory/movements', [
            'type' => 'out',
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 25,
        ])->assertSessionHasErrors('quantity');

        $this->post('/inventory/movements', [
            'type' => 'write_off',
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 25,
        ])->assertSessionHasErrors('quantity');

        $this->assertSame(10.0, $this->currentStock());
        $this->assertDatabaseCount('stock_movements', 1);

        // ровно остаток — можно
        $this->move('out', 10);
        $this->assertSame(0.0, $this->currentStock());
    }

    /** Закупка: создание черновика → размещение → приём оприходует и не повторяется. */
    public function test_purchase_order_lifecycle(): void
    {
        $this->post('/purchases', [
            'number' => 'PO-1',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 50, 'unit_price' => 90],
                ['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 100],
            ],
        ])->assertSessionHasNoErrors();

        $order = PurchaseOrder::query()->where('number', 'PO-1')->firstOrFail();
        $this->assertSame(PurchaseOrderStatus::Draft, $order->status);
        $this->assertCount(2, $order->items);
        $this->assertSame(5500.0, (float) $order->total);
        $this->assertSame(0.0, $this->currentStock());

        // повторный номер — ошибка
        $this->post('/purchases', [
            'number' => 'PO-1',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1]],
        ])->assertSessionHasErrors('number');

        // размещение
        $this->post('/purchases/'.$order->id.'/order')->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Ordered, $order->fresh()->status);

        // приём: +60 на склад, статус received
        $this->post('/purchases/'.$order->id.'/receive')->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Received, $order->fresh()->status);
        $this->assertSame(60.0, $this->currentStock());
        $this->assertNotNull($order->fresh()->received_at);

        // повторный приём невозможен
        $this->post('/purchases/'.$order->id.'/receive')->assertSessionHasErrors('status');
        $this->assertSame(60.0, $this->currentStock());
        // по одному движению на позицию, повторно не создано
        $this->assertDatabaseCount('stock_movements', 2);
    }

    /** Позиции черновика правятся только до размещения; отмена не оприходует. */
    public function test_purchase_draft_items_and_cancel(): void
    {
        $this->post('/purchases', [
            'number' => 'PO-2',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 100],
                ['product_id' => $this->product->id, 'quantity' => 7, 'unit_price' => 200],
            ],
        ])->assertSessionHasNoErrors();

        $order = PurchaseOrder::query()->where('number', 'PO-2')->firstOrFail();
        $itemId = $order->items()->first()->id;

        $this->post('/purchases/'.$order->id.'/items/'.$itemId.'/remove')->assertSessionHasNoErrors();
        $this->assertSame(1400.0, (float) $order->fresh()->total);

        $this->post('/purchases/'.$order->id.'/cancel')->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(0.0, $this->currentStock());

        // отменённый заказ нельзя принять и нельзя править
        $this->post('/purchases/'.$order->id.'/receive')->assertSessionHasErrors('status');
        $this->post('/purchases/'.$order->id.'/items/'.$itemId.'/remove')->assertSessionHasErrors('status');
    }

    /** Межтенантная изоляция: чужие ID — 403, чужие данные не попадают в списки. */
    public function test_tenant_isolation(): void
    {
        $otherCompany = Company::create(['name' => 'Other Co', 'code' => 'otherco']);
        $otherWarehouse = Warehouse::create(['company_id' => $otherCompany->id, 'code' => 'X', 'name' => 'Чужой', 'is_active' => true]);
        $otherProduct = Product::create(['company_id' => $otherCompany->id, 'sku' => 'X-1', 'name' => 'Чужой товар', 'unit' => 'шт', 'price' => 1, 'is_active' => true]);
        $otherSupplier = Supplier::create(['company_id' => $otherCompany->id, 'name' => 'Чужой поставщик', 'is_active' => true]);

        // формы со ссылкой на чужой склад/товар — 403
        $this->post('/inventory/movements', [
            'type' => 'in',
            'warehouse_id' => $otherWarehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
        ])->assertForbidden();

        $this->post('/inventory/movements', [
            'type' => 'in',
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $otherProduct->id,
            'quantity' => 1,
        ])->assertForbidden();

        $this->post('/purchases', [
            'number' => 'PO-X',
            'supplier_id' => $otherSupplier->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1]],
        ])->assertForbidden();

        // прямой доступ к чужим объектам по ID — 403
        $this->post('/inventory/products/'.$otherProduct->id.'/toggle')->assertForbidden();
        $this->post('/inventory/warehouses/'.$otherWarehouse->id.'/toggle')->assertForbidden();
        $this->post('/inventory/suppliers/'.$otherSupplier->id.'/toggle')->assertForbidden();

        $foreignOrder = PurchaseOrder::create([
            'company_id' => $otherCompany->id,
            'supplier_id' => $otherSupplier->id,
            'warehouse_id' => $otherWarehouse->id,
            'number' => 'FORIGN',
            'status' => 'draft',
            'total' => 0,
        ]);
        $this->get('/purchases/'.$foreignOrder->id)->assertForbidden();
        $this->post('/purchases/'.$foreignOrder->id.'/receive')->assertForbidden();

        // списки отдают только свои записи
        $this->get('/inventory/products')->assertOk()->assertDontSee('Чужой товар');
        $this->get('/inventory/warehouses')->assertOk()->assertDontSee('Чужой');
        $this->get('/inventory/suppliers')->assertOk()->assertDontSee('Чужой поставщик');
        $this->get('/purchases')->assertOk()->assertDontSee('FORIGN');

        $this->assertSame(0.0, $this->currentStock());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    /** Услуга — только номенклатура: в остатках её нет, движение отклоняется. */
    public function test_services_are_catalog_only_and_never_touch_stock(): void
    {
        $this->post('/inventory/products', [
            'sku' => 'SRV-1',
            'name' => 'Консультация',
            'kind' => 'service',
            'unit' => 'шт',
            'price' => 5000,
        ])->assertSessionHasNoErrors();

        $service = Product::query()->where('sku', 'SRV-1')->firstOrFail();
        $this->assertTrue($service->isService());

        $this->post('/inventory/movements', [
            'type' => 'in',
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $service->id,
            'quantity' => 1,
        ])->assertSessionHasErrors('quantity');

        $this->assertDatabaseCount('stock_movements', 0);
        $this->get('/inventory')->assertOk()->assertDontSee('Консультация');
        $this->get('/inventory/products')->assertOk()->assertSee('Консультация')->assertSee('Услуга');

        // услуга в заказе учитывается в сумме, но на склад не оприходуется
        $this->post('/purchases', [
            'number' => 'PO-SRV',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 100],
                ['product_id' => $service->id, 'quantity' => 1, 'unit_price' => 5000],
            ],
        ])->assertSessionHasNoErrors();

        $order = PurchaseOrder::query()->where('number', 'PO-SRV')->firstOrFail();
        $this->assertSame(5200.0, (float) $order->total);

        $this->post('/purchases/'.$order->id.'/receive')->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Received, $order->fresh()->status);
        $this->assertSame(2.0, $this->currentStock());
        $this->assertDatabaseCount('stock_movements', 1);
    }

    /** Карточка товара правится (цена/минимум/название), артикул и вид — нет. */
    public function test_product_card_can_be_updated(): void
    {
        $this->patch('/inventory/products/'.$this->product->id, [
            'name' => 'Бумага А4 Premium',
            'unit' => 'упак',
            'price' => 145.5,
            'min_stock' => 25,
        ])->assertSessionHasNoErrors()->assertRedirect(route('inventory.products'));

        $this->product->refresh();
        $this->assertSame('Бумага А4 Premium', $this->product->name);
        $this->assertSame('упак', $this->product->unit);
        $this->assertSame(145.5, (float) $this->product->price);
        $this->assertSame(25.0, (float) $this->product->min_stock);
        $this->assertSame('SKU-1', $this->product->sku);

        // отрицательная цена отклоняется
        $this->patch('/inventory/products/'.$this->product->id, [
            'name' => 'Бумага',
            'unit' => 'шт',
            'price' => -1,
        ])->assertSessionHasErrors('price');
    }

    /** В черновик добавляются позиции (с пересчётом суммы), после размещения — нет. */
    public function test_draft_order_accepts_new_items_until_placed(): void
    {
        $this->post('/purchases', [
            'number' => 'PO-3',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertSessionHasNoErrors();

        $order = PurchaseOrder::query()->where('number', 'PO-3')->firstOrFail();
        $this->assertSame(100.0, (float) $order->total);

        $this->post('/purchases/'.$order->id.'/items', [
            'product_id' => $this->product->id,
            'quantity' => 3,
            'unit_price' => 50,
        ])->assertSessionHasNoErrors();

        $this->assertSame(250.0, (float) $order->fresh()->total);
        $this->assertCount(2, $order->fresh('items')->items);

        $this->post('/purchases/'.$order->id.'/order')->assertSessionHasNoErrors();

        $this->post('/purchases/'.$order->id.'/items', [
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 1,
        ])->assertSessionHasErrors('status');
        $this->assertSame(250.0, (float) $order->fresh()->total);
    }

    /** Чужой товар нельзя ни изменить, ни добавить в свой заказ. */
    public function test_foreign_product_update_and_purchase_item_blocked(): void
    {
        $otherCompany = Company::create(['name' => 'Third Co', 'code' => 'thirdco']);
        $foreign = Product::create([
            'company_id' => $otherCompany->id,
            'sku' => 'F-1',
            'name' => 'Чужой товар',
            'unit' => 'шт',
            'price' => 1,
            'is_active' => true,
        ]);

        $this->patch('/inventory/products/'.$foreign->id, [
            'name' => 'Взлом',
            'unit' => 'шт',
            'price' => 1,
        ])->assertForbidden();

        $this->post('/purchases', [
            'number' => 'PO-F',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $foreign->id, 'quantity' => 1, 'unit_price' => 1]],
        ])->assertForbidden();
    }
}
