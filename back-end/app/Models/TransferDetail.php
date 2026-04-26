<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferDetail extends Model
{
    protected $table = 'transfer_details';
    protected $primaryKey = 'transfer_detail_id';

    protected $fillable = [
        'transfer_id',
        'inventory_id',
        'product_id',
        'item_id',
        'serial_number',
        'barcode',
        'quantity_transferred',
        'quantity_received',
        'condition',
    ];

    protected $casts = [
        'quantity_transferred' => 'integer',
        'quantity_received' => 'integer',
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
