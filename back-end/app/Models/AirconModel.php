<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named combination of appliance products making up one air conditioner —
 * usually one indoor and one outdoor unit, sometimes several indoor units
 * against one outdoor for a multi-split.
 *
 * The members stay ordinary products with their own price, stock and serials.
 * This only groups them and gives the combination a name.
 */
class AirconModel extends Model
{
    use SoftDeletes, Auditable;

    protected $table      = 'aircon_models';
    protected $primaryKey = 'aircon_model_id';

    protected $fillable = [
        'model_name', 'brand_id', 'ac_type_id',
        'capacity_rating', 'variant',
        'is_two_piece', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_two_piece' => 'boolean',
        'is_active'    => 'boolean',
    ];

    public function units(): HasMany
    {
        return $this->hasMany(AirconModelUnit::class, 'aircon_model_id', 'aircon_model_id')
            ->orderBy('sort_order');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(BrandLookup::class, 'brand_id', 'brand_id');
    }

    public function acType(): BelongsTo
    {
        return $this->belongsTo(AcTypeLookup::class, 'ac_type_id', 'ac_type_id');
    }

    /** Derived display label, e.g. "TCL · 2HP · Inverter · Split Type". */
    public function label(): string
    {
        $this->loadMissing(['brand', 'acType']);

        return collect([
            $this->brand?->brand_name,
            $this->capacity_rating,
            $this->variant,
            $this->acType?->ac_type_name,
        ])->filter()->implode(' · ');
    }

    /**
     * Sum of each unit line's quantity x its product's price.
     *
     * Callers listing many models should eager-load `units.product` on the
     * whole collection first; the loadMissing() here is a safety net for a
     * single instance, not a substitute for batching.
     */
    public function totalPrice(): float
    {
        $this->loadMissing('units.product');

        return round($this->units->sum(
            fn (AirconModelUnit $unit) => $unit->quantity * (float) ($unit->product?->unit_price ?? 0)
        ), 2);
    }
}
