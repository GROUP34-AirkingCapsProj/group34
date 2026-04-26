<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdjustmentDetail extends Model
{
    protected $table = 'adjustment_details';
    protected $primaryKey = 'adjustment_detail_id';

    protected $fillable = [
        'adjustment_id',
        'product_id',
        'add_quantity',
        'deduct_quantity',
    ];

    protected $casts = [
        'add_quantity'    => 'integer',
        'deduct_quantity' => 'integer',
    ];

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(Adjustment::class, 'adjustment_id', 'adjustment_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
