<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;

class DeliveryReceipt extends Model
{
    use Auditable, ScopesToUserLocation;

    protected $table      = 'delivery_receipts';
    protected $primaryKey = 'dr_id';

    protected $fillable = [
        'dr_number',
        'sales_id',
        'issuance_id',
        'customer_id',
        'location_id',
        'dr_date',
        'total_amount',
        'issued_by',
        'status_id',
        'notes',
    ];

    protected $casts = [
        'dr_date'      => 'date',
        'total_amount' => 'decimal:2',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sales_id', 'sales_id');
    }

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(Issuance::class, 'issuance_id', 'issuance_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
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
