<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumableDetail extends Model
{
    protected $table = 'consumable_details';
    protected $primaryKey = 'consumable_detail_id';

    protected $fillable = [
        'product_id',
        'unit_id',
        'pieces_per_package',
        'package_unit',
        'is_serialized',
    ];

    protected $casts = [
        'pieces_per_package' => 'integer',
        'is_serialized'      => 'boolean',
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
