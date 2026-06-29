<?php

namespace App\Http\Middleware;

use App\Support\PermissionResolver;
use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    public function __construct(private PermissionResolver $resolver)
    {
    }

    /**
     * Handle an incoming request.
     *
     * Usage: ->middleware('permission:feature,action')
     */
    public function handle(Request $request, Closure $next, string $feature, string $action): mixed
    {
        if (! $request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $userId = (int) $request->user()->user_id;

        if (! $this->resolver->canUser($userId, $feature, $action)) {
            return response()->json([
                'success' => false,
                'message' => "Unauthorized. Missing permission: {$feature}.{$action}",
            ], 403);
        }

        return $next($request);
    }
}
