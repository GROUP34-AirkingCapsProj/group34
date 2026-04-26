<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ItemSerial (ERD: item_serial)
 * Table: item_serial
 * Fields: serial_id, product_id FK, serial_number, serial_type, status_id FK
 */
class ItemSerial extends Model
{
    protected $table = 'item_serial';
    protected $primaryKey = 'serial_id';

    protected $fillable = [
        'product_id',
        'serial_number',
        'serial_type',
        'status_id',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function purchaseReturnDetails(): HasMany
    {
        return $this->hasMany(PurchaseReturnDetails::class, 'serial_id', 'serial_id');
    }
}