<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockParameterSuggestion extends Model
{
    protected $table = 'stock_parameter_suggestions';
    protected $primaryKey = 'suggestion_id';
    protected $guarded = [];

    protected $casts = [
        'reason_codes' => 'array',
        'run_at'       => 'datetime',
        'reviewed_at'  => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'user_id');
    }
}
