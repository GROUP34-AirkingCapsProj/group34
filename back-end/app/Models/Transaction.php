<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class Transaction extends Model
{
    use Auditable;
    protected $fillable = [
        'transaction_id',
        'transaction_code',
        'branch_id',
        'item_id',
        'item_name',
        'user_id',
        'type',
        'quantity',
        'amount',
        'unit_price',
        'total_amount',
        'date',
        'status',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'amount' => 'decimal:2',
    ];

    public function branch()
    {
        return $this->belongsTo(Location::class, 'branch_id', 'location_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}