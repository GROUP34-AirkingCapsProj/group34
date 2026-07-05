<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces every API request to be treated as expecting JSON.
 *
 * This app is API-only, but some clients (and bare curl/health checks) omit the
 * `Accept: application/json` header. Without it, Laravel's auth middleware treats
 * an unauthenticated request as a browser request and tries to redirect to a
 * `login` route that does not exist here — surfacing a 500 instead of a 401.
 * Setting the Accept header guarantees expectsJson() is true, so auth/validation
 * failures always render as clean JSON with the correct status code.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
