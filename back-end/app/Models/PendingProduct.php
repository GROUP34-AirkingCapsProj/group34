<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingProduct extends Model
{
    protected $table = 'pending_products';

    protected $primaryKey = 'pending_product_id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'registration_kind',
        'barcode',
        'supply_type',
        'packaging_unit',
        'quantity_per_package',
        'category_id',
        'brand_id',
        'opening_location_id',
        'opening_quantity',
        'generated_name',
        'appliance_snapshot',
        'status',
        'unit_price',
        'cost_price',
        'created_by',
        'approved_by',
        'approved_at',
        'created_product_id',
    ];

    protected $casts = [
        'quantity_per_package' => 'integer',
        'opening_quantity'     => 'integer',
        'appliance_snapshot'   => 'array',
        'unit_price'           => 'decimal:2',
        'cost_price'           => 'decimal:2',
        'approved_at'          => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'pending_product_id';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CategoryLookup::class, 'category_id', 'category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(BrandLookup::class, 'brand_id', 'brand_id');
    }

    public function openingLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'opening_location_id', 'location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function createdProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'created_product_id', 'product_id');
    }
}
