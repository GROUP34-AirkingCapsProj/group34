<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestockRecommendation extends Model
{
    use ScopesToUserLocation;

    protected $table = 'restock_recommendations';
    protected $primaryKey = 'recommendation_id';
    protected $fillable = [
        'product_id',
        'location_id',
        'current_stock',
        'reorder_level',
        'recommended_quantity',
        'reason',
        'priority',
        'reason_codes',
        'avg_daily_demand',
        'demand_trend',
        'days_of_cover',
        'lead_time_days',
        'on_order_quantity',
        'status_id',
        'run_at',
    ];

    protected $casts = [
        'reason_codes'     => 'array',
        'run_at'           => 'datetime',
        'avg_daily_demand' => 'decimal:3',
        'demand_trend'     => 'decimal:3',
        'days_of_cover'    => 'decimal:1',
        'lead_time_days'   => 'decimal:1',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }
}
