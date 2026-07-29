<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Supports legacy direct-download links that append ?token=... as well as
 * authenticated fetches that send the token in the Authorization header.
 * The latter keeps credentials out of URLs when the frontend loads a PDF blob.
 */
class AuthenticateFromQueryToken
{
    public function handle(Request $request, Closure $next)
    {
        $queryToken = $request->bearerToken() ?: $request->query('token');

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
