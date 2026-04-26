<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Adjustment (ERD: adjustment)
 * Table: adjustments
 * Fields: adjustment_id, location_id FK, created_by FK, approved_by FK,
 *         adjustment_number, date, adjustment_type, adjusted_by (varchar)
 */
class Adjustment extends Model
{
    protected $table = 'adjustments';
    protected $primaryKey = 'adjustment_id';

    protected $fillable = [
        'location_id',
        'created_by',
        'approved_by',
        'adjustment_number',
        'adjustment_date',   // ERD column name (was 'date' after a prior migration)
        'adjustment_type',
        'adjusted_by',
        'status_id',
    ];

    protected $casts = [
        'adjustment_date' => 'date',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(AdjustmentDetail::class, 'adjustment_id', 'adjustment_id');
    }
}
