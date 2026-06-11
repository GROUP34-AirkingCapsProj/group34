<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RolePermission extends Model
{
    protected $table = 'role_permissions';
    // Surrogate `id` PK (default). Uniqueness is enforced on (role_id, permission_id).

    protected $fillable = ['role_id', 'permission_id', 'is_granted'];

    protected $casts = ['is_granted' => 'boolean'];

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id', 'permission_id');
    }
}
