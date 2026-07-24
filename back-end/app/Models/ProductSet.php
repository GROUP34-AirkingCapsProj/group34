<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductSet extends Model
{
    use SoftDeletes, Auditable;

    protected $table      = 'product_sets';
    protected $primaryKey = 'set_id';

    protected $fillable = [
        'set_code', 'set_name', 'description',
        'brand_id', 'capacity_rating', 'variant',
        'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(ProductSetLine::class, 'set_id', 'set_id')->orderBy('sort_order');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(BrandLookup::class, 'brand_id', 'brand_id');
    }

    /** Sum of line quantity x product unit_price. */
    public function setPrice(): float
    {
        $this->loadMissing('lines.product');

        return round($this->lines->sum(
            fn (ProductSetLine $line) => (float) $line->quantity * (float) ($line->product?->unit_price ?? 0)
        ), 2);
    }
}
