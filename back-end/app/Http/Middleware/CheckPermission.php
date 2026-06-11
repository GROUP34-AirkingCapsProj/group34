<?php

namespace App\Http\Middleware;

use App\Support\PermissionResolver;
use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    public function __construct(private PermissionResolver $resolver) {}

    /**
     * Usage: permission:feature,action
     */
    public function handle(Request $request, Closure $next, string $feature, string $action)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        if (! $this->resolver->canUser((int) $user->user_id, $feature, $action)) {
            return response()->json([
                'success' => false,
                'message' => "Unauthorized. Missing permission: {$feature}.{$action}",
            ], 403);
        }

        return $next($request);
    }
}
