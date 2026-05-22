<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pre-populates the resolved location_id on the request so controllers that
 * read request input directly (without ResolvesUserLocation trait) also see
 * the enforced value. The ResolvesUserLocation trait in each scan controller
 * is the primary enforcement layer; this middleware is a convenience shim.
 */
class EnsureAssignedLocationAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // Read-only methods need no location enforcement
        $method = strtoupper($request->method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // For strict roles, pre-inject the enforced location_id so that any
        // controller or middleware downstream that reads request->input('location_id')
        // already sees the correct value without needing the trait.
        if ($user->hasStrictLocation() && $user->location_id) {
            $request->merge(['location_id' => (int) $user->location_id]);
        }

        return $next($request);
    }
}
