<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $user = $request->user();
        $user->loadMissing('role');
        $userRole = $user->role ? $user->role->role_name : null;

        if (!$userRole || !in_array($userRole, $roles)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Required role: ' . implode(' or ', $roles),
            ], 403);
        }

        return $next($request);
    }
}
