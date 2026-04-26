<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PurchaseReturn (ERD: purchase_return)
 * Table: purchase_returns
 * Records returns of goods back to suppliers.
 */
class PurchaseReturn extends Model
{
    protected $table = 'purchase_returns';
    protected $primaryKey = 'purchase_return_id';

    protected $fillable = [
        'po_id',
        'supplier_id',
        'receiving_id',
        'pr_number',
        'return_date',
        'reason',
        'total_amount',
        'status_id',
        'requested_by',
        'approved_by',
    ];

    protected $casts = [
        'return_date'  => 'date',
        'total_amount' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function receiving(): BelongsTo
    {
        return $this->belongsTo(Receiving::class, 'receiving_id', 'receiving_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by', 'user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(PurchaseReturnDetails::class, 'purchase_return_id', 'purchase_return_id');
    }
}
