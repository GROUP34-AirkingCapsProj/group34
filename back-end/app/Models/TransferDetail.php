<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;

class TransferDetail extends Model
{
    use Auditable;
    protected $table = 'transfer_details';
    protected $primaryKey = 'transfer_detail_id';

    protected $fillable = [
        'quantity_receipt_posted',
        'transfer_id',
        'inventory_id',
        'product_id',
        'item_id',
        'serial_number',
        'barcode',
        'quantity_requested',
        'quantity_transferred',
        'quantity_scanned',
        'quantity_received',
        'condition',
    ];

    protected $casts = [
        'quantity_receipt_posted' => 'integer',
        'quantity_requested'   => 'integer',
        'quantity_transferred' => 'integer',
        'quantity_scanned'     => 'integer',
        'quantity_received'    => 'integer',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'transfer_id', 'transfer_id');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(ProductInventory::class, 'inventory_id', 'inventory_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
