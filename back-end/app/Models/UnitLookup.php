<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitLookup extends Model
{
    protected $table = 'unit_lookup';
    protected $primaryKey = 'unit_id';

    protected $fillable = [
        'unit_name',
        'unit_abbreviation',
    ];
}
