<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductSet extends Model
{
    use SoftDeletes, Auditable;

    protected $table      = 'product_sets';
    protected $primaryKey = 'set_id';

    protected $fillable = [
        'capacity_rating', 'variant',
        'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(ProductSetLine::class, 'set_id', 'set_id')->orderBy('sort_order');
    }

    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(BrandLookup::class, 'product_set_brands', 'set_id', 'brand_id');
    }

    /** Derived display label, e.g. "TCL, Carrier · 2HP · Non-inverter". */
    public function label(): string
    {
        $this->loadMissing('brands');
        $brands = $this->brands->pluck('brand_name')->filter()->implode(', ');

        return collect([$brands ?: 'Any brand', $this->capacity_rating, $this->variant])
            ->filter()->implode(' · ');
    }

    /**
     * Sum of line quantity (stored in pieces) x product price per piece.
     *
     * Callers listing many sets should eager-load `lines.product` on the whole
     * collection first; the loadMissing() here is a safety net for a single
     * instance, not a substitute for batching.
     */
    public function totalPrice(): float
    {
        $this->loadMissing('lines.product');

        return round($this->lines->sum(
            fn (ProductSetLine $line) => (float) $line->quantity
                * ($line->product?->getPricePerPiece() ?? 0)
        ), 2);
    }
}
