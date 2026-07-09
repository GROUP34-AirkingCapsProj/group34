<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBudgetException;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestDetail;
use App\Models\RestockRecommendation;
use App\Models\StockParameterSuggestion;
use App\Services\Analytics\RestockAnalyticsService;
use App\Services\BudgetService;
use App\Support\RealtimeBroadcaster;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

        $warehouseId = Location::where('location_type', 'warehouse')
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

    /**
     * POST /api/restock/create-pr
     * Turn selected recommendations into a draft Purchase Request that then
     * follows the normal PR -> approval -> PO flow.
     */
    public function createPr(Request $request)
    {
        try {
            $validated = $request->validate([
                'needed_by_date'     => 'nullable|date|after:today',
                'items'              => 'required|array|min:1',
                'items.*.product_id' => 'required|exists:products,product_id',
                'items.*.quantity'   => 'required|integer|min:1',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $warehouseId = Location::where('location_type', 'warehouse')
            ->orderBy('location_id')
            ->value('location_id');
        if (!$warehouseId) {
            return $this->error('No warehouse location is configured', 422);
        }

        $budget = app(BudgetService::class)->resolveActiveBudget((int) $warehouseId);
        if (!$budget) {
            return $this->error(
                'No active budget covers today for the warehouse. Create one in Budget Management first.',
                422
            );
        }

        try {
            $pr = DB::transaction(function () use ($validated, $request, $warehouseId, $budget) {
                $products = Product::whereIn(
                    'product_id',
                    collect($validated['items'])->pluck('product_id')
                )->get()->keyBy('product_id');

                $lines = collect($validated['items'])->map(function ($item) use ($products) {
                    $cost = (float) ($products[$item['product_id']]->cost_price ?? 0);
                    return [
                        'product_id'      => $item['product_id'],
                        'quantity'        => $item['quantity'],
                        'estimated_price' => $cost,
                        'subtotal'        => $cost * $item['quantity'],
                    ];
                });

                $estimatedTotal = $lines->sum('subtotal');
                if (!app(BudgetService::class)->hasAvailable($budget, (float) $estimatedTotal)) {
                    throw new InsufficientBudgetException(
                        $budget->period_label,
                        (float) $estimatedTotal,
                        (float) $budget->total_budget - (float) $budget->committed - (float) $budget->spent
                    );
                }

                $pr = PurchaseRequest::create([
                    'location_id'     => $warehouseId,
                    'budget_id'       => $budget->budget_id,
                    'pr_number'       => 'PR-' . date('Ymd') . '-' . strtoupper(Str::random(4)),
                    'request_date'    => today(),
                    'needed_by_date'  => $validated['needed_by_date']
                        ?? today()->addDays(7)->toDateString(),
                    'purpose'         => 'Generated from PO Recommendation analytics',
                    'estimated_total' => $estimatedTotal,
                    'status_id'       => 13, // Pending
                    'requested_by'    => $request->user()->user_id,
                ]);

                foreach ($lines as $line) {
                    PurchaseRequestDetail::create(['pr_id' => $pr->pr_id] + $line);
                }

                return $pr;
            });
        } catch (InsufficientBudgetException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('Failed to create purchase request: ' . $e->getMessage(), 500);
        }

        RealtimeBroadcaster::approvalQueueChanged((int) $pr->location_id, 'pr_changed');

        return $this->success([
            'pr_id'     => $pr->pr_id,
            'pr_number' => $pr->pr_number,
        ], 'Draft purchase request created', 201);
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

        return $this->success($query->get(), 'Stock parameter suggestions retrieved');
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
