<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NavPermissionBundle;
use App\Traits\ApiResponse;

class NavBundleController extends Controller
{
    use ApiResponse;

    /** Bundle catalog with nested permissions (feature, action, is_required). */
    public function index()
    {
        $bundles = NavPermissionBundle::where('is_active', true)
            ->with('permissions:permission_id,feature,action')
            ->orderBy('sidebar_section')
            ->orderBy('sort_order')
            ->get()
            ->map(function ($b) {
                return [
                    'bundle_id' => $b->bundle_id,
                    'name' => $b->name,
                    'route_path' => $b->route_path,
                    'sidebar_section' => $b->sidebar_section,
                    'sidebar_icon' => $b->sidebar_icon,
                    'description' => $b->description,
                    'sort_order' => $b->sort_order,
                    'permissions' => $b->permissions->map(fn ($p) => [
                        'permission_id' => $p->permission_id,
                        'feature' => $p->feature,
                        'action' => $p->action,
                        'is_required' => (bool) $p->pivot->is_required,
                    ])->values(),
                ];
            });

        return $this->success($bundles, 'Navigation bundles');
    }
}
