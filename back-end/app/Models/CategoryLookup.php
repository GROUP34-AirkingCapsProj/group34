<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CategoryLookup extends Model
{
    use SoftDeletes;

    protected $table = 'category_lookup';
    protected $primaryKey = 'category_id';

    protected $fillable = [
        'category_name',
        'category_type',
        'description',
    ];

    // Relationship
    public function subcategories()
    {
        return $this->hasMany(SubcategoryLookup::class, 'category_id', 'category_id');
    }
}