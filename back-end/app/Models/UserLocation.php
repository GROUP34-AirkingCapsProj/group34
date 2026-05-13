<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLocation extends Model
{
    protected $table = 'user_locations';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'location_id',
    ];
}
