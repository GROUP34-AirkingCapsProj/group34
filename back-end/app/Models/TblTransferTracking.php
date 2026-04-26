<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERD: tbl_transfer_tracking
 */
class TblTransferTracking extends Model
{
    protected $table = 'tbl_transfer_tracking';
    protected $primaryKey = 'tracking_id';

    protected $fillable = [
        'transfer_id',
        'location_id',
        'status_note',
        'recorded_at',
        'recorded_by',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'transfer_id', 'transfer_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by', 'user_id');
    }
}
