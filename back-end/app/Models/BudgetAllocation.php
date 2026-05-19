<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetAllocation extends Model
{
    use Auditable;
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
        'notes',
        'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'total_budget' => 'decimal:2',
        'committed'    => 'decimal:2',
        'spent'        => 'decimal:2',
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
}
