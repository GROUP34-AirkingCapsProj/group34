<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserLocation;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BudgetAllocation extends Model
{
    use SoftDeletes, Auditable, ScopesToUserLocation;
    protected $table = 'budget_allocations';
    protected $primaryKey = 'budget_id';

    protected $fillable = [
        'location_id',
        'category_id',
        'period_label',
        'period_start',
        'period_end',
        'total_budget',
        'committed',
        'spent',
        'verification_status',
        'verified_by',
        'verified_at',
        'verification_note',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'total_budget' => 'decimal:2',
        'committed'    => 'decimal:2',
        'spent'        => 'decimal:2',
        'verified_at'  => 'datetime',
    ];

    protected $appends = ['available'];

    public function getAvailableAttribute(): float
    {
        return $this->total_budget - $this->committed - $this->spent;
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CategoryLookup::class, 'category_id', 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by', 'user_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BudgetTransaction::class, 'budget_id', 'budget_id')
            ->orderByDesc('txn_id');
    }
}
