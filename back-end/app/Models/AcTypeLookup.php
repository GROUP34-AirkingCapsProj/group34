<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcTypeLookup extends Model
{
    use SoftDeletes;

    protected $table = 'ac_type_lookup';
    protected $primaryKey = 'ac_type_id';

    protected $fillable = [
        'ac_type_name',
        'is_two_piece',
    ];

    protected $casts = [
        'is_two_piece' => 'boolean',
    ];
}
