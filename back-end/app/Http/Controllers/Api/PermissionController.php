<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Models\PermissionAuditLog;
use App\Services\PermissionAdminService;
use App\Support\PermissionResolver;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private PermissionAdminService $admin,
        private PermissionResolver $resolver,
    ) {}

    /**
     * GET /api/permissions
     * Full catalog of all permission rows, ordered by feature then action.
     */
    public function index()
    {
        return $this->success(
            Permission::orderBy('feature')->orderBy('action')->get(),
            'Permissions catalog'
        );
    }

    /**
     * GET /api/role-permissions
     * All role_permissions rows (role_id, permission_id, is_granted).
     */
    public function roleDefaults()
    {
        return $this->success(
            RolePermission::get(['role_id', 'permission_id', 'is_granted']),
            'Role permission defaults'
        );
    }

    /**
     * PUT /api/role-permissions
     * Bulk-update role-level defaults. Admin role cannot be targeted.
     */
    public function updateRoleDefaults(Request $request)
    {
        $request->validate([
            'changes'                  => 'required|array|min:1',
            'changes.*.role_id'        => 'required|integer|exists:roles,role_id',
            'changes.*.permission_id'  => 'required|integer|exists:permissions,permission_id',
            'changes.*.is_granted'     => 'required|boolean',
        ]);

        $adminRoleId = Role::where('role_name', 'admin')->value('role_id');

        foreach ($request->input('changes') as $change) {
            if ((int) $change['role_id'] === (int) $adminRoleId) {
                return $this->error('The admin role permissions cannot be modified.', 403);
            }
        }

        foreach ($request->input('changes') as $change) {
            $this->admin->setRoleDefault(
                $request->user(),
                (int) $change['role_id'],
                (int) $change['permission_id'],
                (bool) $change['is_granted']
            );
        }

        return $this->success(null, 'Role permissions updated');
    }

    /**
     * GET /api/me/permissions
     * Effective permission map for the currently authenticated user.
     */
    public function mine(Request $request)
    {
        return $this->success(
            $this->resolver->effectivePermissionsFor((int) $request->user()->user_id),
            'My permissions'
        );
    }

    /**
     * GET /api/users/{userId}/permissions
     * Effective + active overrides for a specific user.
     */
    public function userPermissions(Request $request, $userId)
    {
        $user = User::where('user_id', $userId)->first();

        if (! $user) {
            return $this->error('User not found.', 404);
        }

        return $this->success([
            'effective' => $this->resolver->effectivePermissionsFor((int) $userId),
            'overrides' => UserPermissionOverride::where('user_id', $userId)
                ->whereNull('revoked_at')
                ->get(['permission_id', 'is_granted']),
        ], 'User permissions');
    }

    /**
     * PUT /api/users/{userId}/permissions
     * Bulk-set or clear per-user overrides. Admin users cannot be overridden.
     */
    public function updateUserPermissions(Request $request, $userId)
    {
        $request->validate([
            'reason'                   => 'required|string|min:1',
            'changes'                  => 'required|array|min:1',
            'changes.*.permission_id'  => 'required|integer|exists:permissions,permission_id',
            'changes.*.is_granted'     => 'nullable|boolean',
        ]);

        $target = User::where('user_id', $userId)->first();

        if (! $target) {
            return $this->error('User not found.', 404);
        }

        $targetRole = Role::where('role_id', $target->role_id)->first();

        if ($targetRole && $targetRole->role_name === 'admin') {
            return $this->error('Permissions of an admin user cannot be overridden.', 403);
        }

        $reason = $request->input('reason');

        foreach ($request->input('changes') as $change) {
            $permId = (int) $change['permission_id'];

            if (array_key_exists('is_granted', $change) && $change['is_granted'] === null) {
                $this->admin->clearUserOverride($request->user(), (int) $userId, $permId, $reason);
            } else {
                $this->admin->setUserOverride(
                    $request->user(),
                    (int) $userId,
                    $permId,
                    (bool) $change['is_granted'],
                    $reason
                );
            }
        }

        return $this->success(null, 'User permissions updated');
    }

    /**
     * GET /api/permission-audit-logs
     * Paginated audit log with optional filters.
     */
    public function auditLogs(Request $request)
    {
        $request->validate([
            'target_user_id' => 'sometimes|integer',
            'target_role_id' => 'sometimes|integer',
            'change_type'    => 'sometimes|string',
            'feature'        => 'sometimes|string',
            'action'         => 'sometimes|string',
            'date_from'      => 'sometimes|date',
            'date_to'        => 'sometimes|date',
            'per_page'       => 'sometimes|integer|min:1|max:200',
        ]);

        $query = PermissionAuditLog::with(['permission', 'changedBy', 'targetUser'])
            ->orderByDesc('changed_at');

        if ($request->filled('target_user_id')) {
            $query->where('target_user_id', $request->input('target_user_id'));
        }

        if ($request->filled('target_role_id')) {
            $query->where('target_role_id', $request->input('target_role_id'));
        }

        if ($request->filled('change_type')) {
            $query->where('change_type', $request->input('change_type'));
        }

        if ($request->filled('date_from')) {
            $query->where('changed_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('changed_at', '<=', $request->input('date_to') . ' 23:59:59');
        }

        if ($request->filled('feature') || $request->filled('action')) {
            $query->whereHas('permission', function ($q) use ($request) {
                if ($request->filled('feature')) {
                    $q->where('feature', $request->input('feature'));
                }
                if ($request->filled('action')) {
                    $q->where('action', $request->input('action'));
                }
            });
        }

        return $this->success(
            $query->paginate($request->input('per_page', 50)),
            'Permission audit logs'
        );
    }
}
