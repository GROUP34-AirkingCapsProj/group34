<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;

class Product extends Model
{
    use SoftDeletes, Auditable;
    protected $table = 'products';
    protected $primaryKey = 'product_id';

    protected $fillable = [
        'category_id',
        'barcode',
        'product_code',
        'product_name',
        'product_type',
        'description',
        'unit_price',
        'cost_price',
        'status_id',
        'recommended_stocks',
        'safety_stock',
        'max_stock_level',
    ];

    public function getRouteKeyName(): string
    {
        return 'product_id';
    }

    // Serialize the pieces_per_package accessor so API responses always include it.
    // Reads from consumable_details; returns 1 for appliances.
    protected $appends = ['pieces_per_package'];

    protected $casts = [
        'cost_price'         => 'decimal:2',
        'unit_price'         => 'decimal:2',
        'recommended_stocks' => 'integer',
        'safety_stock'       => 'integer',
        'max_stock_level'    => 'integer',
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

    public function isAppliance(): bool
    {
        return strtolower($this->product_type ?? '') === 'appliance';
    }

    public function isConsumable(): bool
    {
        return strtolower($this->product_type ?? '') === 'consumable';
    }

    public function isSerialized(): bool
    {
        if ($this->isAppliance()) {
            $detail = $this->relationLoaded('applianceDetail')
                ? $this->applianceDetail
                : $this->load('applianceDetail')->applianceDetail;
            return (bool) ($detail->is_serialized ?? true);
        }
        if ($this->isConsumable()) {
            $detail = $this->relationLoaded('consumableDetail')
                ? $this->consumableDetail
                : $this->load('consumableDetail')->consumableDetail;
            return (bool) ($detail->is_serialized ?? false);
        }
        return false;
    }

    public function getPiecesPerPackage(): int
    {
        if ($this->isConsumable()) {
            $detail = $this->relationLoaded('consumableDetail')
                ? $this->consumableDetail
                : $this->load('consumableDetail')->consumableDetail;
            return max(1, (int) ($detail->pieces_per_package ?? 1));
        }
        return 1;
    }

    public function applianceDetail()
    {
        return $this->hasOne(ApplianceDetail::class, 'product_id', 'product_id');
    }

    public function consumableDetail()
    {
        return $this->hasOne(ConsumableDetail::class, 'product_id', 'product_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CategoryLookup::class, 'category_id', 'category_id');
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
     * Delegate pieces_per_package to consumable_details.
     * Called automatically when code reads $product->pieces_per_package,
     * so existing scan/receiving code keeps working without changes.
     */
    public function getPiecesPerPackageAttribute($value): int
    {
        if ($value !== null) return (int) $value;
        return (int) ($this->consumableDetail?->pieces_per_package ?? 1);
    }

}
