<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BarcodeScan extends Model
{
    protected $fillable = [
        'user_id',
        'barcode',
        'product_id',
        'transfer_id',
        'scan_mode',
        'quantity',
        'scanned_at',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'quantity'   => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
