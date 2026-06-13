<?php

namespace App\Services;

use App\Models\PermissionAuditLog;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Support\Facades\DB;

class PermissionAdminService
{
    /**
     * Set (or update) a role-level default for a given permission.
     */
    public function setRoleDefault(User $actor, int $roleId, int $permissionId, bool $isGranted): void
    {
        DB::transaction(function () use ($actor, $roleId, $permissionId, $isGranted) {
            $existing = RolePermission::where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->first();

            $old = $existing?->is_granted;

            RolePermission::updateOrCreate(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['is_granted' => $isGranted]
            );

            PermissionAuditLog::create([
                'changed_by'     => $actor->user_id,
                'target_role_id' => $roleId,
                'target_user_id' => null,
                'permission_id'  => $permissionId,
                'old_value'      => $old,
                'new_value'      => $isGranted,
                'change_type'    => 'role_default',
                'reason'         => null,
                'changed_at'     => now(),
            ]);
        });
    }

    /**
     * Set (or replace) an active per-user override for a given permission.
     */
    public function setUserOverride(User $actor, int $userId, int $permissionId, bool $isGranted, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $userId, $permissionId, $isGranted, $reason) {
            $prior = UserPermissionOverride::where('user_id', $userId)
                ->where('permission_id', $permissionId)
                ->whereNull('revoked_at')
                ->first();

            $old = $prior?->is_granted;

            if ($prior) {
                $prior->update(['revoked_at' => now()]);
            }

            UserPermissionOverride::create([
                'user_id'       => $userId,
                'permission_id' => $permissionId,
                'is_granted'    => $isGranted,
                'granted_by'    => $actor->user_id,
                'granted_at'    => now(),
                'revoked_at'    => null,
                'reason'        => $reason,
            ]);

            PermissionAuditLog::create([
                'changed_by'     => $actor->user_id,
                'target_user_id' => $userId,
                'target_role_id' => null,
                'permission_id'  => $permissionId,
                'old_value'      => $old,
                'new_value'      => $isGranted,
                'change_type'    => 'user_override',
                'reason'         => $reason,
                'changed_at'     => now(),
            ]);
        });
    }

    /**
     * Revoke an active per-user override (clears it back to role default).
     */
    public function clearUserOverride(User $actor, int $userId, int $permissionId, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $userId, $permissionId, $reason) {
            $prior = UserPermissionOverride::where('user_id', $userId)
                ->where('permission_id', $permissionId)
                ->whereNull('revoked_at')
                ->first();

            if (! $prior) {
                return;
            }

            $old = $prior->is_granted;

            $prior->update(['revoked_at' => now()]);

            PermissionAuditLog::create([
                'changed_by'     => $actor->user_id,
                'target_user_id' => $userId,
                'target_role_id' => null,
                'permission_id'  => $permissionId,
                'old_value'      => $old,
                'new_value'      => $old,
                'change_type'    => 'user_override_clear',
                'reason'         => $reason,
                'changed_at'     => now(),
            ]);
        });
    }
}
