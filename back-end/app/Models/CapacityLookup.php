<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CapacityLookup extends Model
{
    use SoftDeletes;

    protected $table = 'capacity_lookup';
    protected $primaryKey = 'capacity_id';

    protected $fillable = [
        'capacity_value',
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
