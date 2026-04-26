<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ReceivingDetail (ERD: RECEIVING_DETAIL)
 * Table: receiving_details
 * Line items for each receiving transaction.
 */
class ReceivingDetail extends Model
{
    protected $table = 'receiving_details';
    protected $primaryKey = 'receiving_detail_id';

    protected $fillable = [
        'receiving_id',
        'po_detail_id',
        'product_id',
        'prod_price',
        'quantity_amount',
        'condition',
    ];

    protected $casts = [
        'prod_price'      => 'decimal:2',
        'quantity_amount' => 'integer',
    ];

    public function receiving(): BelongsTo
    {
        return $this->belongsTo(Receiving::class, 'receiving_id', 'receiving_id');
    }

    public function purchaseOrderDetail(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderDetail::class, 'po_detail_id', 'po_detail_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
