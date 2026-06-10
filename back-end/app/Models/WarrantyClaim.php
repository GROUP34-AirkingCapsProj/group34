<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;

class WarrantyClaim extends Model
{
    use Auditable, SoftDeletes;

    protected $table = 'warranty_claims';
    protected $primaryKey = 'claim_id';

    protected $fillable = [
        'claim_number', 'warranty_id', 'customer_id', 'product_id',
        'serial_number', 'claim_type', 'notes', 'location_id',
        'status_id', 'parts_cost', 'created_by', 'completed_at',
    ];

    protected $casts = [
        'parts_cost'   => 'decimal:2',
        'completed_at' => 'datetime',
        'status_id'    => 'integer',
    ];

    public function details()
    {
        return $this->hasMany(WarrantyClaimDetail::class, 'claim_id', 'claim_id');
    }

    public function warranty()
    {
        return $this->belongsTo(WarrantyRecord::class, 'warranty_id', 'warranty_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function status()
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
