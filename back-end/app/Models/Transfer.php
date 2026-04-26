<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inventory transfers between locations (ERD: transfer)
 * Table: transfers
 */
class Transfer extends Model
{
    protected $table = 'transfers';
    protected $primaryKey = 'transfer_id';

    protected $fillable = [
        'from_location_id',
        'to_location_id',
        'transfer_number',
        'transfer_date',
        'date',
        'transfer_by',
        'total_quantity_transferred',
        'total_quantity_received',
        'requested_by',
        'approved_by',
        'received_by',
        'status_id',
    ];

    protected $casts = [
        'transfer_date' => 'datetime',
        'date' => 'datetime',
        'total_quantity_transferred' => 'integer',
        'total_quantity_received' => 'integer',
    ];

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id', 'location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id', 'location_id');
    }

    public function transferBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transfer_by', 'user_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by', 'user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by', 'user_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(TransferDetail::class, 'transfer_id', 'transfer_id');
    }

    public function transferTracking(): HasMany
    {
        return $this->hasMany(TblTransferTracking::class, 'transfer_id', 'transfer_id');
    }
}
