<?php

namespace App\Traits;

use Illuminate\Http\Request;

trait ResolvesUserLocation
{
    protected string $locationError = '';

    /**
     * Resolves the correct location_id for a transaction based on the
     * authenticated user's role grouping.
     *
     * Location-scoped roles (branch_manager=3, warehouse_personnel=4):
     *   Forced to users.location_id. Any request location_id is IGNORED.
     *   Returns null (sets $locationError) if no location is assigned.
     *
     * Centralized roles (admin=1, inventory_analyst=2, auditor=5):
     *   Must supply a valid, active location_id in the request.
     *   Returns null (sets $locationError) if missing or inactive.
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

        // ── LOCATION-SCOPED ROLES (branch_manager=3, warehouse_personnel=4) ──
        // Forced to their assigned location. Any request location_id is ignored.
        if (! $user->isCentralized()) {
            $loc = $user->scopedLocationId();
            if ($loc === null) {
                $this->locationError =
                    'Your account has no assigned location. '
                    . 'Contact your administrator to assign a location '
                    . 'before performing inventory transactions.';
                return null;
            }
            return $loc;
        }

        // ── CENTRALIZED ROLES (admin=1, inventory_analyst=2, auditor=5) ──
        // Must choose a valid, active location for the transaction.
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
