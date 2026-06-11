<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PermissionAuditLog extends Model
{
    protected $table = 'permission_audit_logs';
    protected $primaryKey = 'log_id';
    public $timestamps = false;

    protected $fillable = [
        'changed_by', 'target_user_id', 'target_role_id', 'permission_id',
        'old_value', 'new_value', 'change_type', 'reason', 'changed_at',
    ];

    protected $casts = [
        'old_value' => 'boolean',
        'new_value' => 'boolean',
        'changed_at' => 'datetime',
    ];

    public function permission()
    {
        return $this->belongsTo(Permission::class, 'permission_id', 'permission_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by', 'user_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_user_id', 'user_id');
    }
}
