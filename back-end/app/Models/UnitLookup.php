<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UnitLookup extends Model
{
    use SoftDeletes;

    protected $table = 'unit_lookup';
    protected $primaryKey = 'unit_id';

    protected $fillable = [
        'unit_name',
        'unit_abbreviation',
    ];
}
