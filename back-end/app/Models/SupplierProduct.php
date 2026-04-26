<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierProduct extends Model
{
    protected $table = 'supplier_product';
    protected $primaryKey = 'supplier_prod_id';

    protected $fillable = [
        'supplier_id',
        'product_id',
        'product_price',
        'currency',
        'status_id',
    ];

    protected $casts = [
        'product_price' => 'decimal:2',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function status()
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }
}
