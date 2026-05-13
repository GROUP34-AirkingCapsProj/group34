<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts mutating requests to locations assigned via the user_locations pivot.
 * null/empty pivot rows = full access (admin equivalent). Non-empty rows = whitelist.
 */
class EnsureAssignedLocationAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $method = strtoupper($request->method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // Load assigned locations from pivot (cached on the model for this request)
        $assignedLocations = $user->locations()->pluck('locations.location_id')->all();

        // No pivot rows = unrestricted access (admin / newly created users)
        if (empty($assignedLocations)) {
            return $next($request);
        }

        if (! $request->has('location_id')) {
            return $next($request);
        }

        $lid = (int) $request->input('location_id');
        if ($lid > 0 && ! in_array($lid, $assignedLocations, true)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized for this warehouse/location.',
            ], 403);
        }

        return $next($request);
    }
}
