<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DeliveryReceipt (ERD: delivery_receipt)
 * Table: delivery_receipts
 * Fields: dr_id, dr_number, sales_id FK, issued_by FK, status_id FK
 */
class DeliveryReceipt extends Model
{
    protected $table = 'delivery_receipts';
    protected $primaryKey = 'dr_id';

    protected $fillable = [
        'dr_number',
        'sales_id',
        'issued_by',
        'status_id',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sales_id', 'sales_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by', 'user_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }
}