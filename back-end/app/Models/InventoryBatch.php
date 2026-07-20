<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBatch extends Model
{
    protected $table = 'inventory_batches';

    protected $primaryKey = 'batch_id';

    protected $fillable = [
        'product_id',
        'location_id',
        'receiving_id',
        'quantity_received',
        'quantity_remaining',
        'received_at',
    ];

    protected $casts = [
        'quantity_received'  => 'integer',
        'quantity_remaining' => 'integer',
        'received_at'        => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function receiving(): BelongsTo
    {
        return $this->belongsTo(Receiving::class, 'receiving_id', 'receiving_id');
    }
}
