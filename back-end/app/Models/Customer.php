<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use App\Traits\Auditable;

class Customer extends Model
{
    use SoftDeletes, Auditable;

    protected $table = 'customers';
    protected $primaryKey = 'customer_id';

    protected $fillable = [
        'customer_type',
        'customer_name',
        'customer_code',
        'contact_number',
        'email',
        'company_name',
        'address',
        'city',
        'province',
        'region',
        'tin',
        'notes',
        'is_active',
        'status_id',
        'credit_limit',
        'outstanding_balance',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'credit_limit' => 'decimal:2',
        'outstanding_balance' => 'decimal:2',
    ];

    public function getRouteKeyName()
    {
        return 'customer_id';
    }

    // Relationships
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'customer_id', 'customer_id');
    }

    /**
     * Delivery receipts linked through customer's sales records.
     */
    public function posDeliveryReceipts(): HasManyThrough
    {
        return $this->hasManyThrough(
            DeliveryReceipt::class,
            Sale::class,
            'customer_id',
            'sales_id',
            'customer_id',
            'sales_id'
        );
    }

}