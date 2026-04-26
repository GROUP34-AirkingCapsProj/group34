<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When users.assigned_location_ids is set, restrict top-level location_id on mutating requests.
 * null = full access (default). Non-empty array = whitelist of location_id values.
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

        $allowed = $user->assigned_location_ids;
        if ($allowed === null) {
            return $next($request);
        }

        $ids = array_values(array_unique(array_map('intval', (array) $allowed)));
        if ($ids === []) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has no assigned locations. Contact an administrator.',
            ], 403);
        }

        if (! $request->has('location_id')) {
            return $next($request);
        }

        $lid = (int) $request->input('location_id');
        if ($lid > 0 && ! in_array($lid, $ids, true)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized for this warehouse/location.',
            ], 403);
        }

        return $next($request);
    }
}
