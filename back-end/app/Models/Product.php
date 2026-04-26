<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $table = 'products';
    protected $primaryKey = 'product_id';

    protected $fillable = [
        'unit_id',
        'category_id',
        'brand_id',
        'model_id',
        'barcode',
        'product_code',
        'product_name',
        // Product class discriminator
        'product_type',       // 'appliance' | 'consumable'
        // Appliance-specific
        'capacity_rating',    // e.g. '1HP', '1.5HP', '2HP', '2.5HP', '3HP', '5HP'
        'variant',            // e.g. 'Non-Inverter', 'Inverter'
        // Consumable-specific
        'description',        // descriptive attributes for bulk supplies
        'pieces_per_package', // packaging quantity (pieces per box/roll/pack)
        // Shared pricing & operational fields
        'unit_price',
        'cost_price',
        'warranty_period_months',
        'status_id',
        'recommended_stocks',
        'safety_stock',
        'max_stock_level',
        'quantity',
    ];

    public function getRouteKeyName(): string
    {
        return 'product_id';
    }

    protected $casts = [
        'cost_price'             => 'decimal:2',
        'unit_price'             => 'decimal:2',
        'warranty_period_months' => 'integer',
        'recommended_stocks'     => 'integer',
        'safety_stock'           => 'integer',
        'max_stock_level'        => 'integer',
        'quantity'               => 'integer',
        'pieces_per_package'     => 'integer',
    ];

    // -----------------------------------------------------------------------
    // Scopes for product-class filtering
    // -----------------------------------------------------------------------

    /** Scope: only durable appliance records. */
    public function scopeAppliances($query)
    {
        return $query->where('product_type', 'appliance');
    }

    /** Scope: only consumable supply records. */
    public function scopeConsumables($query)
    {
        return $query->where('product_type', 'consumable');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Returns true when this product is a durable appliance. */
    public function isAppliance(): bool
    {
        return $this->product_type === 'appliance';
    }

    /** Returns true when this product is a consumable supply. */
    public function isConsumable(): bool
    {
        return $this->product_type === 'consumable';
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitLookup::class, 'unit_id', 'unit_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CategoryLookup::class, 'category_id', 'category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(BrandLookup::class, 'brand_id', 'brand_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(ModelLookup::class, 'model_id', 'model_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function serials()
    {
        return $this->hasMany(ItemSerial::class, 'product_id', 'product_id');
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class, 'product_id', 'product_id');
    }

    public function suppliers()
    {
        return $this->belongsToMany(
            Supplier::class,
            'supplier_product',
            'product_id',
            'supplier_id',
            'product_id',
            'id'
        )->withPivot(['supplier_prod_id', 'product_price', 'currency', 'status_id'])
         ->withTimestamps();
    }

    public function purchaseOrderDetails()
    {
        return $this->hasMany(PurchaseOrderDetail::class, 'product_id', 'product_id');
    }

    public function saleDetails()
    {
        return $this->hasMany(SaleDetail::class, 'product_id', 'product_id');
    }

    public function adjustmentDetails()
    {
        return $this->hasMany(AdjustmentDetail::class, 'product_id', 'product_id');
    }

    /**
     * Keep products.quantity equal to the sum of quantity_on_hand across all inventory rows.
     */
    public static function syncQuantityFromInventory(int $productId): void
    {
        $sum = (int) Inventory::where('product_id', $productId)->sum('quantity_on_hand');
        static::where('product_id', $productId)->update(['quantity' => $sum]);
    }

    /** @param int[] $productIds */
    public static function syncQuantityFromInventoryMany(array $productIds): void
    {
        $ids = array_values(array_unique(array_filter($productIds)));
        foreach ($ids as $pid) {
            self::syncQuantityFromInventory((int) $pid);
        }
    }
}
