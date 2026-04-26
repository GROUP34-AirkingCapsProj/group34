<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProfitLoss (ERD: profit_loss)
 * Table: profit_loss
 * Records losses due to damage, theft, expiry, etc.
 */
class ProfitLoss extends Model
{
    protected $table = 'profit_loss';
    protected $primaryKey = 'profit_loss_id';

    protected $fillable = [
        'model_id',
        'product_id',
        'reference_type',
        'transaction_date',
        'incident_date',
        'serial_number',
        'quantity_lost',
        'unit_cost',
        'total_loss_amount',
        'recorded_by',
        'approved_by',
        'status_id',
    ];

    protected $casts = [
        'transaction_date'  => 'date',
        'incident_date'     => 'date',
        'quantity_lost'     => 'integer',
        'unit_cost'         => 'decimal:2',
        'total_loss_amount' => 'decimal:2',
    ];

    public function model(): BelongsTo
    {
        return $this->belongsTo(ModelLookup::class, 'model_id', 'model_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by', 'user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }


}
