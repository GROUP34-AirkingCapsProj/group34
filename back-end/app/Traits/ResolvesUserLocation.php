<?php

namespace App\Traits;

use Illuminate\Http\Request;

trait ResolvesUserLocation
{
    protected string $locationError = '';

    /**
     * Resolves the correct location_id for a transaction based on the
     * authenticated user's role and assigned location.
     *
     * Strict roles (warehouse_personnel=4, inventory_analyst=2):
     *   Returns users.location_id. Ignores any location_id in the request.
     *   Returns null (sets $locationError) if no location is assigned.
     *
     * Branch manager (role_id=3):
     *   Uses request location_id if provided and allowed.
     *   Falls back to users.location_id. Errors if neither resolves.
     *
     * Admin (role_id=1):
     *   Uses request location_id. Errors if not provided or not active.
     *
     * @return int|null  The resolved location_id, or null on failure.
     */
    protected function resolveLocationId(Request $request): ?int
    {
        $user = $request->user();

        if (! $user) {
            $this->locationError = 'Unauthenticated.';
            return null;
        }

        $roleId = (int) $user->role_id;

        // ── STRICT ROLES: warehouse_personnel (4), inventory_analyst (2) ──
        if ($user->hasStrictLocation()) {
            if (! $user->location_id) {
                $this->locationError =
                    'Your account has no assigned location. '
                    . 'Contact your administrator to assign a location '
                    . 'before performing inventory transactions.';
                return null;
            }
            // Ignore any location_id sent by the frontend — enforce the user's assignment
            return (int) $user->location_id;
        }

        // ── BRANCH MANAGER (3) ────────────────────────────────────────────
        if ($roleId === 3) {
            $requestedId = $request->input('location_id');

            if (! $requestedId) {
                if (! $user->location_id) {
                    $this->locationError =
                        'Your account has no primary location assigned. '
                        . 'Contact your administrator.';
                    return null;
                }
                return (int) $user->location_id;
            }

            $requested = (int) $requestedId;
            if (! $user->canTransactAt($requested)) {
                $this->locationError =
                    'You are not authorized to perform transactions '
                    . 'at the selected location.';
                return null;
            }
            return $requested;
        }

        // ── ADMIN (1) and any other roles ─────────────────────────────────
        $requestedId = $request->input('location_id');
        if (! $requestedId) {
            $this->locationError = 'Please select a location to proceed.';
            return null;
        }

        $exists = \App\Models\Location::where('location_id', (int) $requestedId)
            ->where('is_active', 1)
            ->exists();

        if (! $exists) {
            $this->locationError = 'Selected location is not active.';
            return null;
        }

        return (int) $requestedId;
    }

    /**
     * Returns a standardised 422 JSON response for location resolution failures.
     */
    protected function locationErrorResponse(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'action'  => 'rejected',
            'reason'  => $this->locationError,
            'code'    => 'LOCATION_NOT_ASSIGNED',
        ], 422);
    }
}
