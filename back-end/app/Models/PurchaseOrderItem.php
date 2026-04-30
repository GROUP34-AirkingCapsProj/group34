<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
class PurchaseOrderItem extends Model
{
    use Auditable;
    protected $table = 'purchase_order_item';
    protected $primaryKey = 'po_item_id';

protected $fillable = [
        'po_id',
        'product_id',
        'ordered_quantity',
        'unit_cost',
        'received_quantity',
    ];

    protected $casts = [
        'ordered_quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'received_quantity' => 'decimal:2',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }

    /**
     * @deprecated Use product() instead - aligned with Product model
     */
    public function item()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    // Check if fully received
    public function isFullyReceived()
    {
        return $this->received_quantity >= $this->ordered_quantity;
    }
}
