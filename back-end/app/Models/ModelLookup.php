<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ModelLookup extends Model
{
    use SoftDeletes;

    protected $table = 'model_lookup';
    protected $primaryKey = 'model_id';

    protected $fillable = [
        'brand_id',
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

    public function status()
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'model_id', 'model_id');
    }
}