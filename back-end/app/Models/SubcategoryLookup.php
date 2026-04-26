<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubcategoryLookup extends Model
{
    protected $table = 'subcategory_lookup';
    protected $primaryKey = 'subcategory_id';

    protected $fillable = [
        'category_id',
        'subcategory_name',
        'is_serialized',
    ];

    protected $casts = [
        'is_serialized' => 'boolean',
    ];

    // Relationships
    public function category()
    {
        return $this->belongsTo(CategoryLookup::class, 'category_id', 'category_id');
    }

    public function models()
    {
        return $this->hasMany(ModelLookup::class, 'subcategory_id', 'subcategory_id');
    }
}