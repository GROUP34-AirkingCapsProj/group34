<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class MaterialMovementLedger extends Model
{
    use Auditable;
    protected $table = 'material_movement_ledger';
    protected $primaryKey = 'movement_id';

protected $fillable = [
        'transaction_type_id',
        'product_id',
        'issuance_context',
        'from_location_id',
        'to_location_id',
        'quantity',
        'reference_no',
        'performed_by',
        'status_id',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    /**
     * @deprecated Use product() instead - aligned with Product model
     */
    public function item()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function fromLocation()
    {
        return $this->belongsTo(Location::class, 'from_location_id', 'location_id');
    }

    public function toLocation()
    {
        return $this->belongsTo(Location::class, 'to_location_id', 'location_id');
    }

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by', 'user_id');
    }
}