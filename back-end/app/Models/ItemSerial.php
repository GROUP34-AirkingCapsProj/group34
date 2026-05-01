<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;

class ItemSerial extends Model
{
    use Auditable;
    protected $table = 'item_serial';
    protected $primaryKey = 'serial_id';

    protected $fillable = [
        'product_id',
        'location_id',
        'receiving_id',
        'scanned_by',
        'serial_number',
        'serial_type',
        'status_id',
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

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by', 'user_id');
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
