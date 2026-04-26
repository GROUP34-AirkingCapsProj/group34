<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class Inventory extends Model
{
    use Auditable;

    protected $table = 'inventory';

    protected $primaryKey = 'inventory_id';

    protected $fillable = [
        'location_id',
        'product_id',
        'quantity_on_hand',
        'available_quantity',
        'reorder_level',
    ];

    protected $casts = [
        'quantity_on_hand'   => 'integer',
        'available_quantity' => 'integer',
        'reorder_level'      => 'integer',
    ];

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}