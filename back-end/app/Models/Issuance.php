<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Issuance (ERD: issuance)
 * Table: issuances
 * Tracks the issuance of products to users/departments.
 */
class Issuance extends Model
{
    protected $table = 'issuances';
    protected $primaryKey = 'issuance_id';

    protected $fillable = [
        'location_id',
        'issuance_date',
        'issuance_type',
        'purpose',
        'issued_to_user_id',
        'expected_return_date',
        'actual_return_date',
        'issued_by',
        'approved_by',
        'status_id',
    ];

    protected $casts = [
        'issuance_date'        => 'date',
        'expected_return_date' => 'date',
        'actual_return_date'   => 'date',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function issuedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_to_user_id', 'user_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by', 'user_id');
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
        return $this->hasMany(IssuanceDetail::class, 'issuance_id', 'issuance_id');
    }
}
