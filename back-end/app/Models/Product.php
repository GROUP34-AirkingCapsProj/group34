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
    // Reads from installation_material_details; returns 1 for appliances.
    //
    // unit_role and ac_type_name ride along for a specific operational reason:
    // an indoor unit and its outdoor partner share a brand, a capacity and
    // usually a near-identical name, so on a scanning or picking screen they
    // are indistinguishable. Staff need to see WHICH HALF a row is before
    // they scan it. Appending them here rather than in each controller means
    // every screen that serialises a product — receiving, issuance, incident,
    // transfers, adjustments — gets them without its own plumbing.
    protected $appends = ['pieces_per_package', 'unit_role', 'ac_type_name'];

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
        return $query->where('product_type', 'installation_material');
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
        return strtolower($this->product_type ?? '') === 'installation_material';
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

    /**
     * Product prices for installation materials are stored per package, while
     * inventory quantities are stored as individual pieces.
     */
    public function getPricePerPiece(?float $price = null): float
    {
        $packagePrice = $price ?? (float) $this->unit_price;
        return round($packagePrice / $this->getPiecesPerPackage(), 4);
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
     * Delegate pieces_per_package to installation_material_details.
     * Called automatically when code reads $product->pieces_per_package,
     * so existing scan/receiving code keeps working without changes.
     */
    /**
     * Which half of a split aircon this product is: indoor, outdoor, or
     * complete. Null for anything that is not an appliance.
     *
     * Lazy-loads applianceDetail when it is not already there, matching
     * pieces_per_package below. That is a deliberate choice over returning
     * null when unloaded: this value is read on scanning and picking screens
     * to tell two near-identical halves apart, and a silently absent badge
     * would read as "not an aircon" rather than "not loaded" — the one
     * failure mode that could put the wrong carton on a truck.
     *
     * The cost is one query per product row on any screen that does not
     * eager-load. Callers listing products should eager-load
     * `applianceDetail` (and `applianceDetail.acType` for the name below).
     */
    public function getUnitRoleAttribute(): ?string
    {
        if (! $this->isAppliance()) {
            return null;
        }

        $detail = $this->relationLoaded('applianceDetail')
            ? $this->applianceDetail
            : $this->load('applianceDetail')->applianceDetail;

        return $detail?->unit_role;
    }

    /**
     * The AC kind's display name — "Split Type", "Cassette" — or null when
     * the product is not an appliance or carries no type.
     *
     * See getUnitRoleAttribute() for why this lazy-loads rather than
     * returning null when the relation is cold.
     */
    public function getAcTypeNameAttribute(): ?string
    {
        if (! $this->isAppliance()) {
            return null;
        }

        $detail = $this->relationLoaded('applianceDetail')
            ? $this->applianceDetail
            : $this->load('applianceDetail')->applianceDetail;

        return $detail?->acType?->ac_type_name;
    }

    public function getPiecesPerPackageAttribute($value): int
    {
        if ($value !== null) return (int) $value;
        return (int) ($this->consumableDetail?->pieces_per_package ?? 1);
    }

    /**
     * Returns the units available for the custom quantity dropdown for this
     * product: the product's base unit.
     */
    public function getIssuableDropdownUnits(): array
    {
        $unitIds = collect([$this->consumableDetail?->unit_id])
            ->filter()
            ->unique()
            ->values();

        return UnitLookup::whereIn('unit_id', $unitIds)
            ->orderBy('unit_id')
            ->get(['unit_id', 'unit_name', 'unit_abbreviation'])
            ->toArray();
    }

}
