<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RestockRecommendation;
use App\Models\StockParameterSuggestion;
use App\Services\Analytics\RestockAnalyticsService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class RestockRecommendationController extends Controller
{
    use ApiResponse;

    public function __construct(private RestockAnalyticsService $service)
    {
    }

    /** GET /api/restock/recommendations — latest stored batch. */
    public function index(Request $request)
    {
        $query = RestockRecommendation::with('product')
            ->orderByRaw("FIELD(priority, 'CRITICAL', 'HIGH', 'MEDIUM')")
            ->orderByDesc('avg_daily_demand');

        if ($request->filled('priority') && $request->priority !== 'all') {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->whereHas('product', function ($q) use ($s) {
                $q->where(function ($qq) use ($s) {
                    $qq->where('product_name', 'like', "%{$s}%")
                       ->orWhere('product_code', 'like', "%{$s}%");
                });
            });
        }

        $rows = $query->get();

        return $this->success([
            'run_at'          => optional($rows->first())->run_at,
            'recommendations' => $rows,
        ], 'Restock recommendations retrieved');
    }

    /** POST /api/restock/recalculate — run the engine now, return fresh rows. */
    public function recalculate(Request $request)
    {
        try {
            $this->service->run();
        } catch (\Throwable $e) {
            return $this->error('Analytics run failed: ' . $e->getMessage(), 500);
        }

        return $this->index($request);
    }
}
