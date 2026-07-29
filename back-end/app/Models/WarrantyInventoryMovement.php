<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarrantyInventoryMovement extends Model
{
    protected $fillable = [
        'claim_id', 'claim_detail_id', 'product_id', 'location_id',
        'movement_type', 'quantity', 'serial_number', 'performed_by', 'moved_at',
    ];
    protected $casts = ['moved_at' => 'datetime'];
}
