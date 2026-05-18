<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryScanEvent extends Model
{
    protected $table = 'inventory_scan_events';
    protected $primaryKey = 'id';

    protected $fillable = [
        'user_id',
        'transaction_type',
        'action',
        'reason',
        'barcode',
        'product_id',
        'po_id',
        'location_id',
        'to_location_id',
        'quantity_delta',
        'serial_number',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function toLocation()
    {
        return $this->belongsTo(Location::class, 'to_location_id', 'location_id');
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }
}
