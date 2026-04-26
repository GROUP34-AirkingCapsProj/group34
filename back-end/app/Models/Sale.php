<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    protected $table = 'sales';
    protected $primaryKey = 'sales_id';

    protected $fillable = [
        'customer_id',
        'location_id',
        'invoice_number',
        'sale_date',
        'total_amount',
        'payment_method',
        'payment_status_id',
        'amount_paid',
        'balance_due',
        'sold_by',
    ];

    protected $casts = [
        'sale_date'    => 'date',
        'total_amount' => 'decimal:2',
        'amount_paid'  => 'decimal:2',
        'balance_due'  => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by', 'user_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(SaleDetail::class, 'sales_id', 'sales_id');
    }

    public function paymentStatus(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'payment_status_id', 'status_id');
    }

    public function deliveryReceipts(): HasMany
    {
        return $this->hasMany(DeliveryReceipt::class, 'sales_id', 'sales_id');
    }
}
