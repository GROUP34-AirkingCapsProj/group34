<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class Warehouse extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'branch_id',
        'type',
        'location',
        'capacity',
        'contact_number',
        'manager',
        'opening_date',
        'status',
    ];

    protected $casts = [
        'opening_date' => 'date',
    ];

    public function branch()
    {
        return $this->belongsTo(Location::class, 'branch_id', 'location_id');
    }

    public function inventory()
    {
        return $this->hasMany(Inventory::class);
    }
}
