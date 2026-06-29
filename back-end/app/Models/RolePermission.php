<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    protected $table = 'role_permissions';
    protected $primaryKey = 'id';

    protected $fillable = [
        'role_id',
        'permission_id',
        'is_granted',
    ];

    protected $casts = [
        'is_granted' => 'boolean',
    ];
}
