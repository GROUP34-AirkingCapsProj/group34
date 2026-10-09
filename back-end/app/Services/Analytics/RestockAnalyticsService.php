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

        $demandByLoc = $this->dailyDemandByLocation($from90->toDateString(), $asOf->toDateString());
        $stockByWh   = $this->stockByWarehouse();
        $stockTotal  = $this->stockOnHandTotal();
        $openByWh    = $this->openPoByWarehouse();
        $leads       = $this->leadTimes();
        $received90  = $this->receivedQuantities($from90->toDateString());

        // Org-wide daily demand per product (sum across locations) — feeds the
        // product-level Stock Parameter suggestions, which tune global columns.
        $demandTotal = [];
        foreach ($demandByLoc as $prods) {
            foreach ($prods as $pid => $series) {
                foreach ($series as $day => $qty) {
                    $demandTotal[$pid][$day] = ($demandTotal[$pid][$day] ?? 0) + $qty;
                }
            }
        }

        $warehouseIds = Location::query()
            ->where('location_type', 'warehouse')
            ->orderBy('location_id')
            ->pluck('location_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $products = Product::query()
            ->where(fn ($q) => $q->whereNull('status_id')
                ->orWhere('status_id', '!=', self::PRODUCT_INACTIVE_STATUS))
            ->get();

        $recommendations = [];
        $suggestions     = [];

        foreach ($products as $p) {
            $pid = (int) $p->product_id;

            $lead = $leads['product'][$pid]
                ?? $leads['supplier'][$leads['productSupplier'][$pid] ?? -1]
                ?? self::DEFAULT_LEAD_TIME;
            $lead = max(self::LEAD_TIME_MIN, min(self::LEAD_TIME_MAX, $lead));

            $currentRecommended = (int) $p->recommended_stocks;
            $currentSafety      = $p->safety_stock !== null ? (int) $p->safety_stock : null;
            $currentMax         = $p->max_stock_level !== null ? (int) $p->max_stock_level : null;

            // ---- Restock recommendation, PER WAREHOUSE (per-warehouse demand) ----
            foreach ($warehouseIds as $whId) {
                // Only warehouses that actually carry the product (have an
                // inventory row) are eligible; a warehouse that has never
                // stocked it is not a restock target.
                if (!isset($stockByWh[$whId][$pid])) {
                    continue;
                }

                // Demand velocity/trend/sigma from THIS warehouse's own outflow,
                // so a busy warehouse gets higher targets than a quiet one. The
                // global product values still act as a floor. Lead time is
                // per-product (supplier-based), not per-warehouse.
                $m       = $this->demandMetrics($demandByLoc[$whId][$pid] ?? [], $from30, $lead);
                $v30     = $m['v30'];
                $trend   = $m['trend'];
                $total90 = $m['total90'];

                $effectiveSafety  = max($m['computedSafety'], (int) ($currentSafety ?? 0));
                $effectiveReorder = max($m['computedReorder'], $currentRecommended);
                $effectiveTarget  = max($m['computedTarget'], (int) ($currentMax ?? 0), $currentRecommended);

                $onHand = (int) $stockByWh[$whId][$pid];
                $open   = (int) ($openByWh[$whId][$pid] ?? 0);
                $cover  = $v30 > 0 ? round($onHand / $v30, 1) : null;

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
                if (!$triggered || $qty <= 0) {
                    continue;
                }

                $priority = 'MEDIUM';
                if ($onHand <= 0 || ($cover !== null && $cover < $lead / 2)) {
                    $priority = 'CRITICAL';
                } elseif (($cover !== null && $cover < $lead)
                    || ($effectiveSafety > 0 && $onHand < $effectiveSafety)) {
                    $priority = 'HIGH';
                }

                $recommendations[] = [
                    'product_id'           => $pid,
                    'location_id'          => $whId,
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

            // ---- Parameter suggestion (PRODUCT-LEVEL, org-wide demand) --------
            // These tune the product's GLOBAL recommended/safety/max columns, so
            // they use org-wide demand (summed across every location), not one
            // warehouse's slice.
            $mt          = $this->demandMetrics($demandTotal[$pid] ?? [], $from30, $lead);
            $onHandTotal = (int) ($stockTotal[$pid] ?? 0);
            $coverTotal  = $mt['v30'] > 0 ? round($onHandTotal / $mt['v30'], 1) : null;

            $hasParams = $currentRecommended > 0
                || ($currentSafety ?? 0) > 0
                || ($currentMax ?? 0) > 0;

            if ($mt['total90'] <= 0 && !$hasParams) {
                continue; // nothing learned, nothing to shrink
            }

            if ($mt['total90'] <= 0) {
                $sReasons  = ['NO_MOVEMENT'];
                $suggested = [
                    'recommended' => (int) floor($currentRecommended / 2),
                    'safety'      => (int) floor(($currentSafety ?? 0) / 2),
                    'max'         => (int) floor(($currentMax ?? 0) / 2),
                ];
            } else {
                $sReasons = [];
                if ($mt['trend'] !== null && $mt['trend'] >= self::TREND_FAST) {
                    $sReasons[] = 'FAST_MOVING';
                }
                if ($mt['trend'] !== null && $mt['trend'] <= self::TREND_SLOW) {
                    $sReasons[] = 'SLOW_MOVING';
                }
                if ($sReasons === []) {
                    $sReasons[] = 'DEMAND_SHIFT';
                }
                $suggested = [
                    'recommended' => $mt['computedReorder'],
                    'safety'      => $mt['computedSafety'],
                    'max'         => $mt['computedTarget'],
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
                    'summary'               => $this->summarize($mt['v30'], $mt['trend'], $coverTotal, $lead, $onHandTotal),
                ];
            }
        }

        return [
            'run_at'          => $asOf,
            'recommendations' => $recommendations,
            'suggestions'     => $suggestions,
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
                RestockRecommendation::create($rec + ['run_at' => $result['run_at']]);
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

    /**
     * Per-location daily demand (issuances + sales), attributed to the location
     * where the outflow was recorded. Rows with a NULL location land under key 0
     * and so never match a warehouse id (excluded from per-warehouse demand) but
     * are still summed into the org-wide total for suggestions.
     *
     * Public because DashboardAnalyticsService reuses it for the Movement tab —
     * the dashboard and the restock engine must agree on what "demand" means, so
     * there is exactly one definition and one query. Pure read, no side effects.
     *
     * @return array<int, array<int, array<string, float>>> locationId => productId => [Y-m-d => qty out]
     */
    public function dailyDemandByLocation(string $from, string $to): array
    {
        $byLoc = [];

        $issuances = DB::table('issuance_details as d')
            ->join('issuances as i', 'i.issuance_id', '=', 'd.issuance_id')
            ->join('products as p', 'p.product_id', '=', 'd.product_id')
            ->whereNull('i.deleted_at')
            ->whereBetween('i.issuance_date', [$from, $to])
            ->where(fn ($q) => $q->whereNull('i.status_id')
                ->orWhere('i.status_id', '!=', self::ISSUANCE_VOIDED_STATUS))
            ->groupBy('i.location_id', 'd.product_id', 'i.issuance_date')
            ->selectRaw(
                "i.location_id, d.product_id, i.issuance_date as day,
                 SUM(CASE WHEN p.product_type = 'installation_material' AND d.quantity_in_pieces > 0
                          THEN d.quantity_in_pieces ELSE d.quantity_issued END) as qty"
            )
            ->get();

        foreach ($issuances as $row) {
            $loc = (int) $row->location_id;
            $day = (string) $row->day;
            $byLoc[$loc][(int) $row->product_id][$day] =
                ($byLoc[$loc][(int) $row->product_id][$day] ?? 0) + (float) $row->qty;
        }

        $sales = DB::table('sales_details as sd')
            ->join('sales as s', 's.sales_id', '=', 'sd.sales_id')
            ->whereRaw('COALESCE(s.sale_date, s.order_date, DATE(s.created_at)) BETWEEN ? AND ?', [$from, $to])
            ->groupBy('s.location_id', 'sd.product_id', DB::raw('COALESCE(s.sale_date, s.order_date, DATE(s.created_at))'))
            ->selectRaw(
                's.location_id, sd.product_id,
                 COALESCE(s.sale_date, s.order_date, DATE(s.created_at)) as day,
                 SUM(sd.quantity) as qty'
            )
            ->get();

        foreach ($sales as $row) {
            $loc = (int) $row->location_id;
            $day = (string) $row->day;
            $byLoc[$loc][(int) $row->product_id][$day] =
                ($byLoc[$loc][(int) $row->product_id][$day] ?? 0) + (float) $row->qty;
        }

        return $byLoc;
    }

    /**
     * Derive demand velocity + computed stock thresholds from one daily-demand
     * series (a single warehouse's slice, or the org-wide total). Lead time is
     * supplied by the caller (per-product, supplier-based).
     *
     * @param array<string, float> $series [Y-m-d => qty out]
     * @return array{total90:float,total30:float,v30:float,v90:float,trend:?float,demandDays:int,sigma:float,computedSafety:int,computedReorder:int,computedTarget:int}
     */
    private function demandMetrics(array $series, string $from30, float $lead): array
    {
        $total90 = array_sum($series);
        $total30 = 0.0;
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

        $computedSafety = $demandDays >= self::SPARSE_DEMAND_DAYS
            ? (int) ceil(self::SERVICE_FACTOR * $sigma * sqrt($lead))
            : (int) ceil(0.5 * $v30 * $lead);
        $computedReorder = (int) ceil($v30 * $lead) + $computedSafety;
        $computedTarget  = (int) ceil(self::WINDOW_SHORT_DAYS * $v30) + $computedSafety;

        return compact(
            'total90', 'total30', 'v30', 'v90', 'trend', 'demandDays', 'sigma',
            'computedSafety', 'computedReorder', 'computedTarget'
        );
    }

    /** @return array<int, array<int,int>> locationId => [productId => qty], warehouses only */
    private function stockByWarehouse(): array
    {
        $rows = DB::table('inventory as inv')
            ->join('locations as l', 'l.location_id', '=', 'inv.location_id')
            ->where('l.location_type', 'warehouse')
            ->groupBy('inv.location_id', 'inv.product_id')
            ->selectRaw('inv.location_id, inv.product_id, SUM(inv.quantity_on_hand) as qty')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->location_id][(int) $r->product_id] = (int) $r->qty;
        }
        return $out;
    }

    /** @return array<int,int> productId => org-wide quantity_on_hand (all locations) */
    private function stockOnHandTotal(): array
    {
        return DB::table('inventory')
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity_on_hand) as qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($q) => (int) $q)
            ->all();
    }

    /** @return array<int, array<int,int>> locationId => [productId => open-PO qty not yet received] */
    private function openPoByWarehouse(): array
    {
        // quantity_ordered means PACKAGES for installation materials and units
        // (pieces) for appliances. Received quantities are always pieces, so
        // convert ordered -> pieces before netting.
        $ordered = DB::table('purchase_order_details as pod')
            ->join('purchase_orders as po', 'po.po_id', '=', 'pod.po_id')
            ->join('products as p', 'p.product_id', '=', 'pod.product_id')
            ->leftJoin('installation_material_details as imd', 'imd.product_id', '=', 'pod.product_id')
            ->whereIn('po.status_id', self::OPEN_PO_STATUSES)
            ->whereNull('po.deleted_at')
            ->whereNotNull('pod.location_id')
            ->groupBy('pod.location_id', 'pod.product_id')
            ->selectRaw(
                "pod.location_id, pod.product_id,
                 SUM(CASE WHEN p.product_type = 'installation_material'
                          THEN pod.quantity_ordered * GREATEST(COALESCE(imd.pieces_per_package, 1), 1)
                          ELSE pod.quantity_ordered END) as qty"
            )
            ->get();

        $received = DB::table('receiving_details as rd')
            ->join('receivings as r', 'r.receiving_id', '=', 'rd.receiving_id')
            ->join('purchase_orders as po', 'po.po_id', '=', 'r.po_id')
            ->whereIn('po.status_id', self::OPEN_PO_STATUSES)
            ->whereNull('po.deleted_at')
            ->whereNotNull('r.location_id')
            ->groupBy('r.location_id', 'rd.product_id')
            ->selectRaw('r.location_id, rd.product_id, SUM(rd.quantity_amount) as qty')
            ->get();

        $recvMap = [];
        foreach ($received as $r) {
            $recvMap[(int) $r->location_id][(int) $r->product_id] = (int) $r->qty;
        }

        // Nets received against ordered per warehouse; assumes a PO line's delivery
        // destination (pod.location_id) is where it is received (receivings.location_id).
        $out = [];
        foreach ($ordered as $o) {
            $loc = (int) $o->location_id;
            $pid = (int) $o->product_id;
            $out[$loc][$pid] = max(0, (int) $o->qty - (int) ($recvMap[$loc][$pid] ?? 0));
        }
        return $out;
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
