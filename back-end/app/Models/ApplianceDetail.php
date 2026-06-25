<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplianceDetail extends Model
{
    use Auditable;
    protected $table = 'appliance_details';
    protected $primaryKey = 'appliance_detail_id';

    protected $fillable = [
        'product_id',
        'brand_id',
        'model_code',
        'capacity_rating',
        'variant',
        'color',
        'warranty_period_months',
        'warranty_value',
        'warranty_unit',
        'is_serialized',
    ];

    protected $casts = [
        'warranty_period_months' => 'integer',
        'warranty_value'         => 'integer',
        'is_serialized'          => 'boolean',
    ];

    protected $appends = ['warranty_display'];

    /**
     * Returns a human-readable warranty string.
     * Examples: "2 Years", "6 Months", "No Warranty"
     */
    public function getWarrantyDisplayAttribute(): string
    {
        $value = (int) ($this->warranty_value ?? 0);
        $unit  = $this->warranty_unit ?? 'month';

        if ($value <= 0) {
            return 'No Warranty';
        }

        $label = ucfirst($unit);
        if ($value > 1) {
            $label .= 's';
        }

        return "{$value} {$label}";
    }

    /**
     * Computes warranty_period_months from value + unit.
     * Call this before saving when warranty fields change.
     */
    public function computeWarrantyMonths(): int
    {
        $value = (int) ($this->warranty_value ?? 0);
        $unit  = $this->warranty_unit ?? 'month';

        if ($value <= 0) return 0;
        return $unit === 'year' ? $value * 12 : $value;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(BrandLookup::class, 'brand_id', 'brand_id');
    }
}
