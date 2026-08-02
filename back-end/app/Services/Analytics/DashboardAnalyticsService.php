<?php

namespace App\Services\Analytics;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only analytics for the Analytics Dashboard page.
 *
 * Each public method returns arrays shaped exactly like the page's existing
 * tabData sub-objects, so the React page binds to them without transformation.
 * Aggregation happens in SQL, which is why this replaced the old client-side
 * computation: the browser was working from a 500-row page of inventory and
 * silently under-reporting every org-wide total.
 */
class DashboardAnalyticsService
{
    public function __construct(private RestockAnalyticsService $restock)
    {
    }

    /**
     * Per-piece price expression. unit_price/cost_price are per PACKAGE for
     * installation materials; appliances have no details row so the LEFT JOIN
     * yields NULL and the divisor falls back to 1.
     */
    private const PIECE_PRICE = 'p.unit_price / GREATEST(COALESCE(imd.pieces_per_package, 1), 1)';
    private const PIECE_COST  = 'p.cost_price / GREATEST(COALESCE(imd.pieces_per_package, 1), 1)';

    /**
     * The reorder point for a row: the location's own tuning when set, else the
     * product default. inventory.reorder_level is NOT NULL DEFAULT 0, so 0 means
     * "not configured here" and must fall through — hence NULLIF, not COALESCE
     * alone.
     */
    private const REORDER_POINT = 'COALESCE(NULLIF(i.reorder_level, 0), p.recommended_stocks, 0)';

    /** Trailing window, in days, for consumption and turnover. */
    private const MOVEMENT_WINDOW_DAYS = 30;

    private function inventoryQuery(?int $locationId, ?int $categoryId): Builder
    {
        return DB::table('inventory as i')
            ->join('products as p', 'p.product_id', '=', 'i.product_id')
            ->join('locations as l', 'l.location_id', '=', 'i.location_id')
            ->leftJoin('installation_material_details as imd', 'imd.product_id', '=', 'p.product_id')
            ->leftJoin('category_lookup as c', 'c.category_id', '=', 'p.category_id')
            ->when($locationId, fn (Builder $q) => $q->where('i.location_id', $locationId))
            ->when($categoryId, fn (Builder $q) => $q->where('p.category_id', $categoryId));
    }

    /** Bucket expression shared by the Stock Level and Location Performance tabs. */
    private function bucketExpression(): string
    {
        $reorder = self::REORDER_POINT;

        return "CASE
            WHEN i.quantity_on_hand <= 0 THEN 'critical'
            WHEN i.quantity_on_hand <= {$reorder} THEN 'low'
            WHEN p.max_stock_level IS NOT NULL AND i.quantity_on_hand > p.max_stock_level THEN 'overstock'
            ELSE 'optimal'
        END";
    }

    public function stockLevel(?int $locationId, ?int $categoryId): array
    {
        $bucket = $this->bucketExpression();
        $price  = self::PIECE_PRICE;

        // MySQL's ONLY_FULL_GROUP_BY rejects grouping by a CASE expression
        // while aggregating columns not functionally dependent on it, so the
        // bucket is computed in a derived table and the outer query only
        // groups by the resulting alias.
        $bucketed = $this->inventoryQuery($locationId, $categoryId)
            ->selectRaw("{$bucket} AS bucket,
                         i.quantity_on_hand AS quantity_on_hand,
                         i.quantity_on_hand * ({$price}) AS row_value");

        $rows = DB::query()
            ->fromSub($bucketed, 'b')
            ->selectRaw('bucket, COUNT(*) AS row_count,
                         SUM(quantity_on_hand) AS units,
                         SUM(row_value) AS value')
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $count = fn (string $b) => (int) ($rows->get($b)?->row_count ?? 0);

        $critical  = $count('critical');
        $low       = $count('low');
        $optimal   = $count('optimal');
        $overstock = $count('overstock');
        $total     = $critical + $low + $optimal + $overstock;

        $totalUnits = (int) $rows->sum('units');
        $totalValue = (float) $rows->sum('value');

        $pct = static fn (int $n) => $total > 0 ? (int) round(($n / $total) * 100) : 0;

        $belowReorder = $critical + $low;
        $aboveSafety  = $optimal + $overstock;

        return [
            'statsData' => [
                'totalStockValue' => round($totalValue, 2),
                'totalUnits'      => $totalUnits,
                'lowStockItems'   => $low,
                'criticalItems'   => $critical,
            ],
            'stockDistribution' => [
                'optimal'   => ['count' => $optimal,   'percentage' => $pct($optimal)],
                'lowStock'  => ['count' => $low,       'percentage' => $pct($low)],
                'critical'  => ['count' => $critical,  'percentage' => $pct($critical)],
                'overstock' => ['count' => $overstock, 'percentage' => $pct($overstock)],
            ],
            'reorderCompliance' => [
                'rate'               => $total > 0 ? (int) round((($total - $belowReorder) / $total) * 100) : 0,
                'belowReorderPoint'  => $belowReorder,
                'aboveSafetyStock'   => $aboveSafety,
            ],
            'criticalItems'  => $this->attentionItems($locationId, $categoryId),
            'overstockItems' => $this->overstockItems($locationId, $categoryId),
        ];
    }

    /** Critical + low rows for the "needs attention" list, worst first. */
    private function attentionItems(?int $locationId, ?int $categoryId): array
    {
        $reorder = self::REORDER_POINT;

        return $this->inventoryQuery($locationId, $categoryId)
            ->whereRaw("i.quantity_on_hand <= {$reorder}")
            ->selectRaw("p.product_name, l.location_name,
                         i.quantity_on_hand AS current_qty,
                         {$reorder} AS reorder_point")
            ->orderBy('i.quantity_on_hand')
            ->limit(6)
            ->get()
            ->map(static fn ($r) => [
                'name'     => $r->product_name,
                'location' => $r->location_name ?? 'N/A',
                'current'  => (int) $r->current_qty,
                'max'      => (int) $r->reorder_point,
            ])
            ->all();
    }

    private function overstockItems(?int $locationId, ?int $categoryId): array
    {
        return $this->inventoryQuery($locationId, $categoryId)
            ->whereNotNull('p.max_stock_level')
            ->whereRaw('i.quantity_on_hand > p.max_stock_level')
            ->selectRaw('p.product_name, l.location_name,
                         i.quantity_on_hand AS current_qty,
                         p.max_stock_level')
            ->orderByDesc('i.quantity_on_hand')
            ->limit(6)
            ->get()
            ->map(static fn ($r) => [
                'name'     => $r->product_name,
                'location' => $r->location_name ?? 'N/A',
                'current'  => (int) $r->current_qty,
                'optimal'  => (int) $r->max_stock_level,
            ])
            ->all();
    }

    public function movement(?int $locationId, ?int $categoryId): array
    {
        $to   = now()->toDateString();
        $from = now()->subDays(self::MOVEMENT_WINDOW_DAYS - 1)->toDateString();

        // One demand definition, shared with the restock engine, so the Movement
        // and Predictions tabs can never tell different stories.
        $demand = $this->restock->dailyDemandByLocation($from, $to);

        // Collapse to [productId => units] for the locations in scope.
        $consumed = [];
        foreach ($demand as $locId => $products) {
            if ($locationId !== null && (int) $locId !== $locationId) {
                continue;
            }
            foreach ($products as $productId => $series) {
                $consumed[(int) $productId] = ($consumed[(int) $productId] ?? 0) + array_sum($series);
            }
        }

        $stockRows = $this->inventoryQuery($locationId, $categoryId)
            ->selectRaw('i.product_id,
                         p.product_name,
                         COALESCE(c.category_name, \'Uncategorized\') AS category_name,
                         SUM(i.quantity_on_hand) AS on_hand')
            ->groupBy('i.product_id', 'p.product_name', 'c.category_name')
            ->get();

        $items = $stockRows->map(function ($r) use ($consumed) {
            $productId = (int) $r->product_id;
            $onHand    = (int) $r->on_hand;
            $units     = (float) ($consumed[$productId] ?? 0);

            return [
                'productId'   => $productId,
                'name'        => $r->product_name,
                'category'    => $r->category_name,
                'onHand'      => $onHand,
                'unitsOut'    => $units,
                // Turnover: units consumed per unit of stock held. With no stock
                // but real consumption the ratio is undefined, so fall back to the
                // raw consumed count rather than dividing by zero.
                'turnover'    => $onHand > 0 ? round($units / $onHand, 1) : $units,
            ];
        })->values();

        // The UI renders consumption on a percentage bar, so scale against the
        // busiest product in scope. An absolute unit count has no natural ceiling.
        $peak = (float) max(1, $items->max('unitsOut') ?? 1);

        $sorted    = $items->sortByDesc('turnover')->values();
        $fast      = $sorted->where('turnover', '>=', 6)->take(5)->values();
        $slow      = $sorted->where('turnover', '>', 0)->where('turnover', '<=', 3)->take(4)->values();
        $moved     = $items->where('unitsOut', '>', 0);
        $avgTurn   = $moved->count() > 0 ? (int) round($moved->avg('turnover')) : 0;

        $optimalCount = (int) $this->inventoryQuery($locationId, $categoryId)
            ->whereRaw($this->bucketExpression() . " = 'optimal'")
            ->count();

        return [
            'movementStats' => [
                'avgTurnoverRate'  => $avgTurn,
                'fastMovingCount'  => $fast->count(),
                'slowMovingCount'  => $slow->count(),
                'optimalStockCount'=> $optimalCount,
            ],
            'fastMovingItems' => $fast->map(static fn ($i) => [
                'name'     => $i['name'],
                'category' => $i['category'],
                'rate'     => (int) round($i['turnover']),
            ])->all(),
            'slowMovingItems' => $slow->map(static fn ($i) => [
                'name'     => $i['name'],
                'category' => $i['category'],
                'rate'     => (int) round($i['turnover']),
            ])->all(),
            'consumptionData' => $items->sortByDesc('unitsOut')->take(10)->values()
                ->map(static function ($i) use ($peak) {
                    $status = 'In Stock';
                    if ($i['onHand'] <= 0) {
                        $status = 'Out of Stock';
                    } elseif ($i['turnover'] >= 6) {
                        $status = 'Low Stock';
                    }

                    return [
                        'name'            => $i['name'],
                        'currentStock'    => $i['onHand'],
                        'consumptionRate' => (int) round(($i['unitsOut'] / $peak) * 100),
                        'turnover'        => ((int) round($i['turnover'])) . 'x/mo',
                        'status'          => $status,
                    ];
                })->all(),
            'weeklyMovement' => $this->weeklyBuckets($demand, $locationId),
        ];
    }

    /**
     * Trailing four weeks of demand, oldest first, matching the chart's
     * "Week 1..Week 4" labels.
     *
     * @param  array<int,array<int,array<string,float>>>  $demand
     * @return array<int,int>
     */
    private function weeklyBuckets(array $demand, ?int $locationId): array
    {
        $buckets = [0, 0, 0, 0];
        $today   = now()->startOfDay();

        foreach ($demand as $locId => $products) {
            if ($locationId !== null && (int) $locId !== $locationId) {
                continue;
            }
            foreach ($products as $series) {
                foreach ($series as $day => $qty) {
                    $daysAgo = \Carbon\Carbon::parse($day)->startOfDay()->diffInDays($today);
                    $index   = 3 - intdiv($daysAgo, 7);
                    if ($index >= 0 && $index <= 3) {
                        $buckets[$index] += (int) $qty;
                    }
                }
            }
        }

        return $buckets;
    }
}
