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

    private function locationId(Request $request): ?int
    {
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
        return response()->json(['success' => true, 'data' => $data]);
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
            $this->categoryId($request)
        ));
    }

    public function filters(): JsonResponse
    {
        return $this->ok($this->analytics->filters());
    }
}
