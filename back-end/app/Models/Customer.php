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
        'customer_code', 'customer_type', 'customer_name',
        'contact_number', 'email', 'company_name', 'address',
        'city', 'province', 'region', 'tin', 'notes',
        'credit_limit', 'outstanding_balance', 'status_id',
    ];

    protected $casts = [
        'credit_limit'        => 'decimal:2',
        'outstanding_balance' => 'decimal:2',
    ];

    public function getRouteKeyName()
    {
        return 'customer_id';
    }

    public function status()
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function issuances(): HasMany
    {
        return $this->hasMany(Issuance::class, 'customer_id', 'customer_id')
                    ->orderBy('issuance_date', 'desc');
    }

    public function warrantyRecords(): HasMany
    {
        return $this->hasMany(WarrantyRecord::class, 'customer_id', 'customer_id')
                    ->orderBy('created_at', 'desc');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'customer_id', 'customer_id');
    }

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

    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }

    public function scopeRegistered($query)
    {
        return $query->where('customer_type', 'registered');
    }

    public function scopeWalkIn($query)
    {
        return $query->where('customer_type', 'walk_in');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('customer_type', $type);
    }
}
