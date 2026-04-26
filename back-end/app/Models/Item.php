<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;  // ← KULANG MO TO! Import the trait

class Item extends Model
{
    use Auditable;  // ← Now this will work!
    
    protected $fillable = [
        'code',
        'name',
        'category',
        'brand',
        'barcode',
        'description',
        'price',
        'unit',
        'reorder_level',
        'supplier',
        'status',
        'type',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'reorder_level' => 'integer',
    ];

    protected $appends = ['unit_price'];

    public function getUnitPriceAttribute(): ?float
    {
        return $this->price !== null ? (float) $this->price : null;
    }

    public function setUnitPriceAttribute($value): void
    {
        $this->attributes['price'] = $value;
    }

    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}