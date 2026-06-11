<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPermissionOverride extends Model
{
    protected $table = 'user_permission_overrides';
    protected $primaryKey = 'override_id';

    protected $fillable = [
        'user_id', 'permission_id', 'is_granted', 'granted_by', 'granted_at', 'revoked_at', 'reason',
    ];

    protected $casts = [
        'is_granted' => 'boolean',
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function permission()
    {
        return $this->belongsTo(Permission::class, 'permission_id', 'permission_id');
    }
}
