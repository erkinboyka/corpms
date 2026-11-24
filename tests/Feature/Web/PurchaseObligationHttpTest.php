<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Finance\Services\FinanceService;
use App\Domains\Inventory\Services\InventoryService;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\FinAccount;
use App\Models\FinCategory;
use App\Models\FinTransaction;
use App\Models\Product;
use App\Models\PurchaseObligation;
use App\Models\PurchaseObligationPayment;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Shared\Enums\FinTransactionSource;
use App\Shared\Enums\PurchaseObligationStatus;
use App\Shared\Enums\PurchaseOrderStatus;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROC-01: закупка порождает финансовое обязательство, оплата связана с заказом
 * и не дублируется, приход остаётся отдельным шагом той же цепочки.
 */
class PurchaseObligationHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Warehouse $warehouse;

    protected Product $product;

    protected Supplier $supplier;

    protected FinAccount $account;

    protected FinCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'Obligation Co', 'code' => 'obligationco']);
        $this->adminUser = User::factory()->create(['email' => 'obl-admin@corpms.demo']);
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $this->adminUser->id,
            'employee_no' => 'O-1',
            'first_name' => 'Админ',
            'last_name' => 'Облигаций',
        ]);
        EmployeeRole::create([
            'employee_id' => $employee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'MAIN',
            'name' => 'Основной склад',
            'is_active' => true,
        ]);
        $this->product = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'SKU-OBL',
            'name' => 'Бумага А4',
            'unit' => 'шт',
            'price' => 100,
            'is_active' => true,
        ]);
        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'name' => 'ООО Поставка',
            'is_active' => true,
        ]);
        $this->account = FinAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Счёт TJS',
            'kind' => 'bank',
            'currency' => 'TJS',
            'opening_balance' => 10000,
            'is_active' => true,
        ]);
        $this->category = FinCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Закупки',
            'direction' => 'expense',
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser);
    }

    /** Заказ → обязательство → частичная/полная оплата → приход; повторы не дублируют деньги. */
    public function test_full_chain_is_linked_and_idempotent(): void
    {
        $this->post('/purchases', [
            'number' => 'PO-OBL-1',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 50, 'unit_price' => 90]],
        ])->assertSessionHasNoErrors();

        $order = PurchaseOrder::query()->where('number', 'PO-OBL-1')->firstOrFail();
        $this->assertNull($order->obligation, 'черновик не должен создавать обязательство');

        $this->post('/purchases/'.$order->id.'/order')->assertSessionHasNoErrors();
        $obligation = $order->obligation()->firstOrFail();
        $this->assertSame(4500.0, (float) $obligation->amount);
        $this->assertSame('TJS', $obligation->currency);
        $this->assertSame(PurchaseObligationStatus::Open, $obligation->status);

        $this->post('/purchases/'.$order->id.'/order')->assertSessionHasErrors('status');
        $this->assertSame(1, PurchaseObligation::query()->where('purchase_order_id', $order->id)->count());

        $payment = [
            'account_id' => $this->account->id,
            'category_id' => $this->category->id,
            'amount' => 1500,
            'paid_at' => now()->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'PAY-1',
        ];
        $this->post('/purchases/'.$order->id.'/obligation/payments', $payment)->assertSessionHasNoErrors();
        $this->assertSame(PurchaseObligationStatus::PartiallyPaid, $obligation->fresh()->status);
        $this->assertSame(3000.0, $obligation->fresh()->remainingAmount());
        $this->assertSame(1, FinTransaction::query()->where('source', FinTransactionSource::PurchaseObligationPayment->value)->count());
        $this->assertSame(8500.0, app(FinanceService::class)->balance($this->account->fresh()));

        // повтор той же платёжки не создаёт вторую операцию
        $this->post('/purchases/'.$order->id.'/obligation/payments', $payment)->assertSessionHasNoErrors();
        $this->assertSame(1, PurchaseObligationPayment::query()->where('obligation_id', $obligation->id)->count());
        $this->assertSame(1, FinTransaction::query()->where('source', FinTransactionSource::PurchaseObligationPayment->value)->count());

        $this->post('/purchases/'.$order->id.'/obligation/payments', [
            ...$payment,
            'amount' => 9000,
            'reference' => 'PAY-2',
        ])->assertSessionHasErrors('amount');

        $this->post('/purchases/'.$order->id.'/obligation/payments', [
            ...$payment,
            'amount' => 3000,
            'reference' => 'PAY-3',
        ])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseObligationStatus::Paid, $obligation->fresh()->status);
        $this->assertSame(0.0, $obligation->fresh()->remainingAmount());

        $this->post('/purchases/'.$order->id.'/receive')->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Received, $order->fresh()->status);
        $stock = app(InventoryService::class)->stock($this->company->id, $this->warehouse->id, $this->product->id);
        $this->assertSame(50.0, $stock);
        $this->assertSame(2, PurchaseObligationPayment::query()->where('obligation_id', $obligation->id)->count());

        // повторный приём не меняет остаток и не создаёт новое движение
        $this->post('/purchases/'.$order->id.'/receive')->assertSessionHasErrors('status');
        $this->assertSame($stock, app(InventoryService::class)->stock($this->company->id, $this->warehouse->id, $this->product->id));
    }

    /** Неверная валюта, чужая компания и отмена с оплатой не портят обязательство. */
    public function test_validation_and_tenant_isolation(): void
    {
        $this->post('/purchases', [
            'number' => 'PO-OBL-2',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 100]],
        ])->assertSessionHasNoErrors();
        $order = PurchaseOrder::query()->where('number', 'PO-OBL-2')->firstOrFail();
        $this->post('/purchases/'.$order->id.'/order')->assertSessionHasNoErrors();
        $obligation = $order->obligation()->firstOrFail();

        $rub = FinAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Рублёвый счёт',
            'kind' => 'bank',
            'currency' => 'RUB',
            'opening_balance' => 1000,
            'is_active' => true,
        ]);
        $rubPayment = [
            'account_id' => $rub->id,
            'amount' => 100,
            'paid_at' => now()->toDateString(),
            'method' => 'cash',
            'reference' => 'PAY-RUB',
        ];
        $this->post('/purchases/'.$order->id.'/obligation/payments', $rubPayment)->assertSessionHasErrors('account_id');

        $otherCompany = Company::create(['name' => 'Other', 'code' => 'otherobl']);
        $foreignAccount = FinAccount::create([
            'company_id' => $otherCompany->id,
            'name' => 'Чужой счёт',
            'kind' => 'bank',
            'currency' => 'TJS',
            'opening_balance' => 1000,
            'is_active' => true,
        ]);
        $this->post('/purchases/'.$order->id.'/obligation/payments', [
            ...$rubPayment,
            'account_id' => $foreignAccount->id,
            'reference' => 'PAY-FOREIGN',
        ])->assertForbidden();

        $this->post('/purchases/'.$order->id.'/obligation/payments', [
            'account_id' => $this->account->id,
            'category_id' => $this->category->id,
            'amount' => 1000,
            'paid_at' => now()->toDateString(),
            'method' => 'cash',
            'reference' => 'PAY-OK',
        ])->assertSessionHasNoErrors();
        $this->post('/purchases/'.$order->id.'/cancel')->assertSessionHasErrors('status');
        $this->assertSame(PurchaseObligationStatus::Paid, $obligation->fresh()->status);

        $foreignOrder = PurchaseOrder::query()->create([
            'company_id' => $otherCompany->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'number' => 'PO-OTHER',
            'status' => PurchaseOrderStatus::Ordered,
            'total' => 100,
        ]);
        $this->post('/purchases/'.$foreignOrder->id.'/obligation/payments', [
            ...$rubPayment,
            'account_id' => $this->account->id,
        ])->assertForbidden();
    }
}
