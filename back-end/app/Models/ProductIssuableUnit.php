<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductIssuableUnit extends Model
{
    protected $table      = 'product_issuable_units';
    protected $primaryKey = 'issuable_unit_id';

    protected $fillable = [
        'product_id',
        'unit_id',
        'label',
        'conversion_factor',
        'is_whole_package',
        'sort_order',
        'show_as_quick_action',
    ];

    protected $casts = [
        'conversion_factor'    => 'float',
        'is_whole_package'     => 'boolean',
        'show_as_quick_action' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitLookup::class, 'unit_id', 'unit_id');
    }
}
