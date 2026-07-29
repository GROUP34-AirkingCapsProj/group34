<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarrantyClaimReturn extends Model
{
    protected $primaryKey = 'return_id';
    protected $fillable = [
        'claim_id', 'product_id', 'serial_number', 'location_id',
        'holding_status', 'received_at', 'released_at',
        'release_disposition', 'replacement_warranty_id',
    ];
    protected $casts = ['received_at' => 'datetime', 'released_at' => 'datetime'];
}
