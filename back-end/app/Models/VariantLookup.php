<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VariantLookup extends Model
{
    use SoftDeletes;

    protected $table = 'variant_lookup';
    protected $primaryKey = 'variant_id';

    protected $fillable = [
        'variant_name',
    ];
}
