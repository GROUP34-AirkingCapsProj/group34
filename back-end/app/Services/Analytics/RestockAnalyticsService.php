<?php

namespace App\Services\Analytics;

use App\Models\Location;
use App\Models\Product;
use App\Models\RestockRecommendation;
use App\Models\StockParameterSuggestion;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Rule-based restock analytics (spec: docs/superpowers/specs/
 * 2026-07-06-po-recommendation-analytics-design.md).
 *
 * analyze() is a pure read: it derives demand velocity, lead times and
 * stock-parameter targets, and returns recommendation + suggestion arrays.
 * Persistence lives in run()/persist() (Task 3).
 */
class RestockAnalyticsService
{
    public const WINDOW_LONG_DAYS   = 90;
    public const WINDOW_SHORT_DAYS  = 30;
    public const DEFAULT_LEAD_TIME  = 7.0;
    public const LEAD_TIME_MIN      = 1.0;
    public const LEAD_TIME_MAX      = 30.0;
    public const SERVICE_FACTOR     = 1.65; // ~95% service level
    public const TREND_FAST         = 1.3;
    public const TREND_SLOW         = 0.7;
    public const SUGGEST_MIN_PCT    = 0.20;
    public const SPARSE_DEMAND_DAYS = 5;
    public const LEAD_TIME_SAMPLE   = 5;    // most recent POs per product

    /** status_lookup: PO statuses with deliveries still outstanding. */
    public const OPEN_PO_STATUSES = [7, 8, 10, 12];
    /** status_lookup: voided issuances are not demand. */
    public const ISSUANCE_VOIDED_STATUS = 32;
    /** status_lookup: inactive product. */
    public const PRODUCT_INACTIVE_STATUS = 4;

    public function analyze(?Carbon $asOf = null): array
    {
        $asOf   = ($asOf ?? now())->copy()->startOfDay();
        $from90 = $asOf->copy()->subDays(self::WINDOW_LONG_DAYS - 1);
        $from30 = $asOf->copy()->subDays(self::WINDOW_SHORT_DAYS - 1)->toDateString();

        $daily      = $this->dailyDemand($from90->toDateString(), $asOf->toDateString());
        $stock      = $this->stockOnHand();
        $onOrder    = $this->openPoQuantities();
        $leads      = $this->leadTimes();
        $received90 = $this->receivedQuantities($from90->toDateString());

        $warehouseId = Location::query()
            ->where('location_type', 'warehouse')
            ->orderBy('location_id')
            ->value('location_id');

        $products = Product::query()
            ->where(fn ($q) => $q->whereNull('status_id')
                ->orWhere('status_id', '!=', self::PRODUCT_INACTIVE_STATUS))
            ->get();

        $recommendations = [];
        $suggestions     = [];

        foreach ($products as $p) {
            $pid    = (int) $p->product_id;
            $series = $daily[$pid] ?? [];

            $total90 = array_sum($series);
            $total30 = 0;
            foreach ($series as $day => $qty) {
                if ($day >= $from30) {
                    $total30 += $qty;
                }
            }
            $v30        = $total30 / self::WINDOW_SHORT_DAYS;
            $v90        = $total90 / self::WINDOW_LONG_DAYS;
            $trend      = $v90 > 0 ? $v30 / $v90 : null;
            $demandDays = count(array_filter($series, fn ($q) => $q > 0));
            $sigma      = $this->dailyStdDev($series, self::WINDOW_LONG_DAYS);

            $lead = $leads['product'][$pid]
                ?? $leads['supplier'][$leads['productSupplier'][$pid] ?? -1]
                ?? self::DEFAULT_LEAD_TIME;
            $lead = max(self::LEAD_TIME_MIN, min(self::LEAD_TIME_MAX, $lead));

            $computedSafety = $demandDays >= self::SPARSE_DEMAND_DAYS
                ? (int) ceil(self::SERVICE_FACTOR * $sigma * sqrt($lead))
                : (int) ceil(0.5 * $v30 * $lead);
            $computedReorder = (int) ceil($v30 * $lead) + $computedSafety;
            $computedTarget  = (int) ceil(self::WINDOW_SHORT_DAYS * $v30) + $computedSafety;

            $currentRecommended = (int) $p->recommended_stocks;
            $currentSafety      = $p->safety_stock !== null ? (int) $p->safety_stock : null;
            $currentMax         = $p->max_stock_level !== null ? (int) $p->max_stock_level : null;

            $onHand = (int) ($stock[$pid] ?? 0);
            $open   = (int) ($onOrder[$pid] ?? 0);
            $cover  = $v30 > 0 ? round($onHand / $v30, 1) : null;

            // Current product values act as a floor so hand-tuned thresholds keep working.
            $effectiveSafety  = max($computedSafety, (int) ($currentSafety ?? 0));
            $effectiveReorder = max($computedReorder, $currentRecommended);
            $effectiveTarget  = max($computedTarget, (int) ($currentMax ?? 0), $currentRecommended);

            // ---- Restock recommendation --------------------------------
            $reasons = [];
            if ($onHand <= 0) {
                $reasons[] = 'OUT_OF_STOCK';
            }
            if ($onHand > 0 && $effectiveSafety > 0 && $onHand < $effectiveSafety) {
                $reasons[] = 'BELOW_SAFETY_STOCK';
            }
            if ($onHand > 0 && $cover !== null && $cover < $lead) {
                $reasons[] = 'STOCKOUT_RISK';
            }
            if ($effectiveReorder > 0 && $onHand <= $effectiveReorder) {
                $reasons[] = 'LOW_STOCK';
            }
            if ($trend !== null && $trend >= self::TREND_FAST) {
                $reasons[] = 'FAST_MOVING';
            }
            if (($received90[$pid] ?? 0) >= 1 && $total90 >= 3 * max(1, $onHand)) {
                $reasons[] = 'HIGH_TURNOVER';
            }

            $triggered = $onHand <= 0
                || ($effectiveSafety > 0 && $onHand < $effectiveSafety)
                || ($effectiveReorder > 0 && $onHand <= $effectiveReorder);

            $qty = $effectiveTarget - $onHand - $open;

            if ($triggered && $qty > 0) {
                $priority = 'MEDIUM';
                if ($onHand <= 0 || ($cover !== null && $cover < $lead / 2)) {
                    $priority = 'CRITICAL';
                } elseif (($cover !== null && $cover < $lead)
                    || ($effectiveSafety > 0 && $onHand < $effectiveSafety)) {
                    $priority = 'HIGH';
                }

                $recommendations[] = [
                    'product_id'           => $pid,
                    'current_stock'        => $onHand,
                    'reorder_level'        => $effectiveReorder,
                    'recommended_quantity' => $qty,
                    'reason'               => $this->summarize($v30, $trend, $cover, $lead, $onHand),
                    'priority'             => $priority,
                    'reason_codes'         => $reasons,
                    'avg_daily_demand'     => round($v30, 3),
                    'demand_trend'         => $trend !== null ? round($trend, 3) : null,
                    'days_of_cover'        => $cover,
                    'lead_time_days'       => round($lead, 1),
                    'on_order_quantity'    => $open,
                ];
            }

            // ---- Parameter suggestion -----------------------------------
            $hasParams = $currentRecommended > 0
                || ($currentSafety ?? 0) > 0
                || ($currentMax ?? 0) > 0;

            if ($total90 <= 0 && !$hasParams) {
                continue; // nothing learned, nothing to shrink
            }

            if ($total90 <= 0) {
                $sReasons  = ['NO_MOVEMENT'];
                $suggested = [
                    'recommended' => (int) floor($currentRecommended / 2),
                    'safety'      => (int) floor(($currentSafety ?? 0) / 2),
                    'max'         => (int) floor(($currentMax ?? 0) / 2),
                ];
            } else {
                $sReasons = [];
                if ($trend !== null && $trend >= self::TREND_FAST) {
                    $sReasons[] = 'FAST_MOVING';
                }
                if ($trend !== null && $trend <= self::TREND_SLOW) {
                    $sReasons[] = 'SLOW_MOVING';
                }
                if ($sReasons === []) {
                    $sReasons[] = 'DEMAND_SHIFT';
                }
                $suggested = [
                    'recommended' => $computedReorder,
                    'safety'      => $computedSafety,
                    'max'         => $computedTarget,
                ];
            }

            $drifted = $this->differs($currentRecommended, $suggested['recommended'])
                || $this->differs((int) ($currentSafety ?? 0), $suggested['safety'])
                || $this->differs((int) ($currentMax ?? 0), $suggested['max']);

            if ($drifted) {
                $suggestions[] = [
                    'product_id'            => $pid,
                    'current_recommended'   => $currentRecommended,
                    'suggested_recommended' => $suggested['recommended'],
                    'current_safety'        => $currentSafety,
                    'suggested_safety'      => $suggested['safety'],
                    'current_max'           => $currentMax,
                    'suggested_max'         => $suggested['max'],
                    'reason_codes'          => $sReasons,
                    'summary'               => $this->summarize($v30, $trend, $cover, $lead, $onHand),
                ];
            }
        }

        return [
            'run_at'                => $asOf,
            'warehouse_location_id' => $warehouseId ? (int) $warehouseId : null,
            'recommendations'       => $recommendations,
            'suggestions'           => $suggestions,
        ];
    }

    /** Compute and persist in one go. Returns the analyze() result. */
    public function run(?Carbon $asOf = null): array
    {
        // Serialize scheduled + manual (API) runs: both delete and rewrite the
        // recommendations batch, so exactly one run may persist at a time.
        return Cache::lock('analytics-restock-run', 120)->block(15, function () use ($asOf) {
            $result = $this->analyze($asOf);
            $this->persist($result);
            return $result;
        });
    }

    /**
     * Replace the recommendations batch; upsert pending suggestions.
     * Applied/dismissed suggestion rows are never touched (audit trail);
     * a dismissed suggestion is only re-raised when its values change.
     */
    public function persist(array $result): void
    {
        DB::transaction(function () use ($result) {
            DB::table('restock_recommendations')->delete();
            foreach ($result['recommendations'] as $rec) {
                RestockRecommendation::create($rec + [
                    'location_id' => $result['warehouse_location_id'],
                    'run_at'      => $result['run_at'],
                ]);
            }

            $suggestedIds = array_map(fn ($s) => $s['product_id'], $result['suggestions']);
            StockParameterSuggestion::where('status', 'pending')
                ->whereNotIn('product_id', $suggestedIds ?: [0])
                ->delete();

            foreach ($result['suggestions'] as $sug) {
                $pending = StockParameterSuggestion::where('product_id', $sug['product_id'])
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->first();
                if ($pending) {
                    $pending->update($sug + ['run_at' => $result['run_at']]);
                    continue;
                }

                $lastDismissed = StockParameterSuggestion::where('product_id', $sug['product_id'])
                    ->where('status', 'dismissed')
                    ->orderByDesc('reviewed_at')
                    ->first();
                if ($lastDismissed
                    && (int) $lastDismissed->suggested_recommended === (int) $sug['suggested_recommended']
                    && (int) ($lastDismissed->suggested_safety ?? 0) === (int) ($sug['suggested_safety'] ?? 0)
                    && (int) ($lastDismissed->suggested_max ?? 0) === (int) ($sug['suggested_max'] ?? 0)) {
                    continue;
                }

                StockParameterSuggestion::create($sug + [
                    'status' => 'pending',
                    'run_at' => $result['run_at'],
                ]);
            }
        });
    }

    // -------------------------------------------------------------------
    // Data gathering
    // -------------------------------------------------------------------

    /** @return array<int, array<string, float>> product_id => [Y-m-d => qty out] */
    private function dailyDemand(string $from, string $to): array
    {
        $daily = [];

        $issuances = DB::table('issuance_details as d')
            ->join('issuances as i', 'i.issuance_id', '=', 'd.issuance_id')
            ->join('products as p', 'p.product_id', '=', 'd.product_id')
            ->whereBetween('i.issuance_date', [$from, $to])
            ->where(fn ($q) => $q->whereNull('i.status_id')
                ->orWhere('i.status_id', '!=', self::ISSUANCE_VOIDED_STATUS))
            ->groupBy('d.product_id', 'i.issuance_date')
            ->selectRaw(
                "d.product_id, i.issuance_date as day,
                 SUM(CASE WHEN p.product_type = 'consumable' AND d.quantity_in_pieces > 0
                          THEN d.quantity_in_pieces ELSE d.quantity_issued END) as qty"
            )
            ->get();

        foreach ($issuances as $row) {
            $day = (string) $row->day;
            $daily[(int) $row->product_id][$day] =
                ($daily[(int) $row->product_id][$day] ?? 0) + (float) $row->qty;
        }

        $sales = DB::table('sales_details as sd')
            ->join('sales as s', 's.sales_id', '=', 'sd.sales_id')
            ->whereRaw('COALESCE(s.sale_date, s.order_date, DATE(s.created_at)) BETWEEN ? AND ?', [$from, $to])
            ->groupBy('sd.product_id', DB::raw('COALESCE(s.sale_date, s.order_date, DATE(s.created_at))'))
            ->selectRaw(
                'sd.product_id,
                 COALESCE(s.sale_date, s.order_date, DATE(s.created_at)) as day,
                 SUM(sd.quantity) as qty'
            )
            ->get();

        foreach ($sales as $row) {
            $day = (string) $row->day;
            $daily[(int) $row->product_id][$day] =
                ($daily[(int) $row->product_id][$day] ?? 0) + (float) $row->qty;
        }

        return $daily;
    }

    /** @return array<int, int> product_id => org-wide quantity_on_hand */
    private function stockOnHand(): array
    {
        return DB::table('inventory')
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity_on_hand) as qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($q) => (int) $q)
            ->all();
    }

    /** @return array<int, int> product_id => open-PO qty not yet received */
    private function openPoQuantities(): array
    {
        $ordered = DB::table('purchase_order_details as pod')
            ->join('purchase_orders as po', 'po.po_id', '=', 'pod.po_id')
            ->whereIn('po.status_id', self::OPEN_PO_STATUSES)
            ->whereNull('po.deleted_at')
            ->groupBy('pod.product_id')
            ->selectRaw('pod.product_id, SUM(pod.quantity_ordered) as qty')
            ->pluck('qty', 'product_id');

        $received = DB::table('receiving_details as rd')
            ->join('receivings as r', 'r.receiving_id', '=', 'rd.receiving_id')
            ->join('purchase_orders as po', 'po.po_id', '=', 'r.po_id')
            ->whereIn('po.status_id', self::OPEN_PO_STATUSES)
            ->whereNull('po.deleted_at')
            ->groupBy('rd.product_id')
            ->selectRaw('rd.product_id, SUM(rd.quantity_amount) as qty')
            ->pluck('qty', 'product_id');

        $open = [];
        foreach ($ordered as $pid => $qty) {
            $open[(int) $pid] = max(0, (int) $qty - (int) ($received[$pid] ?? 0));
        }
        return $open;
    }

    /**
     * @return array{product: array<int,float>, supplier: array<int,float>, productSupplier: array<int,int>}
     */
    private function leadTimes(): array
    {
        $rows = DB::table('purchase_order_details as pod')
            ->join('purchase_orders as po', 'po.po_id', '=', 'pod.po_id')
            ->join('receivings as r', 'r.po_id', '=', 'po.po_id')
            ->whereNotNull('po.order_date')
            ->whereNotNull('r.receiving_date')
            ->groupBy('pod.product_id', 'po.po_id', 'po.supplier_id', 'po.order_date')
            ->selectRaw('pod.product_id, po.po_id, po.supplier_id, po.order_date,
                         MIN(r.receiving_date) as first_received')
            ->orderByDesc('po.order_date')
            ->get();

        $perProduct = [];
        $perSupplier = [];
        $productSupplier = [];

        foreach ($rows as $row) {
            $days = max(0, Carbon::parse($row->order_date)
                ->diffInDays(Carbon::parse($row->first_received), false));
            $pid = (int) $row->product_id;

            if (count($perProduct[$pid] ?? []) < self::LEAD_TIME_SAMPLE) {
                $perProduct[$pid][] = $days;
            }
            if ($row->supplier_id !== null) {
                $perSupplier[(int) $row->supplier_id][] = $days;
                $productSupplier[$pid] = $productSupplier[$pid] ?? (int) $row->supplier_id;
            }
        }

        // Product→supplier association from ALL POs (not just received ones),
        // so a product with no receiving history can inherit its supplier's
        // measured average lead time.
        $associations = DB::table('purchase_order_details as pod')
            ->join('purchase_orders as po', 'po.po_id', '=', 'pod.po_id')
            ->whereNotNull('po.supplier_id')
            ->whereNull('po.deleted_at')
            ->orderByDesc('po.order_date')
            ->select('pod.product_id', 'po.supplier_id')
            ->get();

        foreach ($associations as $row) {
            $pid = (int) $row->product_id;
            $productSupplier[$pid] = $productSupplier[$pid] ?? (int) $row->supplier_id;
        }

        $avg = fn (array $set) => array_map(
            fn ($vals) => array_sum($vals) / count($vals), $set
        );

        return [
            'product'         => $avg($perProduct),
            'supplier'        => $avg($perSupplier),
            'productSupplier' => $productSupplier,
        ];
    }

    /** @return array<int, float> product_id => qty received in window */
    private function receivedQuantities(string $from): array
    {
        return DB::table('receiving_details as rd')
            ->join('receivings as r', 'r.receiving_id', '=', 'rd.receiving_id')
            ->where('r.receiving_date', '>=', $from)
            ->groupBy('rd.product_id')
            ->selectRaw('rd.product_id, SUM(rd.quantity_amount) as qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($q) => (float) $q)
            ->all();
    }

    // -------------------------------------------------------------------
    // Math helpers
    // -------------------------------------------------------------------

    /** Population std dev of the daily series over $n days (absent days = 0). */
    private function dailyStdDev(array $series, int $n): float
    {
        if ($n <= 0) {
            return 0.0;
        }
        $mean = array_sum($series) / $n;
        $ss   = 0.0;
        foreach ($series as $qty) {
            $ss += ($qty - $mean) ** 2;
        }
        $ss += ($n - count($series)) * $mean ** 2;
        return sqrt($ss / $n);
    }

    /** Spec drift gate: >20% relative difference AND at least 1 unit. */
    private function differs(int $current, int $suggested): bool
    {
        if (abs($suggested - $current) < 1) {
            return false;
        }
        if ($current === 0) {
            return $suggested >= 1;
        }
        return abs($suggested - $current) / $current > self::SUGGEST_MIN_PCT;
    }

    private function summarize(float $v30, ?float $trend, ?float $cover, float $lead, int $onHand): string
    {
        if ($v30 <= 0) {
            return $onHand <= 0
                ? 'Out of stock with no demand in the last 90 days.'
                : 'No demand in the last 90 days.';
        }

        $trendTxt = '';
        if ($trend !== null && $trend >= self::TREND_FAST) {
            $trendTxt = sprintf(' (up %d%% vs the 90-day average)', (int) round(($trend - 1) * 100));
        } elseif ($trend !== null && $trend <= self::TREND_SLOW) {
            $trendTxt = sprintf(' (down %d%% vs the 90-day average)', (int) round((1 - $trend) * 100));
        }

        return sprintf(
            'Demand is %.1f/day%s; stock covers %s day(s), delivery takes about %.0f day(s).',
            $v30,
            $trendTxt,
            $cover !== null ? number_format($cover, 1) : 'unlimited',
            $lead
        );
    }
}
