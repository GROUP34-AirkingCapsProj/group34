<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\DashboardAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Analytics Dashboard reads. All computation lives in
 * DashboardAnalyticsService; these actions only translate query parameters and
 * wrap the result.
 *
 * Unlike /api/batch (which this page used to call, and which carries no
 * permission middleware at all), every route here is gated on
 * analytics_dashboard.read.
 */
class AnalyticsDashboardController extends Controller
{
    public function __construct(private DashboardAnalyticsService $analytics)
    {
    }

    /**
     * The location filter these reads run under.
     *
     * analytics_dashboard.read is granted to branch_manager, a location-scoped
     * role, so the query parameter cannot be trusted on its own: a null value
     * means "every location". Scoped roles are therefore pinned to their own
     * location and the parameter is ignored, mirroring what
     * App\Traits\ResolvesUserLocation does for writes. Centralized roles
     * (admin, inventory_analyst, auditor) keep full control, including null
     * for the org-wide view.
     */
    private function locationId(Request $request): ?int
    {
        $user = $request->user();

        if ($user && ! $user->readsAllLocations()) {
            // A scoped user with no assigned location gets no rows, rather than
            // silently widening to every location (spec D3).
            return $user->scopedLocationId() ?? -1;
        }

        $value = $request->query('location_id');

        return ($value === null || $value === '') ? null : (int) $value;
    }

    private function categoryId(Request $request): ?int
    {
        $value = $request->query('category_id');

        return ($value === null || $value === '') ? null : (int) $value;
    }

    private function ok(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'generated_at' => now()->toIso8601String()]);
    }

    public function stockLevel(Request $request): JsonResponse
    {
        return $this->ok($this->analytics->stockLevel(
            $this->locationId($request),
            $this->categoryId($request)
        ));
    }

    public function movement(Request $request): JsonResponse
    {
        return $this->ok($this->analytics->movement(
            $this->locationId($request),
            $this->categoryId($request)
        ));
    }

    public function predictions(Request $request): JsonResponse
    {
        return $this->ok($this->analytics->predictions(
            $this->locationId($request),
            $this->categoryId($request)
        ));
    }

    public function profitLoss(Request $request): JsonResponse
    {
        return $this->ok($this->analytics->profitLoss(
            $this->locationId($request),
            $this->categoryId($request)
        ));
    }

    public function locationPerformance(Request $request): JsonResponse
    {
        return $this->ok($this->analytics->locationPerformance(
            $this->locationId($request),
            $this->categoryId($request)
        ));
    }

    public function filters(Request $request): JsonResponse
    {
        // The location filter must only offer what the caller can actually be
        // shown — for a scoped role every other option is a no-op now that
        // locationId() pins them to their own location.
        return $this->ok($this->analytics->filters($this->locationId($request)));
    }
}
