<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;

class Supplier extends Model
{
    use SoftDeletes, Auditable;

    protected $table = 'suppliers';
    protected $primaryKey = 'supplier_id';

    protected $fillable = [
        'supplier_name',
        'contact_person',
        'contact_number',
        'email',
        'address',
        'origin',
        'region',
        'tin',
        'status_id',
        'notes',
    ];

    // Relationships
    public function status()
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id', 'supplier_id');
    }

    public function purchaseReturns()
    {
        return $this->hasMany(PurchaseReturn::class, 'supplier_id', 'supplier_id');
    }

    public function products()
    {
        return $this->belongsToMany(
            Product::class,
            'supplier_product',
            'supplier_id',
            'product_id',
            'supplier_id',
            'product_id'
        )->withPivot(['supplier_prod_id', 'product_price', 'currency', 'status_id', 'created_at', 'updated_at'])
         ->withTimestamps();
    }
}