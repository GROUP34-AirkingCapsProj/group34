<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;

/**
 * PurchaseOrderDetail (ERD: purchase_order_detail)
 * Table: purchase_order_details
 * Line items for each purchase order.
 */
class PurchaseOrderDetail extends Model
{
    use Auditable;
    protected $table = 'purchase_order_details';
    protected $primaryKey = 'po_detail_id';

    protected $fillable = [
        'po_id',
        'product_id',
        'quantity_ordered',
        'unit_price',
        'subtotal',
        'approved_by',
    ];

    protected $casts = [
        'quantity_ordered' => 'integer',
        'unit_price'       => 'decimal:2',
        'subtotal'         => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function receivingDetails(): HasMany
    {
        return $this->hasMany(ReceivingDetail::class, 'po_detail_id', 'po_detail_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }
}
