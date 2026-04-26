<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERD: tbl_Inventory
 * Table: inventory (product-level inventory by location)
 */
class ProductInventory extends Model
{
    protected $table = 'inventory';
    protected $primaryKey = 'inventory_id';

    protected $fillable = [
        'location_id',
        'product_id',
        'status_id',
        'quantity_on_hand',
        'available_quantity',
        'reorder_level',
        'condition',
        'last_updated',
    ];

    protected $casts = [
        'quantity_on_hand' => 'integer',
        'available_quantity' => 'integer',
        'reorder_level' => 'integer',
        'last_updated' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }
}
