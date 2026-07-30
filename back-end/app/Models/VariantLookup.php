<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VariantLookup extends Model
{
    use SoftDeletes;

    protected $table = 'variant_lookup';
    protected $primaryKey = 'variant_id';

    protected $fillable = [
        'variant_name',
        'category_id',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'sort_order'  => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(CategoryLookup::class, 'category_id', 'category_id');
    }
}
