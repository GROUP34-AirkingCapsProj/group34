<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplianceDetail extends Model
{
    protected $table = 'appliance_details';
    protected $primaryKey = 'appliance_detail_id';

    protected $fillable = [
        'product_id',
        'brand_id',
        'model_id',
        'capacity_rating',
        'variant',
        'color',
        'warranty_period_months',
        'is_serialized',
    ];

    protected $casts = [
        'warranty_period_months' => 'integer',
        'is_serialized'          => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(BrandLookup::class, 'brand_id', 'brand_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(ModelLookup::class, 'model_id', 'model_id');
    }

    // Alias used by eager-load chains (applianceDetail.modelLookup)
    public function modelLookup(): BelongsTo
    {
        return $this->model();
    }
}
