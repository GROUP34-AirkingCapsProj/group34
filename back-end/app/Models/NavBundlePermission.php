<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NavBundlePermission extends Model
{
    protected $table = 'nav_bundle_permissions';
    protected $primaryKey = 'id';

    protected $fillable = [
        'bundle_id',
        'permission_id',
        'is_required',
    ];

    protected $casts = [
        'is_required' => 'boolean',
    ];
}
