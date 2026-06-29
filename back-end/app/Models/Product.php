<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
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

    public function issuableUnits(): HasMany
    {
        return $this->hasMany(ProductIssuableUnit::class, 'product_id', 'product_id')
                    ->orderBy('sort_order');
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

    /**
     * Returns quick-action button and dropdown data for the issuance quantity
     * panel. Reads from product_issuable_units when defined; falls back to
     * computed defaults for products not yet configured.
     */
    public function buildConsumableQuickActions(): array
    {
        if ($this->isSerialized()) return [];

        // Ensure consumableDetail.unit is loaded.
        if (! $this->relationLoaded('consumableDetail')) {
            $this->load('consumableDetail.unit');
        } elseif ($this->consumableDetail && ! $this->consumableDetail->relationLoaded('unit')) {
            $this->consumableDetail->load('unit');
        }

        $cd               = $this->consumableDetail;
        $unit             = $cd?->unit;
        $piecesPerPackage = $this->getPiecesPerPackage();
        $packageUnit      = $cd?->package_unit ?? ($unit?->unit_name ?? 'package');
        $baseUnitName     = $unit?->unit_name ?? 'piece';
        $unitPrice        = (float) $this->unit_price;
        $pricePerPiece    = $piecesPerPackage > 0
            ? round($unitPrice / $piecesPerPackage, 4)
            : $unitPrice;

        // Load from product_issuable_units if defined
        $issuableUnits = $this->issuableUnits()->with('unit')->orderBy('sort_order')->get();

        $actions = [];

        if ($issuableUnits->isNotEmpty()) {
            foreach ($issuableUnits as $iu) {
                $conversionFactor = (float) $iu->conversion_factor;
                // round before ceil so float dust (e.g. 5.000001) can't bump a whole number up
                $piecesDeducted   = max(1, (int) ceil(round($conversionFactor, 4)));
                $subtotal         = round($pricePerPiece * $piecesDeducted, 2);

                $actions[] = [
                    'label'               => $iu->label,
                    'unit_id'             => $iu->unit_id,
                    'unit_name'           => $iu->unit?->unit_name ?? $baseUnitName,
                    'unit_abbreviation'   => $iu->unit?->unit_abbreviation ?? 'pc',
                    'conversion_factor'   => $conversionFactor,
                    'quantity_in_pieces'  => $piecesDeducted,
                    'is_whole_package'    => $iu->is_whole_package,
                    'show_as_quick_action'=> $iu->show_as_quick_action,
                    'price_preview'       => '₱' . number_format($subtotal, 2),
                    'is_custom'           => false,
                ];
            }
        } else {
            // Fallback: generate defaults from pieces_per_package
            foreach ([1, 2, 5] as $pkgCount) {
                $pieces   = $pkgCount * $piecesPerPackage;
                $subtotal = round($pricePerPiece * $pieces, 2);
                $label    = $pkgCount === 1
                    ? "1 {$packageUnit}"
                    : "{$pkgCount} " . Str::plural($packageUnit);

                $actions[] = [
                    'label'               => $label,
                    'unit_id'             => $cd?->unit_id,
                    'unit_name'           => $baseUnitName,
                    'unit_abbreviation'   => $unit?->unit_abbreviation ?? 'pc',
                    'conversion_factor'   => (float) $pieces,
                    'quantity_in_pieces'  => $pieces,
                    'is_whole_package'    => true,
                    'show_as_quick_action'=> true,
                    'price_preview'       => '₱' . number_format($subtotal, 2),
                    'is_custom'           => false,
                ];
            }

            // Fallback fractions for Roll products
            if ($unit && (int) $unit->unit_id === 3) {
                foreach ([
                    ['Half Roll',    11, 0.5],
                    ['Quarter Roll',  9, 0.25],
                ] as [$lbl, $uid, $frac]) {
                    $pieces   = max(1, (int) floor($piecesPerPackage * $frac));
                    $subtotal = round($pricePerPiece * $pieces, 2);
                    $actions[] = [
                        'label'               => $lbl,
                        'unit_id'             => $uid,
                        'unit_name'           => $lbl,
                        'unit_abbreviation'   => ($frac === 0.5 ? '1/2' : '1/4'),
                        'conversion_factor'   => (float) $pieces,
                        'quantity_in_pieces'  => $pieces,
                        'is_whole_package'    => false,
                        'show_as_quick_action'=> true,
                        'price_preview'       => '₱' . number_format($subtotal, 2),
                        'is_custom'           => false,
                    ];
                }
            }
        }

        // Always append Custom
        $actions[] = [
            'label'               => 'Custom',
            'unit_id'             => null,
            'unit_name'           => $baseUnitName,
            'unit_abbreviation'   => $unit?->unit_abbreviation ?? 'pc',
            'conversion_factor'   => null,
            'quantity_in_pieces'  => null,
            'is_whole_package'    => false,
            'show_as_quick_action'=> true,
            'price_preview'       => null,
            'is_custom'           => true,
        ];

        return $actions;
    }

    /**
     * Returns the units available for the custom quantity dropdown for this
     * product: the base unit plus all distinctly defined issuable unit types.
     */
    public function getIssuableDropdownUnits(): array
    {
        $unitIds = collect([$this->consumableDetail?->unit_id])
            ->merge($this->issuableUnits()->pluck('unit_id'))
            ->filter()
            ->unique()
            ->values();

        return UnitLookup::whereIn('unit_id', $unitIds)
            ->orderBy('unit_id')
            ->get(['unit_id', 'unit_name', 'unit_abbreviation'])
            ->toArray();
    }

}
