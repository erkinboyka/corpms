<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Enums\ProductKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Товар/услуга — номенклатура компании.
 */
class Product extends Model
{
    protected $table = 'products';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => ProductKind::class,
            'price' => 'decimal:2',
            'min_stock' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    /** Услуга не хранится на складе: движения и остатки к ней неприменимы. */
    public function isService(): bool
    {
        return $this->kind === ProductKind::Service;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'product_id');
    }

    /** @return HasMany<PurchaseOrderItem, $this> */
    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'product_id');
    }
}
