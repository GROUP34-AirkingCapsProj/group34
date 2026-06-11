<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    protected $table = 'role_permissions';
    // Surrogate `id` PK (default). Uniqueness is enforced on (role_id, permission_id).

    protected $fillable = ['role_id', 'permission_id', 'is_granted'];

    protected $casts = ['is_granted' => 'boolean'];

    public function permission()
    {
        return $this->belongsTo(Permission::class, 'permission_id', 'permission_id');
    }
}
