<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PermissionAuditLog extends Model
{
    protected $table = 'permission_audit_logs';
    protected $primaryKey = 'id';

    protected $fillable = [
        'changed_by',
        'target_user_id',
        'target_role_id',
        'permission_id',
        'old_value',
        'new_value',
        'change_type',
        'reason',
        'changed_at',
    ];

    protected $casts = [
        'old_value'  => 'boolean',
        'new_value'  => 'boolean',
        'changed_at' => 'datetime',
    ];

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id', 'permission_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by', 'user_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id', 'user_id');
    }
}
