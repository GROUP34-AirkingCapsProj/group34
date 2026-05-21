<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * For direct-download endpoints (CSV), the frontend appends ?token=...
 * because the browser opens the URL directly and cannot set Authorization headers.
 * This middleware resolves and validates the Sanctum personal access token from the
 * query string, logs the user in on all guards, and aborts with 401 if invalid.
 * Use this INSTEAD of auth:sanctum on download-only routes.
 */
class AuthenticateFromQueryToken
{
    public function handle(Request $request, Closure $next)
    {
        $queryToken = $request->query('token');

        if (! $queryToken) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        [$id, $plain] = array_pad(explode('|', $queryToken, 2), 2, null);

        if (! $id || ! $plain) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $pat = PersonalAccessToken::find((int) $id);

        if (! $pat
            || ! hash_equals($pat->token, hash('sha256', $plain))
            || ($pat->expires_at && $pat->expires_at->isPast())
        ) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user = $pat->tokenable;
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        Auth::setUser($user);
        Auth::guard('sanctum')->setUser($user);
        PersonalAccessToken::where('id', $pat->id)->update(['last_used_at' => now()]);

        return $next($request);
    }
}
