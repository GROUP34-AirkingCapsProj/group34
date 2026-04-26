<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auditors may only use read-only HTTP methods (GET/HEAD/OPTIONS).
 * Allows auth maintenance: logout and token refresh.
 */
class AuditorReadOnly
{
    private const ALLOWED_PATH_SUFFIXES = [
        '/logout',
        '/refresh-token',
        '/user',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $user->loadMissing('role');
        $roleName = $user->role?->role_name;
        if ($roleName !== 'auditor') {
            return $next($request);
        }

        $method = strtoupper($request->method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $path = '/' . ltrim($request->path(), '/');
        foreach (self::ALLOWED_PATH_SUFFIXES as $suffix) {
            if (str_ends_with($path, $suffix)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Auditor role is read-only. You cannot modify data through the API.',
        ], 403);
    }
}
