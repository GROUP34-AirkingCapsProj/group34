<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarrantyClaimDetail extends Model
{
    protected $table = 'warranty_claim_details';
    protected $primaryKey = 'claim_detail_id';

    protected $fillable = [
        'claim_id', 'product_id', 'serial_number',
        'quantity_issued', 'quantity_in_pieces', 'quantity_unit',
        'unit_cost', 'line_cost',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'line_cost' => 'decimal:2',
    ];

    public function claim()
    {
        return $this->belongsTo(WarrantyClaim::class, 'claim_id', 'claim_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
