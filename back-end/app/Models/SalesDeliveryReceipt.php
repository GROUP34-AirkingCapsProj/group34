<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery receipts linked to sales (ERD: delivery_receipt)
 * Table: delivery_receipts
 */
class SalesDeliveryReceipt extends Model
{
    protected $table = 'delivery_receipts';
    protected $primaryKey = 'dr_id';

    protected $fillable = [
        'sales_id',
        'dr_number',
        'delivery_date',
        'delivered_by',
        'issued_by',
        'received_by',
        'status_id',
    ];

    protected $casts = [
        'delivery_date' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sales_id', 'sales_id');
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by', 'user_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by', 'user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by', 'user_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }
}
