<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\PermissionAuditLog;
use App\Models\RolePermission;
use App\Models\UserPermissionOverride;
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

    /** Full permission catalog. */
    public function index()
    {
        return $this->success(
            Permission::orderBy('feature')->orderBy('action')->get(),
            'Permissions catalog'
        );
    }

    /** All role default grants. */
    public function roleDefaults()
    {
        return $this->success(
            RolePermission::get(['role_id', 'permission_id', 'is_granted']),
            'Role permission defaults'
        );
    }

    /** Update one or more role default cells. */
    public function updateRoleDefaults(Request $request)
    {
        $data = $request->validate([
            'changes' => ['required', 'array', 'min:1'],
            'changes.*.role_id' => ['required', 'integer'],
            'changes.*.permission_id' => ['required', 'integer', 'exists:permissions,permission_id'],
            'changes.*.is_granted' => ['required', 'boolean'],
        ]);

        foreach ($data['changes'] as $change) {
            $this->admin->setRoleDefault($request->user(), (int) $change['role_id'], (int) $change['permission_id'], (bool) $change['is_granted']);
        }

        return $this->success(null, 'Role permissions updated');
    }

    /** A user's effective permissions, plus active overrides. */
    public function userPermissions(Request $request, $userId)
    {
        $overrides = UserPermissionOverride::where('user_id', $userId)
            ->whereNull('revoked_at')->get(['permission_id', 'is_granted']);

        return $this->success([
            'effective' => $this->resolver->effectivePermissionsFor((int) $userId),
            'overrides' => $overrides,
        ], 'User permissions');
    }

    /** Set or clear overrides for a user. A reason is required. */
    public function updateUserPermissions(Request $request, $userId)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:1'],
            'changes' => ['required', 'array', 'min:1'],
            'changes.*.permission_id' => ['required', 'integer', 'exists:permissions,permission_id'],
            'changes.*.is_granted' => ['nullable', 'boolean'], // null = clear override
        ]);

        foreach ($data['changes'] as $change) {
            $permId = (int) $change['permission_id'];
            if (array_key_exists('is_granted', $change) && $change['is_granted'] === null) {
                $this->admin->clearUserOverride($request->user(), (int) $userId, $permId, $data['reason']);
            } else {
                $this->admin->setUserOverride($request->user(), (int) $userId, $permId, (bool) $change['is_granted'], $data['reason']);
            }
        }

        return $this->success(null, 'User permissions updated');
    }

    /** Filterable audit feed. */
    public function auditLogs(Request $request)
    {
        $query = PermissionAuditLog::with(['permission', 'changedBy', 'targetUser'])
            ->orderByDesc('changed_at');

        if ($request->filled('target_user_id')) {
            $query->where('target_user_id', $request->target_user_id);
        }
        if ($request->filled('target_role_id')) {
            $query->where('target_role_id', $request->target_role_id);
        }
        if ($request->filled('change_type')) {
            $query->where('change_type', $request->change_type);
        }
        if ($request->filled('feature') || $request->filled('action')) {
            $query->whereHas('permission', function ($q) use ($request) {
                if ($request->filled('feature')) {
                    $q->where('feature', $request->feature);
                }
                if ($request->filled('action')) {
                    $q->where('action', $request->action);
                }
            });
        }
        if ($request->filled('date_from')) {
            $query->where('changed_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('changed_at', '<=', $request->date_to);
        }

        return $this->success($query->paginate($request->input('per_page', 50)), 'Permission audit logs');
    }
}
