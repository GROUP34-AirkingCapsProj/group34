<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class WarrantyClaim extends Model
{
    use Auditable;

    protected $fillable = [
        'warranty_code',
        'customer_name',
        'customer_contact',
        'item_name',
        'serial_number',
        'issue',
        'branch',
        'priority',
        'status',
        'technician',
        'estimated_date',
    ];

    protected $casts = [
        'estimated_date' => 'date',
    ];
}
