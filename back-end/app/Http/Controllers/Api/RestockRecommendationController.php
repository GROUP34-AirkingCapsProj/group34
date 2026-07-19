<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\RestockRecommendation;
use App\Models\StockParameterSuggestion;
use App\Services\Analytics\RestockAnalyticsService;
use App\Services\BudgetService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $locationId = $request->filled('location_id') ? (int) $request->location_id : null;
        if ($locationId) {
            $query->where('location_id', $locationId);
        }

        $rows = $query->get();

        $warehouseId = $locationId ?? Location::where('location_type', 'warehouse')
            ->orderBy('location_id')
            ->value('location_id');
        $budget = $warehouseId
            ? app(BudgetService::class)->resolveActiveBudget((int) $warehouseId)
            : null;

        return $this->success([
            // Fall back to the suggestions table so a run that produced zero
            // recommendations still reports when it happened.
            'run_at'          => optional($rows->first())->run_at
                ?? StockParameterSuggestion::max('run_at'),
            'recommendations' => $rows,
            'budget'          => $budget ? [
                'budget_id'    => $budget->budget_id,
                'period_label' => $budget->period_label,
                'total_budget' => (float) $budget->total_budget,
                'available'    => (float) $budget->total_budget
                    - (float) $budget->committed
                    - (float) $budget->spent,
            ] : null,
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

    /** GET /api/restock/suggestions?status=pending|applied|dismissed|all */
    public function suggestions(Request $request)
    {
        $status = $request->input('status', 'pending');

        $query = StockParameterSuggestion::with('product')
            ->orderByDesc('created_at');
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        $suggestions = $query->get();

        // Attach the warehouse(s) each product is stocked at (inventory rows at
        // warehouse-type locations) so the UI can show and filter by warehouse.
        $productIds = $suggestions->pluck('product_id')->filter()->unique()->values()->all();
        $whByProduct = collect();
        if ($productIds) {
            $whByProduct = DB::table('inventory as inv')
                ->join('locations as l', 'l.location_id', '=', 'inv.location_id')
                ->where('l.location_type', 'warehouse')
                ->whereIn('inv.product_id', $productIds)
                ->select('inv.product_id', 'l.location_id', 'l.location_name')
                ->distinct()
                ->get()
                ->groupBy('product_id');
        }

        $data = $suggestions->map(function ($s) use ($whByProduct) {
            $arr = $s->toArray();
            $arr['warehouses'] = ($whByProduct[$s->product_id] ?? collect())
                ->map(fn ($r) => ['location_id' => (int) $r->location_id, 'location_name' => $r->location_name])
                ->values()
                ->all();
            return $arr;
        });

        return $this->success($data, 'Stock parameter suggestions retrieved');
    }

    /**
     * POST /api/restock/suggestions/{id}/apply
     * Writes the suggested values to the product; the suggestion row stays
     * as the audit trail. Rejects double-applies and stale snapshots.
     */
    public function applySuggestion(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $s = StockParameterSuggestion::lockForUpdate()->find($id);
            if (!$s) {
                return $this->notFound('Suggestion not found');
            }
            if ($s->status !== 'pending') {
                return $this->error('Suggestion is no longer pending', 422);
            }

            $product = Product::lockForUpdate()->find($s->product_id);
            if (!$product) {
                return $this->error('Product no longer exists', 422);
            }

            $stale = (int) $product->recommended_stocks !== (int) $s->current_recommended
                || (int) ($product->safety_stock ?? 0) !== (int) ($s->current_safety ?? 0)
                || (int) ($product->max_stock_level ?? 0) !== (int) ($s->current_max ?? 0);
            if ($stale) {
                return $this->error(
                    'Product stock parameters changed since this suggestion was computed. Recalculate first.',
                    409
                );
            }

            $product->update([
                'recommended_stocks' => $s->suggested_recommended,
                'safety_stock'       => $s->suggested_safety,
                'max_stock_level'    => $s->suggested_max,
            ]);
            $s->update([
                'status'      => 'applied',
                'reviewed_by' => $request->user()->user_id,
                'reviewed_at' => now(),
            ]);

            return $this->success($s->fresh('product'), 'Suggestion applied');
        });
    }

    /** POST /api/restock/suggestions/{id}/dismiss */
    public function dismissSuggestion(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $s = StockParameterSuggestion::lockForUpdate()->find($id);
            if (!$s) {
                return $this->notFound('Suggestion not found');
            }
            if ($s->status !== 'pending') {
                return $this->error('Suggestion is no longer pending', 422);
            }

            $s->update([
                'status'      => 'dismissed',
                'reviewed_by' => $request->user()->user_id,
                'reviewed_at' => now(),
            ]);

            return $this->success($s->fresh(), 'Suggestion dismissed');
        });
    }
}
