<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CapacityLookup extends Model
{
    use SoftDeletes;

    protected $table = 'capacity_lookup';
    protected $primaryKey = 'capacity_id';

    protected $fillable = [
        'capacity_value',
    ];
}
