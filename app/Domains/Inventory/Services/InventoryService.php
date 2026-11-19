<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Services;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Shared\Enums\ProductKind;
use App\Shared\Enums\PurchaseOrderStatus;
use App\Shared\Enums\StockMovementType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Складской движок (Этап E1: ERP Core).
 *
 * Правила:
 *   - журнал движений неизменяем, остаток = сумма quantity по складу/товару;
 *   - расход/списание не уходят в минус (проверка достатности + транзакция);
 *   - приём заказа создаёт движения in и переводит статус draft/ordered → received.
 */
class InventoryService
{
    /** Остаток товара на складе (сумма журнала, может быть отрицательным при ручной корректировке). */
    public function stock(int $companyId, int $warehouseId, int $productId): float
    {
        $sum = StockMovement::query()
            ->where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->sum('quantity');

        return (float) $sum;
    }

    /**
     * Остатки всех товаров склада: product_id => количество.
     *
     * @return Collection<int, float>
     */
    public function stockByWarehouse(int $companyId, int $warehouseId): Collection
    {
        return StockMovement::query()
            ->where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id')
            ->map(static fn ($qty): float => (float) $qty);
    }

    /**
     * Совокупный остаток товара по всем складам компании: warehouse_id => количество.
     *
     * @return Collection<int, float>
     */
    public function stockByProduct(int $companyId, int $productId): Collection
    {
        return StockMovement::query()
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->selectRaw('warehouse_id, SUM(quantity) as qty')
            ->groupBy('warehouse_id')
            ->pluck('qty', 'warehouse_id')
            ->map(static fn ($qty): float => (float) $qty);
    }

    /**
     * Запись движения. Для расхода/списания проверяется достатность остатка.
     *
     * @param  array<string, mixed>  $data
     */
    public function move(array $data): StockMovement
    {
        $type = $data['type'];
        $quantity = (float) $data['quantity'];
        $warehouseId = (int) $data['warehouse_id'];
        $productId = (int) $data['product_id'];
        $companyId = (int) $data['company_id'];

        $product = Product::query()->where('company_id', $companyId)->find($productId);

        if ($product === null) {
            throw new LogicException('Товар не найден в компании');
        }

        if ($product->isService()) {
            throw new LogicException('Услуга не хранится на складе — движения недоступны');
        }

        $signed = match ($type) {
            StockMovementType::In => abs($quantity),
            StockMovementType::Out, StockMovementType::WriteOff => -abs($quantity),
            // корректировка: quantity приходит со знаком из формы (приход+ / списание−)
            StockMovementType::Adjustment => $quantity,
        };

        if ($signed === 0.0) {
            throw new LogicException('Количество должно быть больше нуля');
        }

        return DB::transaction(function () use ($data, $type, $signed, $warehouseId, $productId, $companyId): StockMovement {
            // A stable row exists even when the movement ledger is empty.
            // Every writer locks the warehouse before reading its balance.
            $warehouse = Warehouse::query()->where('company_id', $companyId)->whereKey($warehouseId)->lockForUpdate()->first();
            if ($warehouse === null) {
                throw new LogicException('Склад не найден в компании');
            }
            if ($signed < 0) {
                $current = $this->stock($companyId, $warehouseId, $productId);
                if ($current + $signed < 0) {
                    throw new LogicException(sprintf(
                        'Недостаточно остатка: на складе %s, требуется списать %s',
                        rtrim(rtrim(number_format($current, 3, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format(abs($signed), 3, '.', ''), '0'), '.'),
                    ));
                }
            }

            $movement = new StockMovement([
                'company_id' => $companyId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'type' => $type,
                'quantity' => $signed,
                'unit_price' => $data['unit_price'] ?? 0,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'moved_at' => $data['moved_at'] ?? now(),
            ]);
            $movement->save();

            return $movement;
        });
    }

    /**
     * Приём закупочного заказа: движения in по позициям, статус → received.
     */
    public function receive(PurchaseOrder $order, ?int $actorId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $actorId): PurchaseOrder {
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Ordered], true)) {
                throw new LogicException('Приём возможен только для заказа в статусе черновика или размещённого');
            }

            $order->loadMissing('items.product');

            if ($order->items->isEmpty()) {
                throw new LogicException('В заказе нет позиций');
            }

            Warehouse::query()->whereKey($order->warehouse_id)->where('company_id', $order->company_id)->lockForUpdate()->firstOrFail();
            foreach ($order->items as $item) {
                // Услуги входят в заказ, но на склад не оприходуются.
                if ($item->product?->isService()) {
                    continue;
                }

                $this->move([
                    'type' => StockMovementType::In,
                    'company_id' => $order->company_id,
                    'warehouse_id' => $order->warehouse_id,
                    'product_id' => $item->product_id,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'reference' => $order->number,
                    'note' => 'Приём заказа '.$order->number,
                    'created_by' => $actorId,
                    'moved_at' => now(),
                ]);
            }

            $order->status = PurchaseOrderStatus::Received;
            $order->received_at = now()->toDateString();
            $order->save();

            return $order->fresh('items');
        });
    }

    /**
     * Итоговая сумма позиций заказа (записывается в total).
     */
    public function recalcTotal(PurchaseOrder $order): float
    {
        $total = $order->items()->sum(DB::raw('quantity * unit_price'));
        $order->total = $total;
        $order->save();

        return (float) $total;
    }

    /**
     * Добавление позиции в черновик заказа с пересчётом суммы.
     *
     * @param  array<string, mixed>  $data
     */
    public function addItem(PurchaseOrder $order, array $data): PurchaseOrderItem
    {
        return DB::transaction(function () use ($order, $data): PurchaseOrderItem {
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== PurchaseOrderStatus::Draft) {
                throw new LogicException('Позиции можно менять только в черновике');
            }
            if (! Product::query()->where('company_id', $order->company_id)->whereKey($data['product_id'])->exists()) {
                throw new LogicException('Товар не найден в компании');
            }
            $item = $order->items()->create([
                'product_id' => (int) $data['product_id'],
                'quantity' => $data['quantity'],
                'unit_price' => $data['unit_price'] ?? 0,
            ]);

            $this->recalcTotal($order);

            return $item;
        });
    }

    /** Товары компании с текущим остатком по всем складам. */
    public function productsWithStock(int $companyId, bool $onlyGoods = false): Collection
    {
        $totals = StockMovement::query()
            ->where('company_id', $companyId)
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        return Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($onlyGoods, static fn ($query) => $query->where('kind', ProductKind::Goods->value))
            ->orderBy('name')
            ->get()
            ->each(static function (Product $product) use ($totals): void {
                $product->setAttribute('stock_total', (float) ($totals[$product->id] ?? 0));
            });
    }

    /** Склады компании. */
    public function warehouses(int $companyId): Collection
    {
        return Warehouse::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
