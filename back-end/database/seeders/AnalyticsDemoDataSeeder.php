<?php

namespace Database\Seeders;

use App\Models\IncidentReport;
use App\Services\IncidentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dev-only demo data for the Analytics Dashboard.
 *
 * The dashboard backend (DashboardAnalyticsService + RestockAnalyticsService)
 * is correct, but the dev DB (`airkingV5`) only carries master data — zero
 * rows in inventory/sales_details/issuance_details/incident_reports/etc. A
 * perfect backend over an empty DB still renders every tile as zero, which is
 * visually identical to the bug this dashboard was built to fix. This seeder
 * fabricates coherent operational history so the dashboard is demonstrable
 * and every tab can be eyeballed as "yes, this is real data flowing through."
 *
 * Not part of DatabaseSeeder::run() — invoke explicitly:
 *   php artisan db:seed --class=AnalyticsDemoDataSeeder
 *
 * Idempotent: every section checks whether its table already has rows before
 * writing, so re-running only logs "already seeded, skipping" and never
 * duplicates data.
 */
class AnalyticsDemoDataSeeder extends Seeder
{
    /** The three real locations. Never create more. */
    private const WAREHOUSE_A = 1; // CDO Warehouse A
    private const SHOWROOM    = 2; // CDO Main Showroom
    private const WAREHOUSE_B = 3; // CDO Warehouse B

    private const LOCATIONS   = [self::WAREHOUSE_A, self::SHOWROOM, self::WAREHOUSE_B];
    private const WAREHOUSES  = [self::WAREHOUSE_A, self::WAREHOUSE_B];

    /** 90 days matches RestockAnalyticsService::WINDOW_LONG_DAYS — anything
     *  shorter leaves demand velocity / days-of-cover degenerate. */
    private const DEMAND_WINDOW_DAYS = 90;

    private int $invoiceSeq  = 0;
    private int $drSeq       = 0;

    public function run(): void
    {
        // -----------------------------------------------------------------
        // Guard: this seeder writes fabricated business data. Never run it
        // against a production database, however it gets invoked.
        // -----------------------------------------------------------------
        if (app()->environment('production')) {
            $this->command?->error('AnalyticsDemoDataSeeder refuses to run in the production environment.');
            return;
        }

        $this->nullifyAFewMaxStockLevels();

        $products = DB::table('products')
            ->whereNull('deleted_at')
            ->orderBy('product_id')
            ->get(['product_id', 'product_type', 'unit_price', 'cost_price', 'recommended_stocks', 'max_stock_level']);

        $piecesPerPackage = DB::table('installation_material_details')
            ->pluck('pieces_per_package', 'product_id');

        $this->seedInventory($products, $piecesPerPackage);
        $this->seedDemandHistory($products, $piecesPerPackage);
        $this->seedIncidents($products);

        // -----------------------------------------------------------------
        // Recompute restock recommendations against the freshly seeded 90
        // days of demand, so the Predictions tab shows real engine output
        // instead of relying on DashboardAnalyticsService's lazy refresh.
        // -----------------------------------------------------------------
        $this->command?->info('Running analytics:restock over the seeded demand history...');
        $this->command?->call('analytics:restock');
    }

    // -----------------------------------------------------------------------
    // Section 0: exercise the NULL max_stock_level path
    // -----------------------------------------------------------------------

    /**
     * DemoProductDataSeeder previously backfilled max_stock_level on every
     * product, so the "no ceiling configured" branch of the stock-level
     * bucket CASE (see DashboardAnalyticsService::bucketExpression) is dead
     * code on this DB. Clearing a handful back to NULL exercises it. This is
     * a plain update (not an insert), so re-running is naturally idempotent
     * — nulling an already-null column is a no-op.
     */
    private function nullifyAFewMaxStockLevels(): void
    {
        $ids = DB::table('products')
            ->whereNull('deleted_at')
            ->orderBy('product_id')
            ->limit(5)
            ->pluck('product_id');

        DB::table('products')->whereIn('product_id', $ids)->update(['max_stock_level' => null]);

        $this->command?->info('Cleared max_stock_level on ' . $ids->count() . ' product(s) to exercise the no-ceiling path.');
    }

    // -----------------------------------------------------------------------
    // Section 1: inventory — every Stock Level bucket, at all 3 locations
    // -----------------------------------------------------------------------

    private function seedInventory($products, $piecesPerPackage): void
    {
        if (DB::table('inventory')->exists()) {
            $this->command?->warn('inventory already has rows — skipping inventory seeding.');
            return;
        }

        $statusInStock  = DB::table('status_lookup')->where('status_category', 'inventory')->where('status_name', 'In Stock')->value('status_id');
        $statusLow      = DB::table('status_lookup')->where('status_category', 'inventory')->where('status_name', 'Low Stock')->value('status_id');
        $statusOut      = DB::table('status_lookup')->where('status_category', 'inventory')->where('status_name', 'Out of Stock')->value('status_id');

        $now  = now();
        $rows = [];
        $idx  = 0;

        foreach ($products as $product) {
            $pid      = (int) $product->product_id;
            $piecesPP = (int) ($piecesPerPackage[$pid] ?? 1);

            foreach (self::LOCATIONS as $locSlot => $locationId) {
                // 5-way rotation across every (product, location) pair guarantees
                // every bucket appears many times over, at every location.
                $bucketSeed = ($idx + $locSlot) % 5;

                // Explicit reorder_level except on the "optimal via product
                // default" slot, which deliberately falls back to
                // products.recommended_stocks (COALESCE(NULLIF(...))) so that
                // path is exercised too, not just the explicit-override path.
                if ($bucketSeed === 2) {
                    $reorderLevel = 0;
                    $reorderPoint = (int) $product->recommended_stocks;
                } else {
                    $reorderLevel = 5 + (($pid * 7 + $locSlot) % 40); // 5..44
                    $reorderPoint = $reorderLevel;
                }

                $maxLevel = $product->max_stock_level !== null ? (int) $product->max_stock_level : null;

                switch ($bucketSeed) {
                    case 0: // critical
                        $qty = 0;
                        $statusId = $statusOut;
                        break;
                    case 1: // low: > 0 but <= reorder point
                        $qty = max(1, intdiv(max($reorderPoint, 2), 2));
                        $statusId = $statusLow;
                        break;
                    case 3: // overstock, when a ceiling exists; else fall back to optimal
                        if ($maxLevel !== null) {
                            $qty = $maxLevel + 10 + ($pid % 15);
                        } else {
                            $qty = $reorderPoint + 25 + ($pid % 20);
                        }
                        $statusId = $statusInStock;
                        break;
                    default: // 2, 4: optimal — comfortably above reorder, below any ceiling
                        $qty = $reorderPoint + 10 + ($pid % 25);
                        if ($maxLevel !== null && $qty >= $maxLevel) {
                            $qty = max($reorderPoint + 1, $maxLevel - 1);
                        }
                        $statusId = $statusInStock;
                        break;
                }

                $packages = $product->product_type === 'installation_material' && $piecesPP > 0
                    ? (int) ceil($qty / $piecesPP)
                    : $qty;

                $rows[] = [
                    'location_id'         => $locationId,
                    'product_id'          => $pid,
                    'status_id'           => $statusId,
                    'quantity_on_hand'    => $qty,
                    'packages_on_hand'    => $packages,
                    'available_quantity'  => $qty,
                    'reorder_level'       => $reorderLevel,
                    'last_updated'        => $now,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ];
            }

            $idx++;

            if (count($rows) >= 300) {
                DB::table('inventory')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('inventory')->insert($rows);
        }

        $this->command?->info('Seeded inventory: ' . DB::table('inventory')->count() . ' rows across ' . count(self::LOCATIONS) . ' locations.');
    }

    // -----------------------------------------------------------------------
    // Section 2: ~90 days of sales (all locations) + issuances (warehouses only)
    // -----------------------------------------------------------------------

    private function seedDemandHistory($products, $piecesPerPackage): void
    {
        if (DB::table('sales_details')->exists() || DB::table('issuance_details')->exists()) {
            $this->command?->warn('sales_details/issuance_details already has rows — skipping demand history seeding.');
            return;
        }

        // Deterministic per-product movement tier: fast/medium/slow/none, so
        // fast and slow movers are genuinely distinguishable on the Movement
        // tab rather than uniformly random noise.
        $tiers = [];
        $idx   = 0;
        foreach ($products as $p) {
            $mod = $idx % 10;
            $tiers[(int) $p->product_id] = match (true) {
                $mod < 2 => 'fast',   // 20%
                $mod < 5 => 'medium', // 30%
                $mod < 9 => 'slow',   // 40%
                default  => 'none',   // 10%
            };
            $idx++;
        }

        $paidStatusId      = DB::table('status_lookup')->where('status_category', 'sales')->where('status_name', 'Paid')->value('status_id');
        $issuanceCompleted = DB::table('status_lookup')->where('status_category', 'issuance')->where('status_name', 'Completed')->value('status_id');
        $customerId        = DB::table('customers')->orderBy('customer_id')->value('customer_id');
        $userIds            = DB::table('users')->pluck('user_id')->all();

        $salesQtyRange     = ['fast' => [3, 15], 'medium' => [1, 6], 'slow' => [1, 2]];
        $salesProb         = ['fast' => 0.12, 'medium' => 0.04, 'slow' => 0.01, 'none' => 0.0];
        $salesLocMult      = [self::WAREHOUSE_A => 1.3, self::SHOWROOM => 1.0, self::WAREHOUSE_B => 0.6];

        $issuanceQtyRange  = ['fast' => [5, 25], 'medium' => [2, 10], 'slow' => [1, 3]];
        $issuanceProb      = ['fast' => 0.08, 'medium' => 0.03, 'slow' => 0.005, 'none' => 0.0];
        $issuanceLocMult   = [self::WAREHOUSE_A => 1.2, self::WAREHOUSE_B => 0.7];

        $today = CarbonImmutable::now()->startOfDay();

        for ($daysAgo = self::DEMAND_WINDOW_DAYS - 1; $daysAgo >= 0; $daysAgo--) {
            $day = $today->subDays($daysAgo);

            foreach (self::LOCATIONS as $locationId) {
                $this->seedOneSalesInvoice(
                    $day, $locationId, $products, $tiers, $salesProb, $salesQtyRange,
                    $salesLocMult[$locationId], $paidStatusId, $customerId, $userIds
                );
            }

            foreach (self::WAREHOUSES as $locationId) {
                $this->seedOneIssuance(
                    $day, $locationId, $products, $tiers, $issuanceProb, $issuanceQtyRange,
                    $issuanceLocMult[$locationId], $issuanceCompleted, $customerId, $userIds, $piecesPerPackage
                );
            }
        }

        $this->command?->info(sprintf(
            'Seeded demand history: %d sales / %d sales_details, %d issuances / %d issuance_details.',
            DB::table('sales')->count(),
            DB::table('sales_details')->count(),
            DB::table('issuances')->count(),
            DB::table('issuance_details')->count()
        ));
    }

    private function seedOneSalesInvoice(
        CarbonImmutable $day,
        int $locationId,
        $products,
        array $tiers,
        array $prob,
        array $qtyRange,
        float $locMult,
        ?int $paidStatusId,
        ?int $customerId,
        array $userIds
    ): void {
        $lines = [];

        foreach ($products as $product) {
            $tier = $tiers[(int) $product->product_id];
            $p    = min(1.0, ($prob[$tier] ?? 0.0) * $locMult);
            if ($p <= 0 || (mt_rand(1, 1_000_000) / 1_000_000) > $p) {
                continue;
            }

            [$min, $max] = $qtyRange[$tier];
            $qty         = mt_rand($min, $max);
            $unitPrice   = max(1.0, (float) $product->unit_price);

            $lines[] = [
                'product_id' => (int) $product->product_id,
                'quantity'   => $qty,
                'unit_price' => $unitPrice,
                'subtotal'   => round($unitPrice * $qty, 2),
            ];
        }

        if ($lines === []) {
            return;
        }

        $total = array_sum(array_column($lines, 'subtotal'));
        $this->invoiceSeq++;

        $salesId = DB::table('sales')->insertGetId([
            'customer_id'       => $customerId,
            'location_id'       => $locationId,
            'invoice_number'    => sprintf('SIM-%s-L%d-%05d', $day->format('Ymd'), $locationId, $this->invoiceSeq),
            'order_date'        => $day->toDateString(),
            'sale_date'         => $day->toDateString(),
            'total_amount'      => $total,
            'payment_method'    => 'cash',
            'amount_paid'       => $total,
            'balance_due'       => 0,
            'payment_status_id' => $paidStatusId,
            'sold_by'           => $userIds[array_rand($userIds)],
            'created_at'        => $day,
            'updated_at'        => $day,
        ]);

        $now = now();
        foreach ($lines as &$line) {
            $line['sales_id']   = $salesId;
            $line['created_at'] = $day;
            $line['updated_at'] = $day;
            unset($line);
        }

        DB::table('sales_details')->insert($lines);
    }

    private function seedOneIssuance(
        CarbonImmutable $day,
        int $locationId,
        $products,
        array $tiers,
        array $prob,
        array $qtyRange,
        float $locMult,
        ?int $completedStatusId,
        ?int $customerId,
        array $userIds,
        $piecesPerPackage
    ): void {
        $lines = [];

        foreach ($products as $product) {
            $tier = $tiers[(int) $product->product_id];
            $p    = min(1.0, ($prob[$tier] ?? 0.0) * $locMult);
            if ($p <= 0 || (mt_rand(1, 1_000_000) / 1_000_000) > $p) {
                continue;
            }

            [$min, $max] = $qtyRange[$tier];
            $qty         = mt_rand($min, $max);
            $unitPrice   = max(1.0, (float) $product->unit_price);
            $isMaterial  = $product->product_type === 'installation_material';

            $lines[] = [
                'product_id'            => (int) $product->product_id,
                'quantity_issued'       => $qty,
                'quantity_requested'    => $qty,
                'quantity_short'        => 0,
                'quantity_in_pieces'    => $isMaterial ? $qty : 0,
                'unit_price'            => $unitPrice,
                'unit_price_per_piece'  => $unitPrice,
                'subtotal'              => round($unitPrice * $qty, 2),
                'condition_issued'      => 'Good',
            ];
        }

        if ($lines === []) {
            return;
        }

        $total = array_sum(array_column($lines, 'subtotal'));
        $this->drSeq++;

        $issuanceId = DB::table('issuances')->insertGetId([
            'location_id'   => $locationId,
            'customer_id'   => $customerId,
            'dr_number'     => sprintf('DRSIM-%s-L%d-%05d', $day->format('Ymd'), $locationId, $this->drSeq),
            'issuance_date' => $day->toDateString(),
            'issuance_type' => 'Operations',
            'selection_mode'=> 'manual',
            'subtotal'      => $total,
            'discount_amount' => 0,
            'total_amount'  => $total,
            'purpose'       => 'Simulated project/operations demand',
            'issued_by'     => $userIds[array_rand($userIds)],
            'approved_by'   => $userIds[array_rand($userIds)],
            'status_id'     => $completedStatusId,
            'created_at'    => $day,
            'updated_at'    => $day,
        ]);

        foreach ($lines as &$line) {
            $line['issuance_id'] = $issuanceId;
            $line['created_at']  = $day;
            $line['updated_at']  = $day;
            unset($line);
        }

        DB::table('issuance_details')->insert($lines);
    }

    // -----------------------------------------------------------------------
    // Section 3: incidents — go through IncidentService so the incident ->
    // adjustment/correction chain is authentic, not hand-inserted rows.
    // -----------------------------------------------------------------------

    private function seedIncidents($products): void
    {
        if (DB::table('incident_reports')->exists()) {
            $this->command?->warn('incident_reports already has rows — skipping incident seeding.');
            return;
        }

        // 8 distinct low-numbered products, used one-per-incident so each
        // incident's batch/inventory math is independent and easy to verify.
        $ids = $products->take(8)->pluck('product_id')->map(fn ($id) => (int) $id)->values();
        [$pDamaged, $pNotFound, $pWrongType, $pZone, $pDismissed, $pOpen, $pUnusedA, $pCorrectedTarget] = $ids->all();

        $reporterId = DB::table('users')->where('username', 'WarehouseMan')->value('user_id')
            ?? DB::table('users')->orderBy('user_id')->value('user_id');
        $reviewerId = DB::table('users')->where('username', 'Branch Manager')->value('user_id')
            ?? DB::table('users')->orderBy('user_id')->skip(1)->value('user_id');
        $reporter = (int) $reporterId;
        $reviewer = (int) $reviewerId;

        $now = now();

        // Give each demo product a generous, known inventory position and a
        // matching batch at Warehouse A, so the incident's write-off/relocate/
        // correction math is never clamped by an accidentally-low quantity —
        // the point of this section is to demonstrate the loss rule exactly,
        // so the numbers must land exactly where the incident says they will.
        $batchFor = [];
        foreach ([$pDamaged, $pNotFound, $pWrongType, $pZone, $pDismissed, $pOpen] as $pid) {
            DB::table('inventory')->updateOrInsert(
                ['location_id' => self::WAREHOUSE_A, 'product_id' => $pid],
                [
                    'status_id'          => DB::table('status_lookup')->where('status_category', 'inventory')->where('status_name', 'In Stock')->value('status_id'),
                    'quantity_on_hand'   => 200,
                    'packages_on_hand'   => 200,
                    'available_quantity' => 200,
                    'reorder_level'      => 20,
                    'last_updated'       => $now,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]
            );

            $batchId = DB::table('inventory_batches')->insertGetId([
                'product_id'         => $pid,
                'location_id'        => self::WAREHOUSE_A,
                'receiving_id'       => null,
                'quantity_received'  => 200,
                'quantity_remaining' => 200,
                'received_at'        => $now->copy()->subDays(20),
                'zone_label'         => 'A1',
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            $batchFor[$pid] = $batchId;
        }

        $service = app(IncidentService::class);

        // 1) Confirmed damaged -> real Adjustment (type: damage) -> IS a loss.
        $damaged = $service->file([
            'source_type' => 'issuance',
            'source_id'   => 1,
            'location_id' => self::WAREHOUSE_A,
            'product_id'  => $pDamaged,
            'batch_id'    => $batchFor[$pDamaged],
            'reason'      => IncidentReport::REASON_DAMAGED,
            'quantity'    => 5,
            'narrative'   => 'Unit found with cracked casing during warehouse pick.',
        ], $reporter);
        $service->resolve($damaged, 'confirm', ['resolution_note' => 'Confirmed damaged on inspection.'], $reviewer);

        // 2) Confirmed not_found -> real Adjustment (type: loss) -> IS a loss.
        $notFound = $service->file([
            'source_type' => 'issuance',
            'source_id'   => 1,
            'location_id' => self::WAREHOUSE_A,
            'product_id'  => $pNotFound,
            'batch_id'    => $batchFor[$pNotFound],
            'reason'      => IncidentReport::REASON_NOT_FOUND,
            'quantity'    => 7,
            'narrative'   => 'Expected stock missing from bin during cycle count.',
        ], $reporter);
        $service->resolve($notFound, 'confirm', ['resolution_note' => 'Confirmed missing after re-count.'], $reviewer);

        // 3) Confirmed wrong_type -> ScanCorrection, batch re-typed -> NOT a loss.
        $wrongType = $service->file([
            'source_type' => 'issuance',
            'source_id'   => 1,
            'location_id' => self::WAREHOUSE_A,
            'product_id'  => $pWrongType,
            'batch_id'    => $batchFor[$pWrongType],
            'reason'      => IncidentReport::REASON_WRONG_TYPE,
            'quantity'    => 10,
            'narrative'   => 'Barcode scanned as one SKU but shelf physically holds another.',
        ], $reporter);
        $service->resolve($wrongType, 'confirm', [
            'corrected_product_id' => $pCorrectedTarget,
            'resolution_note'      => 'Re-typed batch to the product actually on the shelf.',
        ], $reviewer);

        // 4) Confirmed zone_mismatch -> batch relocated -> NOT a loss.
        $zoneMismatch = $service->file([
            'source_type'   => 'issuance',
            'source_id'     => 1,
            'location_id'   => self::WAREHOUSE_A,
            'product_id'    => $pZone,
            'batch_id'      => $batchFor[$pZone],
            'reason'        => IncidentReport::REASON_ZONE_MISMATCH,
            'quantity'      => 4,
            'expected_zone' => 'A1',
            'actual_zone'   => 'B3',
            'narrative'     => 'Stock physically found in zone B3, not the expected A1.',
        ], $reporter);
        $service->resolve($zoneMismatch, 'confirm', ['resolution_note' => 'Zone label corrected.'], $reviewer);

        // 5) Dismissed -> nothing posted -> NOT a loss, stock returns to FIFO.
        $dismissed = $service->file([
            'source_type' => 'issuance',
            'source_id'   => 1,
            'location_id' => self::WAREHOUSE_A,
            'product_id'  => $pDismissed,
            'batch_id'    => $batchFor[$pDismissed],
            'reason'      => IncidentReport::REASON_DAMAGED,
            'quantity'    => 3,
            'narrative'   => 'Reported as damaged; on review the item was fine.',
        ], $reporter);
        $service->resolve($dismissed, 'dismiss', ['resolution_note' => 'False alarm — item inspected and undamaged.'], $reviewer);

        // 6) Left open -> populates the Pending Reports count on Profit/Loss.
        $service->file([
            'source_type' => 'issuance',
            'source_id'   => 1,
            'location_id' => self::WAREHOUSE_A,
            'product_id'  => $pOpen,
            'batch_id'    => $batchFor[$pOpen],
            'reason'      => IncidentReport::REASON_NOT_FOUND,
            'quantity'    => 2,
            'narrative'   => 'Awaiting review — stock not found during last count.',
        ], $reporter);

        $this->command?->info('Seeded 6 incidents (damaged, not_found, wrong_type, zone_mismatch, dismissed, open) via IncidentService.');
    }
}
