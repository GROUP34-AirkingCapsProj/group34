<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PurchaseReturnDetails (ERD: purchase_return_details)
 * Table: purchase_return_details
 * Line items for each purchase return.
 */
class PurchaseReturnDetails extends Model
{
    protected $table = 'purchase_return_details';
    protected $primaryKey = 'pr_detail_id';

    protected $fillable = [
        'purchase_return_id',
        'product_id',
        'quantity_returned',
        'unit_cost',
        'subtotal',
        'condition',
        'serial_id',
    ];

    protected $casts = [
        'quantity_returned' => 'integer',
        'unit_cost'         => 'decimal:2',
        'subtotal'          => 'decimal:2',
    ];

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id', 'purchase_return_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(ItemSerial::class, 'serial_id', 'serial_id');
    }
}
