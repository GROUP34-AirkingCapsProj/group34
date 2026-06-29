<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NavPermissionBundle;
use App\Traits\ApiResponse;

class NavBundleController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/nav-bundles
     * Returns all active navigation permission bundles with their required permissions.
     */
    public function index()
    {
        $bundles = NavPermissionBundle::where('is_active', true)
            ->with('permissions:permission_id,feature,action')
            ->orderBy('sidebar_section')
            ->orderBy('sort_order')
            ->get()
            ->map(function ($bundle) {
                return [
                    'bundle_id'       => $bundle->bundle_id,
                    'name'            => $bundle->name,
                    'route_path'      => $bundle->route_path,
                    'sidebar_section' => $bundle->sidebar_section,
                    'sidebar_icon'    => $bundle->sidebar_icon,
                    'description'     => $bundle->description,
                    'sort_order'      => $bundle->sort_order,
                    'permissions'     => $bundle->permissions->map(function ($permission) {
                        return [
                            'permission_id' => $permission->permission_id,
                            'feature'       => $permission->feature,
                            'action'        => $permission->action,
                            'is_required'   => (bool) $permission->pivot->is_required,
                        ];
                    })->values()->all(),
                ];
            });

        return $this->success($bundles, 'Navigation bundles');
    }
}
