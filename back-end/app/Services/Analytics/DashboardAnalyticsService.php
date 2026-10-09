<?php

namespace App\Services\Analytics;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read-only analytics for the Analytics Dashboard page.
 *
 * Each response includes complete supporting records alongside its summaries.
 * Stock valuation and thresholds share StockSnapshotService with generated
 * reports; charts never aggregate a truncated browser page of inventory.
 */
class DashboardAnalyticsService
{
    public function __construct(private RestockAnalyticsService $restock, private StockSnapshotService $stock)
    {
    }

    /**
     * Per-piece cost expression. cost_price is per PACKAGE for
     * installation materials; appliances have no details row so the LEFT JOIN
     * yields NULL and the divisor falls back to 1.
     */
    private const PIECE_COST = StockSnapshotService::PIECE_COST;

    /**
     * The reorder point for a row: the location's own tuning when set, else the
     * product default. inventory.reorder_level is NOT NULL DEFAULT 0, so 0 means
     * "not configured here" and must fall through — hence NULLIF, not COALESCE
     * alone.
     */
    private const REORDER_POINT = StockSnapshotService::REORDER_POINT;

    /** Trailing window, in days, for consumption and turnover. */
    private const MOVEMENT_WINDOW_DAYS = 30;

    /** Recommendations older than this are recomputed on demand. */
    private const RECOMMENDATION_MAX_AGE_HOURS = 24;
    private const STOCKOUT_HORIZON_DAYS        = 30;

    private function inventoryQuery(?int $locationId, ?int $categoryId): Builder
    {
        return $this->stock->query($locationId, $categoryId);
    }

    /** Same stock buckets as StockSnapshotService, used by movement queries. */
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
        // Derive both summaries and supporting records from this one snapshot.
        $records = $this->stock->rows($locationId, $categoryId);
        $count = fn ($status) => $records->where('status', $status)->count();
        $critical = $count('critical');
        $low = $count('low');
        $optimal = $count('optimal');
        $overstock = $count('overstock');
        $total = $records->count();
        $pct = fn ($n) => $total > 0 ? (int) round($n / $total * 100) : 0;
        $attention = $records->filter(fn ($r) => in_array($r['status'], ['critical', 'low'], true));

        return [
            'statsData' => [
                'totalStockValue' => round((float) $records->sum('stock_value'), 2),
                'totalUnits' => (int) $records->sum('quantity_on_hand'),
                'lowStockItems' => $low, 'criticalItems' => $critical,
            ],
            'stockDistribution' => [
                'optimal' => ['count' => $optimal, 'percentage' => $pct($optimal)],
                'lowStock' => ['count' => $low, 'percentage' => $pct($low)],
                'critical' => ['count' => $critical, 'percentage' => $pct($critical)],
                'overstock' => ['count' => $overstock, 'percentage' => $pct($overstock)],
            ],
            'reorderCompliance' => [
                'rate' => $pct($optimal + $overstock),
                'belowReorderPoint' => $critical + $low,
                'aboveSafetyStock' => $optimal + $overstock,
            ],
            'criticalItems' => $attention->sortBy('quantity_on_hand')->values()->map(fn ($r) => [
                'name' => $r['product'], 'location' => $r['location'],
                'current' => $r['quantity_on_hand'], 'max' => $r['threshold'],
            ])->all(),
            'overstockItems' => $records->where('status', 'overstock')->sortByDesc('quantity_on_hand')->values()->map(fn ($r) => [
                'name' => $r['product'], 'location' => $r['location'],
                'current' => $r['quantity_on_hand'], 'optimal' => $r['max_stock_level'],
            ])->all(),
            'stockRecords' => $records->all(),
        ];
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
                         SUM(i.quantity_on_hand) AS on_hand,
                         MAX(' . self::REORDER_POINT . ') AS reorder_point')
            ->groupBy('i.product_id', 'p.product_name', 'c.category_name')
            ->get();

        // Charts and tables must use the same active products and category.
        $allowed = array_fill_keys($stockRows->pluck('product_id')->map(fn ($id) => (int) $id)->all(), true);
        foreach ($demand as $locId => $products) {
            $demand[$locId] = array_intersect_key($products, $allowed);
        }

        $items = $stockRows->map(function ($r) use ($consumed) {
            $productId = (int) $r->product_id;
            $onHand    = (int) $r->on_hand;
            $units     = (float) ($consumed[$productId] ?? 0);

            return [
                'productId'    => $productId,
                'name'         => $r->product_name,
                'category'     => $r->category_name,
                'onHand'       => $onHand,
                'reorderPoint' => (int) $r->reorder_point,
                'unitsOut'     => $units,
                // Turnover: units consumed per unit of stock held. With no stock
                // but real consumption the ratio is undefined, so fall back to the
                // null when stock is zero so consumption is never mislabeled as a ratio.
                'turnover'     => $onHand > 0 ? round($units / $onHand, 1) : ($units > 0 ? null : 0.0),
            ];
        })->values();

        // The UI renders consumption on a percentage bar, so scale against the
        // busiest product in scope. An absolute unit count has no natural ceiling.
        $peak = (float) max(1, $items->max('unitsOut') ?? 1);

        $sorted    = $items->sortByDesc('turnover')->values();
        $fast      = $sorted->where('turnover', '>=', 6)->values();
        $slow      = $sorted->where('turnover', '>', 0)->where('turnover', '<=', 3)->values();
        $moved     = $items->where('unitsOut', '>', 0)->filter(fn ($i) => $i['turnover'] !== null);
        $avgTurn   = $moved->count() > 0 ? (int) round($moved->avg('turnover')) : 0;

        $optimalCount = (int) $this->inventoryQuery($locationId, $categoryId)
            ->whereRaw($this->bucketExpression() . " = 'optimal'")
            ->count();

        $toRateShape = static fn ($i) => [
            'name'     => $i['name'],
            'category' => $i['category'],
            'rate'     => (int) round($i['turnover']),
        ];

        return [
            'movementStats' => [
                'avgTurnoverRate'  => $avgTurn,
                'fastMovingCount'  => $fast->count(),
                'slowMovingCount'  => $slow->count(),
                'optimalStockCount'=> $optimalCount,
            ],
            'fastMovingItems' => $fast->map($toRateShape)->all(),
            'slowMovingItems' => $slow->map($toRateShape)->all(),
            'consumptionData' => $items->sortByDesc('unitsOut')->values()
                ->map(static function ($i) use ($peak) {
                    // Status reflects stock level against the reorder point,
                    // the same definition the Stock Level tab uses — not
                    // turnover, which measures speed, not scarcity.
                    if ($i['onHand'] <= 0) {
                        $status = 'Out of Stock';
                    } elseif ($i['onHand'] <= $i['reorderPoint']) {
                        $status = 'Low Stock';
                    } else {
                        $status = 'In Stock';
                    }

                    return [
                        'name'            => $i['name'],
                        'currentStock'    => $i['onHand'],
                        'consumptionRate' => (int) round(($i['unitsOut'] / $peak) * 100),
                        'turnover'        => $i['turnover'] === null ? 'N/A' : $i['turnover'] . 'x/mo',
                        'status'          => $status,
                    ];
                })->all(),
            'weeklyMovement' => $this->weeklyBuckets($demand, $locationId),
            'movementRecords' => $items->map(function ($i) use ($demand, $locationId) {
                $productDemand = [];
                foreach ($demand as $locId => $products) {
                    $productDemand[$locId] = [$i['productId'] => $products[$i['productId']] ?? []];
                }
                $weeks = $this->weeklyBuckets($productDemand, $locationId);
                return $i + ['week1' => $weeks[0], 'week2' => $weeks[1], 'week3' => $weeks[2], 'week4' => $weeks[3]];
            })->all(),
            'movementPeriod' => ['from' => $from, 'to' => $to, 'labels' => [
                now()->subDays(29)->format('M d') . ' to ' . now()->subDays(21)->format('M d'),
                now()->subDays(20)->format('M d') . ' to ' . now()->subDays(14)->format('M d'),
                now()->subDays(13)->format('M d') . ' to ' . now()->subDays(7)->format('M d'),
                now()->subDays(6)->format('M d') . ' to ' . now()->format('M d'),
            ]],
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
        $buckets = [0.0, 0.0, 0.0, 0.0];
        $today   = now()->startOfDay();

        foreach ($demand as $locId => $products) {
            if ($locationId !== null && (int) $locId !== $locationId) {
                continue;
            }
            foreach ($products as $series) {
                foreach ($series as $day => $qty) {
                    $daysAgo = \Carbon\Carbon::parse($day)->startOfDay()->diffInDays($today);
                    if ($daysAgo < 0 || $daysAgo >= self::MOVEMENT_WINDOW_DAYS) {
                        continue;
                    }
                    // Oldest bucket includes days 21?29, so all 30 days reconcile.
                    $index = max(0, 3 - intdiv((int) $daysAgo, 7));
                    if ($index >= 0 && $index <= 3) {
                        $buckets[$index] += (float) $qty;
                    }
                }
            }
        }

        return array_map(static fn ($v) => (int) round($v), $buckets);
    }

    /**
     * Predictions tab: this reads the persisted output of
     * RestockAnalyticsService rather than recomputing any forecast itself.
     * That engine derives demand velocity, lead times and days-of-cover and
     * writes one row per (product, warehouse) to restock_recommendations —
     * warehouse-only by design, since procurement is warehouse-only.
     */
    public function predictions(?int $locationId, ?int $categoryId): array
    {
        $this->ensureRecommendationsFresh();

        $cost = self::PIECE_COST;

        $rows = DB::table('restock_recommendations as r')
            ->join('products as p', 'p.product_id', '=', 'r.product_id')
            ->join('locations as l', 'l.location_id', '=', 'r.location_id')
            ->whereNull('p.deleted_at')
            ->whereNull('l.deleted_at')
            ->leftJoin('installation_material_details as imd', 'imd.product_id', '=', 'p.product_id')
            ->when($locationId, fn (Builder $q) => $q->where('r.location_id', $locationId))
            ->when($categoryId, fn (Builder $q) => $q->where('p.category_id', $categoryId))
            ->selectRaw("r.product_id, p.product_name, l.location_name,
                         r.current_stock, r.reorder_level, r.recommended_quantity, r.created_at AS computed_at,
                         LOWER(r.priority) AS priority, r.days_of_cover, r.avg_daily_demand,
                         COALESCE(p.safety_stock, 0) AS safety_stock,
                         r.recommended_quantity * ({$cost}) AS est_cost")
            ->get();

        $rows->each(function ($row) { $row->est_cost = round((float) $row->est_cost, 2); });

        $priorityRank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

        $stockouts = $rows->filter(static fn ($r) =>
            $r->days_of_cover !== null && (float) $r->days_of_cover <= self::STOCKOUT_HORIZON_DAYS);

        $summaryFor = static fn (string $priority) => [
            'count' => $rows->where('priority', $priority)->count(),
            'units' => (int) $rows->where('priority', $priority)->sum('recommended_quantity'),
            'cost'  => round((float) $rows->where('priority', $priority)->sum('est_cost'), 2),
        ];

        return [
            'predictionsStats' => [
                'predictedStockouts' => $stockouts->count(),
                'restockNeeded'      => $rows->count(),
                'criticalPriority'   => $rows->where('priority', 'critical')->count(),
                'estRestockCost'     => round((float) $rows->sum('est_cost'), 2),
            ],
            'stockoutTimeline' => $stockouts
                ->sortBy(static fn ($r) => (float) $r->days_of_cover)
                ->values()
                ->map(static fn ($r) => [
                    'name'           => $r->product_name,
                    'branch'         => $r->location_name ?? 'Unknown Location',
                    'currentStock'   => (int) $r->current_stock,
                    'consumptionPct' => round((float) $r->avg_daily_demand, 2),
                    'reorderLevel'   => (int) $r->reorder_level,
                    'suggestedQty'   => (int) $r->recommended_quantity,
                    'daysLeft'       => (int) round((float) $r->days_of_cover),
                ])->all(),
            'restockRecommendations' => $rows
                ->sortBy(static fn ($r) => $priorityRank[$r->priority] ?? 9)
                ->values()
                ->map(static fn ($r) => [
                    'priority'     => $r->priority,
                    'name'         => $r->product_name,
                    'branch'       => $r->location_name ?? 'Unknown Location',
                    'currentStock' => (int) $r->current_stock,
                    'reorderLevel' => (int) $r->reorder_level,
                    'safetyStock'  => (int) $r->safety_stock,
                    'suggestedQty' => (int) $r->recommended_quantity,
                    'estCost'      => round((float) $r->est_cost, 2),
                    'daysOfCover'  => $r->days_of_cover === null ? null : round((float) $r->days_of_cover, 2),
                    'dailyDemand'  => round((float) $r->avg_daily_demand, 2),
                    'computedAt'   => $r->computed_at,
                    'stockoutRisk' => $r->days_of_cover !== null && (float) $r->days_of_cover <= self::STOCKOUT_HORIZON_DAYS ? 'Yes' : 'No',
                ])->all(),
            'prioritySummary' => [
                'critical' => $summaryFor('critical'),
                'high'     => $summaryFor('high'),
                'medium'   => $summaryFor('medium'),
                'low'      => $summaryFor('low'),
            ],
        ];
    }

    /**
     * Confirmed losses only: an incident counts when it produced an Adjustment,
     * i.e. when IncidentService::postDeduction() actually removed stock.
     *
     * Reasons are NOT whitelisted here on purpose. wrong_type resolves to a
     * ScanCorrection (the batch is re-typed) and zone_mismatch to a relocate —
     * in both cases the goods still exist and nothing was lost, and neither
     * produces an Adjustment to join to.
     *
     * But an Adjustment alone no longer implies a loss: customer_return posts
     * one that ADDS stock. The deduct_quantity filter is what keeps this a loss
     * query — a new reason that removes stock is picked up automatically, while
     * one that returns it is excluded without needing to be named here.
     */
    private function confirmedLossQuery(?int $locationId, ?int $categoryId): Builder
    {
        return DB::table('incident_reports as ir')
            ->join('adjustments as a', 'a.adjustment_id', '=', 'ir.adjustment_id')
            ->join('adjustment_details as ad', function ($join) {
                $join->on('ad.adjustment_id', '=', 'a.adjustment_id')
                     ->on('ad.product_id', '=', 'ir.product_id');
            })
            ->join('products as p', 'p.product_id', '=', 'ir.product_id')
            ->leftJoin('locations as l', 'l.location_id', '=', 'a.location_id')
            ->leftJoin('installation_material_details as imd', 'imd.product_id', '=', 'p.product_id')
            ->leftJoin('category_lookup as c', 'c.category_id', '=', 'p.category_id')
            ->whereNull('a.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereNotNull('ir.adjustment_id')
            ->where('ad.deduct_quantity', '>', 0)
            ->when($locationId, fn (Builder $q) => $q->where('a.location_id', $locationId))
            ->when($categoryId, fn (Builder $q) => $q->where('p.category_id', $categoryId));
    }

    public function profitLoss(?int $locationId, ?int $categoryId): array
    {
        $cost  = self::PIECE_COST;
        $value = "ad.deduct_quantity * ({$cost})";

        $records = $this->confirmedLossQuery($locationId, $categoryId)
            ->selectRaw("ir.incident_id AS id, ir.created_at AS date, p.product_name AS itemName,
                COALESCE(l.location_name, 'Unknown Location') AS branchName,
                COALESCE(c.category_name, 'Uncategorized') AS itemCategory,
                a.adjustment_type AS lossType, ad.deduct_quantity AS qty,
                {$value} AS lossValue, ir.narrative AS reason")
            ->orderByDesc('ir.created_at')->get()->map(fn ($r) => [
                'id' => (int) $r->id, 'date' => $r->date, 'itemName' => $r->itemName,
                'branchName' => $r->branchName, 'itemCategory' => $r->itemCategory,
                'lossType' => $r->lossType, 'qty' => (int) $r->qty,
                'lossValue' => round((float) $r->lossValue, 2), 'reason' => $r->reason,
                'status' => 'approved',
            ]);
        $byLocation = $records->groupBy('branchName')->map(fn ($group, $name) => [
            'name' => $name, 'amount' => round((float) $group->sum('lossValue'), 2),
        ])->sortByDesc('amount')->values()->all();
        $byCategory = $records->groupBy('itemCategory')->map(fn ($group, $name) => (object) [
            'name' => $name, 'amount' => round((float) $group->sum('lossValue'), 2),
        ])->sortByDesc('amount')->values();
        $maxCategory = (float) ($byCategory->max('amount') ?: 1);

        $pendingCount = (int) DB::table('incident_reports as ir')
            ->join('products as p', 'p.product_id', '=', 'ir.product_id')
            ->whereNull('p.deleted_at')
            ->when($categoryId, fn ($q) => $q->where('p.category_id', $categoryId))
            ->where('ir.status_id', \App\Models\IncidentReport::statusId('Open'))
            ->whereIn('ir.reason', [
                \App\Models\IncidentReport::REASON_DEFECTIVE,
                \App\Models\IncidentReport::REASON_MISSING,
            ])
            ->when($locationId, fn (Builder $q) => $q->where('ir.location_id', $locationId))
            ->count();



        return [
            'profitLossStats' => [
                'totalProfitLoss' => round((float) $records->sum('lossValue'), 2),
                'stolenUnits'     => (int) $records->where('lossType', 'loss')->sum('qty'),
                'damagedUnits'    => (int) $records->where('lossType', 'damage')->sum('qty'),
                'pendingReports'  => $pendingCount,
            ],
            'lossByBranch'   => $byLocation,
            'lossByCategory' => $byCategory->map(static fn ($r) => [
                'name'       => $r->name,
                'amount'     => round((float) $r->amount, 2),
                'percentage' => (int) round(((float) $r->amount / $maxCategory) * 100),
            ])->all(),
            'recentLossIncidents' => $records->map(fn ($r) => array_merge($r, ['lossType' => $r['lossType'] === 'damage' ? 'damaged' : 'missing']))->all(),
            'lossRecords' => $records->all(),
        ];
    }

    /**
     * Per-location performance. $locationId narrows the report to a single
     * location — the controller passes the caller's enforced location for
     * location-scoped roles, so a branch manager sees only their own branch
     * here instead of every branch's figures side by side.
     */
    public function locationPerformance(?int $locationId, ?int $categoryId): array
    {
        $snapshot = $this->stock->rows($locationId, $categoryId);
        $rows = $snapshot->groupBy('locationId')->map(function ($stock, $id) {
            $attention = $stock->filter(fn ($r) => in_array($r['status'], ['critical', 'low'], true));
            return (object) [
                'location_id' => $id, 'location_name' => $stock->first()['location'],
                'items' => $stock->count(), 'total_value' => $stock->sum('stock_value'),
                'low_stock_count' => $attention->count(), 'critical_count' => $stock->where('status', 'critical')->count(),
                'restock_needed' => $attention->count(),
                'restock_cost' => $attention->sum(fn ($r) => max(0, ($r['max_stock_level'] ?? $r['threshold']) - $r['quantity_on_hand']) * $r['cost_price']),
            ];
        })->values();

        $to   = now()->toDateString();
        $from = now()->subDays(self::MOVEMENT_WINDOW_DAYS - 1)->toDateString();

        $demand = $this->restock->dailyDemandByLocation($from, $to);

        // Use the same stock snapshot as the location summaries.
        $onHandByLocation = [];
        foreach ($snapshot as $record) {
            $locId = $record['locationId'];
            $productId = $record['productId'];
            $onHandByLocation[$locId][$productId] =
                ($onHandByLocation[$locId][$productId] ?? 0) + $record['quantity_on_hand'];
        }

        $performance = $rows->map(function ($r) use ($demand, $onHandByLocation) {
            $items      = (int) $r->items;
            $lowStock   = (int) $r->low_stock_count;
            $efficiency = $items > 0 ? (int) round((($items - $lowStock) / $items) * 100) : 0;
            $totalValue = (float) $r->total_value;
            $locId      = (int) $r->location_id;

            // Turnover here MUST mean the same thing it means on the Movement
            // tab — consumed ÷ on-hand, per product, then averaged — not a raw
            // "units moved per active SKU" count. The two tabs render this under
            // the identical "{n}x/mo" label, so a different definition here
            // would silently disagree with Movement for the same location.
            $productDemand = $demand[$locId] ?? [];
            $onHand        = $onHandByLocation[$locId] ?? [];
            $ratios        = [];
            foreach ($onHand as $productId => $qty) {
                $consumed = array_sum($productDemand[$productId] ?? []);
                if ($consumed > 0) {
                    if ($qty > 0) {
                        $ratios[] = round($consumed / $qty, 1);
                    }
                }
            }
            $avgTurnover = count($ratios) > 0 ? (int) round(array_sum($ratios) / count($ratios)) : 0;

            return [
                'id'                   => $locId,
                'name'                 => $r->location_name ?? 'Unknown Location',
                'items'                => $items,
                'totalValue'           => round($totalValue, 2),
                // lowStockCount and restockNeeded are the same population
                // (qty <= reorder point) surfaced on two different cards — that
                // duplication is intentional and matches the original page, not
                // a bug to consolidate.
                'lowStockCount'        => $lowStock,
                'criticalPriority'     => (int) $r->critical_count,
                'restockNeeded'        => (int) $r->restock_needed,
                'restockCost'          => round((float) $r->restock_cost, 2),
                'avgTurnover'          => $avgTurnover,
                'efficiencyScore'      => $efficiency,
                'performance'          => $efficiency >= 80 ? 'Excellent' : 'Needs Improvement',
                'stockValueFormatted'  => match (true) {
                    $totalValue >= 1_000_000 => '₱' . number_format($totalValue / 1_000_000, 1) . 'M',
                    $totalValue >= 1_000     => '₱' . number_format($totalValue / 1_000) . 'K',
                    default                  => '₱' . number_format($totalValue),
                },
            ];
        })
        // PHP compares arrays element-wise, so this ranks correctly even when
        // totalValue is negative (bad-data state) — a string-formatted sort key
        // would invert negative comparisons.
        ->sort(static fn ($a, $b) =>
            [$b['efficiencyScore'], $b['totalValue']] <=> [$a['efficiencyScore'], $a['totalValue']])
        ->values();

        return [
            'branchPerformanceData' => $performance->all(),
            'branchRestockingNeeds' => $performance->map(static fn ($b) => [
                'name'             => $b['name'],
                'restockNeeded'    => $b['restockNeeded'],
                'criticalPriority' => $b['criticalPriority'],
                'restockCost'      => $b['restockCost'],
            ])->all(),
        ];
    }

    /**
     * Dropdown contents. Locations are NOT restricted to branch_office: the old
     * page filtered on that type, and since this deployment has none, the
     * dropdown rendered empty and every filtered view collapsed to zero.
     *
     * Also NOT filtered on is_active: locationPerformance() groups whatever
     * inventory exists, including at a deactivated location. Hiding inactive
     * locations here would let the performance tab show a location the
     * dropdown can't select — so the two are kept reconcilable on purpose.
     */
    /**
     * Options for the dashboard's filter controls. $locationId narrows the
     * location list to the caller's own location for location-scoped roles.
     */
    public function filters(?int $locationId = null): array
    {
        return [
            'locations' => DB::table('locations')
                ->whereNull('deleted_at')
                ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
                ->orderBy('location_name')
                ->get(['location_id', 'location_name', 'location_type'])
                ->map(static fn ($l) => [
                    'id'   => (int) $l->location_id,
                    'name' => $l->location_name,
                    'type' => $l->location_type,
                ])->all(),
            'categories' => DB::table('category_lookup')
                ->orderBy('category_name')
                ->get(['category_id', 'category_name'])
                ->map(static fn ($c) => [
                    'id'   => (int) $c->category_id,
                    'name' => $c->category_name,
                ])->all(),
        ];
    }

    /** Guards against a burst of page loads all triggering a recompute. */
    private const REFRESH_GUARD_KEY = 'analytics-dashboard-restock-refresh';

    /**
     * The restock engine is driven by a daily scheduled command. On a dev box
     * that scheduler is not running, which would leave this tab permanently
     * blank and indistinguishable from a bug. If the table is empty or stale,
     * compute it now — but never at the cost of failing the request.
     */
    private function ensureRecommendationsFresh(): void
    {
        // NOT run_at: analyze() truncates that to startOfDay(), so it cannot
        // express age — it would read as stale every night between midnight and
        // the 06:00 scheduled run. created_at is the real insert time.
        $newest = DB::table('restock_recommendations')->max('created_at');

        if ($newest !== null
            && \Carbon\Carbon::parse($newest)->gt(now()->subHours(self::RECOMMENDATION_MAX_AGE_HOURS))) {
            return;
        }

        // Cache::add is atomic: only the first caller in the window wins, so a
        // burst of loads triggers exactly one recompute instead of all of them
        // queueing on the engine's 15s lock.
        if (! Cache::add(self::REFRESH_GUARD_KEY, true, now()->addMinutes(15))) {
            return;
        }

        try {
            $this->restock->run();
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Another process is already recomputing. Serving slightly stale (or
            // empty) recommendations beats failing the whole dashboard.
            report($e);
        }
    }
}
