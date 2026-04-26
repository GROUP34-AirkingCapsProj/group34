<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelLookup extends Model
{
    protected $table = 'model_lookup';
    protected $primaryKey = 'model_id';

    protected $fillable = [
        'brand_id',
        'subcategory_id',
        'model_code',   // ERD: model_code (was model_name)
        'variant',      // ERD: variant    (was model_number)
        'capacity',     // ERD: capacity   (was specifications) — e.g. 1HP, 2HP, 3HP
        'status_id',
    ];

    // Relationships
    public function brand()
    {
        return $this->belongsTo(BrandLookup::class, 'brand_id', 'brand_id');
    }

    public function subcategory()
    {
        return $this->belongsTo(SubcategoryLookup::class, 'subcategory_id', 'subcategory_id');
    }

    public function status()
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'model_id', 'model_id');
    }
}