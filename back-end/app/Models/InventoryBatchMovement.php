<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryBatchMovement extends Model
{
    protected $primaryKey = 'batch_movement_id';

    protected $fillable = [
        'batch_id', 'movement_type', 'issuance_id', 'transfer_id',
        'product_id', 'from_location_id', 'to_location_id', 'quantity',
        'serial_number', 'performed_by', 'moved_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'moved_at' => 'datetime',
    ];
}
