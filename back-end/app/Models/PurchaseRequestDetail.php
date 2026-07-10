<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;

class PurchaseRequestDetail extends Model
{
    use Auditable;

    protected $table = 'purchase_request_details';
    protected $primaryKey = 'pr_detail_id';

    protected $fillable = [
        'pr_id',
        'product_id',
        'location_id',
        'quantity',
        'estimated_price',
        'subtotal',
        'notes',
    ];

    protected $casts = [
        'quantity'        => 'integer',
        'estimated_price' => 'decimal:2',
        'subtotal'        => 'decimal:2',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'pr_id', 'pr_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }
}
