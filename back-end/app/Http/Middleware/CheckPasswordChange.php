<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Blocks users holding an admin-assigned temporary password until they choose
 * their own. Applied group-wide in routes/api.php, so the routes a blocked user
 * still needs are exempted here rather than by per-route registration.
 */
class CheckPasswordChange
{
    /**
     * Routes a flagged user must still reach. Without these the gate deadlocks:
     * the SPA could not load the identity that explains the block, the user
     * could not submit a new password, and could not even sign out.
     */
    private const EXEMPT_ROUTES = [
        'POST /api/logout',
        'GET /api/user',
        'PUT /api/me/password',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        $signature = $request->method() . ' /' . ltrim($request->path(), '/');

        if (in_array($signature, self::EXEMPT_ROUTES, true)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'You must change your password before continuing.',
            // Machine-readable so the SPA can redirect rather than guess from
            // the message text.
            'code' => 'password_change_required',
        ], 403);
    }
}
