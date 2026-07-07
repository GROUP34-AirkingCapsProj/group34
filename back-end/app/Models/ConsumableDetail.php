<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumableDetail extends Model
{
    use Auditable;
    protected $table = 'installation_material_details';
    protected $primaryKey = 'consumable_detail_id';

    protected $fillable = [
        'product_id',
        'unit_id',
        'pieces_per_package',
        'package_unit',
        'is_serialized',
        'warranty_value',
        'warranty_unit',
    ];

    protected $casts = [
        'pieces_per_package' => 'integer',
        'warranty_value'     => 'integer',
        'is_serialized'      => 'boolean',
    ];

    protected $appends = ['warranty_display'];

    /**
     * Returns a human-readable warranty string.
     * Examples: "1 Year", "6 Months", "No Warranty"
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitLookup::class, 'unit_id', 'unit_id');
    }
}
