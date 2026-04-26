<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BrandLookup extends Model
{
    use SoftDeletes;

    protected $table = 'brand_lookup';
    protected $primaryKey = 'brand_id';

    protected $fillable = [
        'brand_name',
        'description',
    ];

    // Relationships
    public function items()
    {
        return $this->hasMany(Product::class, 'brand_id', 'brand_id');
    }

    public function models()
    {
        return $this->hasMany(ModelLookup::class, 'brand_id', 'brand_id');
    }
}