<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Location;
use App\Models\Product;
use App\Models\Inventory;
use App\Models\Sale;
use App\Models\Transfer;
use App\Models\Receiving;
use App\Models\ActivityLog;
use App\Models\AuditTrail;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PendingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user          = $request->user();
        $readsAll = $user === null || $user->readsAllLocations();
        $locId    = $readsAll ? null : $user->scopedLocationId();

        return response()->json([
            'stats'                    => $this->getStats($user),
            'low_stock_items'          => $this->getLowStockItems($locId),
            'inventory_by_category'    => $this->buildInventoryByCategory(null, null, null, $locId),
            'location_distribution'    => $this->getLocationDistribution($locId),
            'stock_status_by_location' => $this->buildStockByLocation(null, null, null, $locId),
            'warehouse_capacity'       => $this->getWarehouseCapacity($locId),
            'stock_trends'             => $this->buildStockTrends(now()->subWeeks(3)->toDateString(), now()->toDateString(), null, null, null, $locId),
            'transaction_trends'       => $this->buildTransactionTrends(now()->subDays(6)->toDateString(), now()->toDateString(), $locId),
            'recent_activity'          => $this->getRecentActivity($locId),
        ]);
    }

    public function stats(Request $request)
    {
        return response()->json(['success' => true, 'data' => $this->getStats($request->user())]);
    }

    /**
     * One-shot payload for the admin header badges.
     * Combines alert badge/unread/pending counts with awaiting-transfer counts so the
     * layout needs a single request instead of three.
     */
    public function layoutSummary(Request $request)
    {
        $alertJson = app(\App\Http\Controllers\Api\AlertSettingsController::class)
            ->index($request);
        $alert = json_decode($alertJson->getContent(), true)['data'] ?? [];

        $awaiting = $this->awaitingTransferCounts();

        return response()->json([
            'success' => true,
            'data' => [
                'badge_count'          => $alert['badge_count']   ?? 0,
                'unread_count'         => $alert['unread_count']  ?? 0,
                'total_pending'        => $alert['total_pending'] ?? 0,
                'pending_pr_count'     => $alert['pending_pr_count'] ?? 0,
                'warehouse_unread_ids' => $alert['warehouse_unread_ids'] ?? [],
                'awaiting_fulfillment' => $awaiting['fulfillment'],
                'awaiting_receipt'     => $awaiting['receipt'],
            ],
        ]);
    }

    public function lowStock()
    {
        return response()->json(['success' => true, 'data' => $this->getLowStockItems()]);
    }

    public function recentSales()
    {
        $sales = Sale::with(['customer', 'createdBy', 'status'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();
        return response()->json(['success' => true, 'data' => $sales]);
    }

    public function recentMovements()
    {
        $recent = DB::table('audit_trail as at')
            ->leftJoin('users as u', 'u.user_id', '=', 'at.user_id')
            ->where(function ($q) {
                $q->whereIn('at.action', [
                        'barcode_received',
                        'stock_transferred',
                        'issuance_completed',
                        'barcode_correction',
                    ])
                  ->orWhere(function ($q2) {
                      $q2->whereIn('at.action', ['create', 'update'])
                         ->whereIn('at.table_name', [
                             'purchase_requests',
                             'purchase_orders',
                             'receivings',
                             'transfers',
                             'issuances',
                             'scan_corrections',
                             'adjustments',
                             'products',
                         ]);
                  });
            })
            ->orderBy('at.created_at', 'desc')
            ->limit(20)
            ->select([
                'at.audit_id',
                'at.action',
                'at.table_name',
                'at.record_id',
                'at.new_values',
                'at.old_values',
                'at.created_at',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as user_full_name"),
                'u.username',
            ])
            ->get()
            ->map(function ($row) {
                $new = is_string($row->new_values) ? (json_decode($row->new_values, true) ?? []) : (array) ($row->new_values ?? []);
                $old = is_string($row->old_values) ? (json_decode($row->old_values, true) ?? []) : (array) ($row->old_values ?? []);

                return [
                    'audit_id'      => $row->audit_id,
                    'action'        => $row->action,
                    'table_name'    => $row->table_name,
                    'record_id'     => $row->record_id,
                    'user_name'     => trim($row->user_full_name) ?: ($row->username ?? 'System'),
                    'description'   => $this->buildActivityDescription($row->action, $row->table_name ?? '', $new, $old),
                    'created_at'    => $row->created_at,
                    'movement_type' => $this->classifyMovement($row->action, $row->table_name ?? ''),
                ];
            });

        return response()->json(['success' => true, 'data' => $recent]);
    }

    private function buildActivityDescription(string $action, string $table, array $new, array $old): string
    {
        if ($action === 'barcode_received') {
            $product = $new['product_name'] ?? 'item';
            $qty     = $new['quantity_received'] ?? 1;
            $po      = !empty($new['po_id']) ? " (PO #{$new['po_id']})" : '';
            return "Received {$qty} x {$product}{$po}";
        }
        if ($action === 'stock_transferred') {
            $from = $new['from_location_id'] ?? '?';
            $to   = $new['to_location_id']   ?? '?';
            $ref  = $new['transfer_number']   ?? '';
            return "Transfer {$ref}: {$from} → {$to}";
        }
        if ($action === 'issuance_completed') {
            $dr       = $new['dr_number']    ?? '';
            $customer = $new['customer']     ?? '';
            $total    = isset($new['total_amount']) ? '₱' . number_format((float) $new['total_amount'], 2) : '';
            return "Issuance {$dr} to {$customer}" . ($total ? " — {$total}" : '');
        }
        if ($action === 'barcode_correction') {
            $name = $new['product_name'] ?? $old['product_name'] ?? '?';
            return "Barcode corrected on {$name}";
        }

        $labels = [
            'purchase_requests' => 'Purchase Request',
            'purchase_orders'   => 'Purchase Order',
            'receivings'        => 'Receiving Session',
            'transfers'         => 'Transfer',
            'issuances'         => 'Issuance',
            'scan_corrections'  => 'Scan Correction',
            'adjustments'       => 'Adjustment',
            'products'          => 'Product',
        ];
        $label = $labels[$table] ?? ucfirst(str_replace('_', ' ', $table));

        if ($action === 'create') {
            $ref = $new['pr_number'] ?? $new['po_number'] ?? $new['receiving_number']
                ?? $new['product_name'] ?? $new['transfer_number'] ?? '';
            return "Created {$label}" . ($ref ? ": {$ref}" : '');
        }
        if ($action === 'update') {
            if (isset($new['status_id'], $old['status_id'])) {
                return "{$label} status: {$old['status_id']} → {$new['status_id']}";
            }
            $ref = $new['pr_number'] ?? $new['po_number'] ?? '';
            return "Updated {$label}" . ($ref ? ": {$ref}" : '');
        }

        return ucfirst(str_replace('_', ' ', $action)) . " on {$label}";
    }

    private function classifyMovement(string $action, string $table): string
    {
        if ($action === 'barcode_received')   return 'stock_in';
        if ($action === 'stock_transferred')  return 'transfer';
        if ($action === 'issuance_completed') return 'stock_out';
        if ($action === 'barcode_correction') return 'correction';
        if ($table  === 'purchase_orders')    return 'purchase_order';
        if ($table  === 'purchase_requests')  return 'purchase_request';
        if ($table  === 'products')           return 'product';
        if ($table  === 'adjustments')        return 'adjustment';
        return 'general';
    }

    public function stockMovementTrends(Request $request)
    {
        $user = $request->user();
        $scope = function ($query, $column) use ($user) {
            if (! $user->readsAllLocations()) {
                $location = $user->scopedLocationId();
                $location === null ? $query->whereRaw('1 = 0') : $query->where($column, $location);
            }
            return $query;
        };
        $today = now()->toDateString();
        $start = now()->subDays(29)->toDateString();
        $previousStart = now()->subDays(59)->toDateString();
        $previousEnd = now()->subDays(30)->toDateString();
        $inDate = 'COALESCE(DATE(r.receiving_date), DATE(r.created_at))';
        $outDate = 'COALESCE(DATE(i.issuance_date), DATE(i.created_at))';
        $in = $scope(DB::table('receiving_details as rd')
            ->join('receivings as r', 'r.receiving_id', '=', 'rd.receiving_id'), 'r.location_id');
        $out = $scope(DB::table('issuance_details as d')
            ->join('issuances as i', 'i.issuance_id', '=', 'd.issuance_id')
            ->where('d.quantity_issued', '>', 0), 'i.location_id');
        $outQuantity = 'COALESCE(d.quantity_in_pieces, d.quantity_issued, 0)';
        $periodIn = (clone $in)->whereRaw("$inDate BETWEEN ? AND ?", [$start, $today]);
        $periodOut = (clone $out)->whereRaw("$outDate BETWEEN ? AND ?", [$start, $today]);
        $inDays = (clone $periodIn)->selectRaw("$inDate as day, SUM(rd.quantity_amount) as total")
            ->groupBy('day')->pluck('total', 'day');
        $outDays = (clone $periodOut)->selectRaw("$outDate as day, SUM($outQuantity) as total")
            ->groupBy('day')->pluck('total', 'day');
        $labels = $inbound = $outbound = [];
        for ($day = 6; $day >= 0; $day--) {
            $date = now()->subDays($day);
            $labels[] = $date->format('M j');
            $inbound[] = (int) ($inDays[$date->toDateString()] ?? 0);
            $outbound[] = (int) ($outDays[$date->toDateString()] ?? 0);
        }
        $received = (clone $periodIn)->selectRaw('rd.product_id, SUM(rd.quantity_amount) as quantity')
            ->groupBy('rd.product_id')->pluck('quantity', 'rd.product_id');
        $issued = (clone $periodOut)->selectRaw("d.product_id, SUM($outQuantity) as quantity")
            ->groupBy('d.product_id')->pluck('quantity', 'd.product_id');
        $rows = Inventory::forUser($user)->whereHas('product')->with('product.category')->get();
        $products = $rows->groupBy('product_id')->map(function ($stock, $id) use ($received, $issued) {
            $item = $stock->first();
            return [
                'inventory_id' => $item->inventory_id,
                'product' => $item->product,
                'quantity_on_hand' => (int) $stock->sum('quantity_on_hand'),
                'inbound' => (int) ($received[$id] ?? 0),
                'outbound' => (int) ($issued[$id] ?? 0),
            ];
        })->values();
        return response()->json(['success' => true, 'data' => [
            'labels' => $labels, 'inbound' => $inbound, 'outbound' => $outbound,
            'total_in' => (int) $periodIn->sum('rd.quantity_amount'),
            'total_out' => (int) $periodOut->sum(DB::raw($outQuantity)),
            'prev_total_in' => (int) (clone $in)->whereRaw("$inDate BETWEEN ? AND ?", [$previousStart, $previousEnd])->sum('rd.quantity_amount'),
            'prev_total_out' => (int) (clone $out)->whereRaw("$outDate BETWEEN ? AND ?", [$previousStart, $previousEnd])->sum(DB::raw($outQuantity)),
            'current_stock' => (int) $rows->sum('quantity_on_hand'),
            'products' => $products,
        ]]);
    }

    public function pendingApprovals()
    {
        $includeHistory = request()->boolean('include_history');
        $user = request()->user();
        $scoped = $user !== null && !$user->readsAllLocations();
        $locId = $user?->scopedLocationId();

        // Keep the default dashboard queue pending-only; alerts also show decisions.
        $pendingPos = PurchaseOrder::whereIn('status_id', $includeHistory ? [7, 8, 9, 10, 11, 12] : [7])
            ->when($scoped, fn ($q) => $q->where('location_id', $locId))
            ->with(['supplier', 'location', 'createdBy'])
            ->orderBy('created_at', 'desc')
            ->when(!$includeHistory, fn ($q) => $q->limit(10))
            ->get()
            ->map(function ($po) {
                $createdBy = $po->createdBy
                    ? trim($po->createdBy->first_name . ' ' . $po->createdBy->last_name)
                    : '—';
                return [
                    'id'                => $po->po_id,
                    'po_number'         => $po->po_number,
                    'supplier'          => $po->supplier?->supplier_name ?? '—',
                    'location_name'     => $po->location?->location_name ?? '—',
                    'total_amount'      => (float) ($po->total_amount ?? 0),
                    'created_by'        => $createdBy ?: '—',
                    'order_date'        => $po->order_date?->toDateString(),
                    'expected_delivery' => $po->expected_delivery_date?->toDateString(),
                    'status_id'         => $po->status_id,
                    'approval_status'   => (int) $po->status_id === 7 ? 'pending' : ((int) $po->status_id === 9 ? 'rejected' : 'approved'),
                    'created_at'        => $po->created_at?->toIso8601String(),
                ];
            });

        // status_id 13 = PR Pending
        $pendingPrs = PurchaseRequest::whereIn('status_id', $includeHistory ? [13, 14, 15, 16] : [13])
            ->when($scoped, fn ($q) => $q->where('location_id', $locId))
            ->with(['location', 'requester'])
            ->orderBy('created_at', 'desc')
            ->when(!$includeHistory, fn ($q) => $q->limit(10))
            ->get()
            ->map(function ($pr) {
                $requestedBy = $pr->requester
                    ? trim($pr->requester->first_name . ' ' . $pr->requester->last_name)
                    : '—';
                return [
                    'id'              => $pr->pr_id,
                    'approval_status' => (int) $pr->status_id === 13 ? 'pending' : ((int) $pr->status_id === 15 ? 'rejected' : 'approved'),
                    'pr_number'       => $pr->pr_number,
                    'location_name'   => $pr->location?->location_name ?? '—',
                    'requested_by'    => $requestedBy ?: '—',
                    'estimated_total' => (float) ($pr->estimated_total ?? 0),
                    'request_date'    => $pr->request_date?->toDateString(),
                    'needed_by_date'  => $pr->needed_by_date?->toDateString(),
                    'created_at'      => $pr->created_at?->toIso8601String(),
                ];
            });

        $pendingProducts = PendingProduct::whereIn('status', $includeHistory ? ['pending', 'approved', 'rejected'] : ['pending'])
            ->when($scoped, fn ($q) => $q->where('opening_location_id', $locId))
            ->orderBy('created_at', 'desc')
            ->when(!$includeHistory, fn ($q) => $q->limit(10))
            ->get()
            ->map(fn($p) => [
                'id'                => $p->pending_product_id,
                'approval_status'   => $p->status,
                'generated_name'    => $p->generated_name,
                'registration_kind' => $p->registration_kind,
                'category_id'       => $p->category_id,
                'created_at'        => $p->created_at?->toIso8601String(),
            ]);

        $totalPending = $pendingPos->where('approval_status', 'pending')->count() + $pendingPrs->where('approval_status', 'pending')->count() + $pendingProducts->where('approval_status', 'pending')->count();
        $awaiting     = $this->awaitingTransferCounts();

        return response()->json([
            'success' => true,
            'data'    => [
                'pending_pos'                => $pendingPos->values(),
                'pending_prs'                => $pendingPrs->values(),
                'pending_products'           => $pendingProducts->values(),
                'pending_po_count'           => $pendingPos->where('approval_status', 'pending')->count(),
                'pending_pr_count'           => $pendingPrs->where('approval_status', 'pending')->count(),
                'pending_product_count'      => $pendingProducts->where('approval_status', 'pending')->count(),
                'total_pending'              => $totalPending,
                'awaiting_fulfillment_count' => $awaiting['fulfillment'],
                'awaiting_receipt_count'     => $awaiting['receipt'],
            ],
        ]);
    }

    private function awaitingTransferCounts(): array
    {
        $user          = request()->user();
        $readsAll = $user === null || $user->readsAllLocations();
        $locId    = $readsAll ? null : $user->scopedLocationId();

        if (!$readsAll && $locId === null) {
            return ['fulfillment' => 0, 'receipt' => 0];
        }

        $fulfillment = Transfer::query()
            ->whereHas('status', fn ($q) => $q->where('status_name', 'Approved')->where('status_category', 'transfer'))
            ->when(!$readsAll, fn ($q) => $q->where('from_location_id', $locId))
            ->count();

        $receipt = Transfer::query()
            ->whereHas('status', fn ($q) => $q->where('status_name', 'In Transit')->where('status_category', 'transfer'))
            ->when(!$readsAll, fn ($q) => $q->where('to_location_id', $locId))
            ->count();

        return ['fulfillment' => $fulfillment, 'receipt' => $receipt];
    }

    private function getStats(?User $user = null): array
    {
        $readsAll = $user === null || $user->readsAllLocations();
        $locId    = $readsAll ? null : $user->scopedLocationId();
        $noData   = !$readsAll && $locId === null;

        // ── pending_transfers: Transfer has from_location_id / to_location_id (no trait)
        // Scoped users see pending transfers that originate from OR arrive at their location.
        $pendingTransfersQuery = Transfer::whereHas('status', fn($q) => $q->where('status_name', 'Pending'));
        if ($noData) {
            $pendingTransfers = 0;
        } elseif (!$readsAll) {
            $pendingTransfers = (clone $pendingTransfersQuery)
                ->where(fn($q) => $q->where('from_location_id', $locId)->orWhere('to_location_id', $locId))
                ->count();
        } else {
            $pendingTransfers = $pendingTransfersQuery->count();
        }

        // ── pending_pos: PurchaseOrder has location_id (no trait)
        $pendingPOsQuery = PurchaseOrder::where('status_id', 7);
        if ($noData) {
            $pendingPOs = 0;
        } elseif (!$readsAll) {
            $pendingPOs = (clone $pendingPOsQuery)->where('location_id', $locId)->count();
        } else {
            $pendingPOs = $pendingPOsQuery->count();
        }

        $pendingPRs = $noData ? 0 : PurchaseRequest::where('status_id', 13)
            ->when(!$readsAll, fn ($q) => $q->where('location_id', $locId))
            ->count();

        // ── pending_registrations: PendingProduct has no location_id — always global
        $pendingRegistrations = PendingProduct::where('status', 'pending')->count();

        $activeLocations = Location::count();
        $totalProducts   = Product::count();
        $awaiting        = $this->awaitingTransferCounts();

        // ── total_sales: Sale has ScopesToUserLocation trait
        $totalSales = $noData ? 0 : ($user !== null ? Sale::forUser($user)->count() : Sale::count());

        // ── low_stock: correlated subquery over inventory — scope to user's location when scoped
        if ($noData) {
            $lowStock = 0;
        } elseif (!$readsAll) {
            $lowStock = (int) DB::table('products')
                ->where('recommended_stocks', '>', 0)
                ->whereRaw(
                    '(SELECT COALESCE(SUM(quantity_on_hand),0) FROM inventory WHERE inventory.product_id = products.product_id AND inventory.location_id = ?) < products.recommended_stocks',
                    [$locId]
                )
                ->count();
        } else {
            $lowStock = (int) DB::table('products')
                ->where('recommended_stocks', '>', 0)
                ->whereRaw('(SELECT COALESCE(SUM(quantity_on_hand),0) FROM inventory WHERE inventory.product_id = products.product_id) < products.recommended_stocks')
                ->count();
        }

        return [
            'total_users'           => User::count(),
            'active_locations'      => $activeLocations,
            'active_branches'       => $activeLocations,
            'total_products'        => $totalProducts,
            'total_items'           => $totalProducts,
            'low_stock'             => $lowStock,
            'total_sales'           => $totalSales,
            'pending_transfers'     => $pendingTransfers,
            'pending_pos'           => $pendingPOs,
            'pending_prs'           => $pendingPRs,
            'pending_registrations' => $pendingRegistrations,
            'pending_actions'       => $pendingTransfers + $pendingPOs + $pendingRegistrations,
            'awaiting_fulfillment'  => $awaiting['fulfillment'],
            'awaiting_receipt'      => $awaiting['receipt'],
        ];
    }

    private function getLowStockItems(?int $locId = null): array
    {
        $sub = $locId === null
            ? '(SELECT COALESCE(SUM(quantity_on_hand),0) FROM inventory WHERE inventory.product_id = products.product_id)'
            : '(SELECT COALESCE(SUM(quantity_on_hand),0) FROM inventory WHERE inventory.product_id = products.product_id AND inventory.location_id = ' . (int) $locId . ')';

        return Product::with(['category', 'applianceDetail.brand'])
            ->selectRaw("products.*, {$sub} AS total_qty")
            ->where('recommended_stocks', '>', 0)
            ->whereRaw("{$sub} < recommended_stocks")
            ->orderByRaw("(recommended_stocks - ({$sub})) DESC")
            ->limit(20)
            ->get()
            ->map(fn($p) => [
                'product_id'         => $p->product_id,
                'product_name'       => $p->product_name,
                'category'           => $p->category?->category_name,
                'brand'              => $p->applianceDetail?->brand?->brand_name,
                'quantity'           => (int) ($p->total_qty ?? 0),
                'recommended_stocks' => (int) $p->recommended_stocks,
                'deficiency'         => max(0, (int) $p->recommended_stocks - (int) ($p->total_qty ?? 0)),
            ])
            ->values()
            ->all();
    }

    private function getInventoryByCategory(): array
    {
        return $this->buildInventoryByCategory(null, null, null);
    }

    private function getLocationDistribution(?int $locId = null): array
    {
        $locations = Location::withCount('inventories')
            ->when($locId !== null, fn ($q) => $q->where('location_id', $locId))
            ->get();

        if ($locations->isEmpty()) {
            return [
                'labels' => ['Davao City', 'Cagayan de Oro City', 'Pagadian City', 'Zamboanga City'],
                'values' => [20, 14, 14, 14],
            ];
        }

        $values = $locations->pluck('inventories_count')->toArray();
        if (array_sum($values) === 0) {
            $values = array_fill(0, count($values), 1);
        }

        return [
            'labels' => $locations->pluck('location_name')->toArray(),
            'values' => $values,
        ];
    }

    private function getStockStatusByLocation(): array
    {
        return $this->buildStockByLocation(null, null, null);
    }

    private function getWarehouseCapacity(?int $locId = null): array
    {
        $locations = Location::when($locId !== null, fn ($q) => $q->where('location_id', $locId))->get();

        if ($locations->isEmpty()) {
            return ['labels' => [], 'capacity' => [], 'occupancy' => []];
        }

        $capacity  = [];
        $occupancy = [];

        foreach ($locations as $location) {
            $capacity[]  = (int) ($location->capacity ?? 0);
            $occupancy[] = (int) Inventory::where('location_id', $location->location_id)->sum('quantity_on_hand');
        }

        return [
            'labels'    => $locations->pluck('location_name')->toArray(),
            'capacity'  => $capacity,
            'occupancy' => $occupancy,
        ];
    }

    private function getStockTrends(): array
    {
        return $this->buildStockTrends(now()->subWeeks(3)->toDateString(), now()->toDateString(), null, null, null);
    }

    private function getSalesTrends(): array
    {
        return $this->buildTransactionTrends(now()->subDays(6)->toDateString(), now()->toDateString());
    }

    // ─── Filtered chart endpoint ──────────────────────────────────────────────

    public function filteredCharts(Request $request)
    {
        $dateFrom  = $request->input('date_from', now()->subDays(6)->toDateString());
        $dateTo    = $request->input('date_to', now()->toDateString());
        $catId     = $request->filled('category_id') ? (int) $request->input('category_id') : null;
        $brandId   = $request->filled('brand_id')    ? (int) $request->input('brand_id')    : null;
        $productId = $request->filled('product_id')  ? (int) $request->input('product_id')  : null;

        // Scope the filtered charts to the user's location too, so a scoped
        // role using the date/category picker never sees other locations' data.
        $user  = $request->user();
        $locId = ($user === null || $user->readsAllLocations()) ? null : $user->scopedLocationId();

        return response()->json([
            'success' => true,
            'data'    => [
                'transaction_trends'       => $this->buildTransactionTrends($dateFrom, $dateTo, $locId),
                'stock_trends'             => $this->buildStockTrends($dateFrom, $dateTo, $catId, $brandId, $productId, $locId),
                'inventory_by_category'    => $this->buildInventoryByCategory($catId, $brandId, $productId, $locId),
                'stock_status_by_location' => $this->buildStockByLocation($catId, $brandId, $productId, $locId),
                'warehouse_capacity'       => $this->getWarehouseCapacity($locId),
            ],
        ]);
    }

    public function filterOptions()
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'categories' => DB::table('category_lookup')->orderBy('category_name')->get(['category_id', 'category_name']),
                'brands'     => DB::table('brand_lookup')->orderBy('brand_name')->get(['brand_id', 'brand_name']),
                'products'   => DB::table('products')->orderBy('product_name')->get(['product_id', 'product_name']),
            ],
        ]);
    }

    // ─── Shared chart builders (used by both index and filteredCharts) ─────────

    private function buildTransactionTrends(string $dateFrom, string $dateTo, ?int $locId = null): array
    {
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to   = Carbon::parse($dateTo)->endOfDay();
        $diff = (int) $from->diffInDays($to);

        $labels = $saleValues = $purchaseValues = [];

        if ($diff <= 31) {
            $d = $from->copy();
            while ($d->lte($to)) {
                $ds               = $d->toDateString();
                $labels[]         = $d->format('M d');
                $saleValues[]     = DB::table('issuances')
                    ->when($locId !== null, fn ($q) => $q->where('location_id', $locId))
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('issuance_details')
                        ->whereColumn('issuance_details.issuance_id', 'issuances.issuance_id')
                        ->where('quantity_issued', '>', 0))
                    ->whereDate('issuance_date', $ds)->count();
                $purchaseValues[] = PurchaseOrder::when($locId !== null, fn ($q) => $q->where('location_id', $locId))->whereDate('created_at', $ds)->count();
                $d->addDay();
            }
        } else {
            $d = $from->copy()->startOfMonth();
            while ($d->lte($to)) {
                $labels[]         = $d->format('M Y');
                $saleValues[]     = DB::table('issuances')
                    ->when($locId !== null, fn ($q) => $q->where('location_id', $locId))
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('issuance_details')
                        ->whereColumn('issuance_details.issuance_id', 'issuances.issuance_id')
                        ->where('quantity_issued', '>', 0))
                    ->whereYear('issuance_date', $d->year)->whereMonth('issuance_date', $d->month)->count();
                $purchaseValues[] = PurchaseOrder::when($locId !== null, fn ($q) => $q->where('location_id', $locId))->whereYear('created_at', $d->year)->whereMonth('created_at', $d->month)->count();
                $d->addMonth();
            }
        }

        return ['labels' => $labels, 'values' => $saleValues, 'purchase_values' => $purchaseValues];
    }

    private function buildStockTrends(string $dateFrom, string $dateTo, ?int $catId, ?int $brandId, ?int $productId, ?int $locId = null): array
    {
        $from  = Carbon::parse($dateFrom);
        $to    = Carbon::parse($dateTo);
        $diff  = (int) $from->diffInDays($to);
        $weeks = max(1, (int) ceil(($diff + 1) / 7));

        $labels = $values = [];

        for ($i = 0; $i < $weeks; $i++) {
            $weekEnd  = $from->copy()->addDays($i * 7 + 6)->min($to)->endOfDay();
            $labels[] = $weeks === 1 ? $from->format('M d') . '–' . $to->format('M d') : 'Wk ' . ($i + 1);
            $q        = DB::table('inventory as inv')->where('inv.updated_at', '<=', $weekEnd);
            $this->applyInventoryFilters($q, $catId, $brandId, $productId, 'inv');
            if ($locId !== null) $q->where('inv.location_id', $locId);
            $values[] = (int) round((float) ($q->avg('inv.quantity_on_hand') ?? 0));
        }

        $rq = DB::table('inventory as inv')->whereNotNull('inv.reorder_level');
        $this->applyInventoryFilters($rq, $catId, $brandId, $productId, 'inv');
        if ($locId !== null) $rq->where('inv.location_id', $locId);
        $avgReorder = (int) round((float) ($rq->avg('inv.reorder_level') ?? 0));

        return ['labels' => $labels, 'values' => $values, 'reorder_level' => $avgReorder];
    }

    private function buildInventoryByCategory(?int $catId, ?int $brandId, ?int $productId, ?int $locId = null): array
    {
        $q = DB::table('category_lookup')
            ->leftJoin('products', 'category_lookup.category_id', '=', 'products.category_id')
            ->leftJoin('inventory', function ($join) use ($locId) {
                $join->on('products.product_id', '=', 'inventory.product_id');
                // Keep the location filter inside the LEFT JOIN so categories with no
                // stock at this location still appear (as 0) instead of being dropped.
                if ($locId !== null) $join->where('inventory.location_id', '=', $locId);
            });

        if ($brandId) {
            $q->leftJoin('appliance_details', 'products.product_id', '=', 'appliance_details.product_id')
              ->where('appliance_details.brand_id', $brandId);
        }
        if ($catId)     $q->where('category_lookup.category_id', $catId);
        if ($productId) $q->where('products.product_id', $productId);

        $rows = $q->selectRaw('category_lookup.category_name, COALESCE(SUM(inventory.quantity_on_hand), 0) as total_qty')
            ->groupBy('category_lookup.category_id', 'category_lookup.category_name')
            ->orderByDesc('total_qty')
            ->get();

        return [
            'labels' => $rows->pluck('category_name')->toArray(),
            'values' => $rows->pluck('total_qty')->map(fn($v) => (int) $v)->toArray(),
        ];
    }

    private function buildStockByLocation(?int $catId, ?int $brandId, ?int $productId, ?int $locId = null): array
    {
        $locations = Location::when($locId !== null, fn ($q) => $q->where('location_id', $locId))->get();
        if ($locations->isEmpty()) {
            return ['labels' => [], 'inStock' => [], 'lowStock' => []];
        }

        $inStock = $lowStock = [];
        foreach ($locations as $loc) {
            $lid    = $loc->location_id;
            $threshold = 'COALESCE(NULLIF(inv.reorder_level, 0), '
                . '(SELECT recommended_stocks FROM products WHERE products.product_id = inv.product_id), 0)';
            $qIn    = DB::table('inventory as inv')->where('inv.location_id', $lid)->whereRaw("inv.quantity_on_hand > {$threshold}");
            $qLow   = DB::table('inventory as inv')->where('inv.location_id', $lid)->whereRaw("inv.quantity_on_hand <= {$threshold}");
            $this->applyInventoryFilters($qIn,  $catId, $brandId, $productId, 'inv');
            $this->applyInventoryFilters($qLow, $catId, $brandId, $productId, 'inv');
            $inStock[]  = (int) $qIn->count();
            $lowStock[] = (int) $qLow->count();
        }

        return [
            'labels'   => $locations->pluck('location_name')->toArray(),
            'inStock'  => $inStock,
            'lowStock' => $lowStock,
        ];
    }

    private function applyInventoryFilters($query, ?int $catId, ?int $brandId, ?int $productId, string $alias = 'inventory'): void
    {
        if (!$catId && !$brandId && !$productId) return;

        if ($productId) {
            $query->where("{$alias}.product_id", $productId);
            return;
        }

        $query->join('products as _p', "{$alias}.product_id", '=', '_p.product_id');
        if ($catId) $query->where('_p.category_id', $catId);
        if ($brandId) {
            $query->join('appliance_details as _ad', '_p.product_id', '=', '_ad.product_id')
                  ->where('_ad.brand_id', $brandId);
        }
    }

    private function getRecentActivity(?int $locId = null): array
    {
        $rows = [];

        try {
            foreach (Sale::with(['customer', 'location', 'soldBy'])->when($locId !== null, fn ($q) => $q->where('location_id', $locId))->orderBy('created_at', 'desc')->limit(5)->get() as $s) {
                $rows[] = [
                    'type'        => 'sale',
                    'label'       => 'Sale ' . ($s->invoice_number ?? ('#' . $s->sales_id)),
                    'description' => ($s->customer?->customer_name ?? 'Walk-in') . ' — ₱' . number_format((float) $s->total_amount, 2),
                    'location'    => $s->location?->location_name,
                    'user'        => $s->soldBy ? trim($s->soldBy->first_name . ' ' . $s->soldBy->last_name) ?: $s->soldBy->username : null,
                    'status'      => 'Completed',
                    'timestamp'   => $s->created_at?->toIso8601String(),
                ];
            }
        } catch (\Throwable $_) {}

        try {
            foreach (Transfer::with(['fromLocation', 'toLocation', 'requestedBy', 'status'])->when($locId !== null, fn ($q) => $q->where(fn ($w) => $w->where('from_location_id', $locId)->orWhere('to_location_id', $locId)))->orderBy('created_at', 'desc')->limit(5)->get() as $t) {
                $rows[] = [
                    'type'        => 'transfer',
                    'label'       => 'Transfer ' . ($t->transfer_number ?? ('#' . $t->transfer_id)),
                    'description' => ($t->fromLocation?->location_name ?? '?') . ' → ' . ($t->toLocation?->location_name ?? '?') . ' (' . ($t->total_quantity_transferred ?? 0) . ' units)',
                    'location'    => null,
                    'user'        => $t->requestedBy ? trim($t->requestedBy->first_name . ' ' . $t->requestedBy->last_name) ?: $t->requestedBy->username : null,
                    'status'      => $t->status?->status_name ?? 'Pending',
                    'timestamp'   => $t->created_at?->toIso8601String(),
                ];
            }
        } catch (\Throwable $_) {}

        try {
            foreach (Receiving::with(['supplier', 'location', 'receivedBy'])->when($locId !== null, fn ($q) => $q->where('location_id', $locId))->orderBy('created_at', 'desc')->limit(5)->get() as $r) {
                $rows[] = [
                    'type'        => 'receiving',
                    'label'       => 'Receiving ' . ($r->receiving_number ?? ('#' . $r->receiving_id)),
                    'description' => ($r->supplier?->supplier_name ?? 'Unknown supplier') . ' — ' . ($r->total_quantity_received ?? 0) . ' units received',
                    'location'    => $r->location?->location_name,
                    'user'        => $r->receivedBy ? trim($r->receivedBy->first_name . ' ' . $r->receivedBy->last_name) ?: $r->receivedBy->username : null,
                    'status'      => 'Received',
                    'timestamp'   => $r->created_at?->toIso8601String(),
                ];
            }
        } catch (\Throwable $_) {}

        try {
            foreach (ActivityLog::with(['user', 'status'])->orderBy('created_at', 'desc')->limit(5)->get() as $a) {
                $rows[] = [
                    'type'        => 'activity',
                    'label'       => $a->module ?? 'System',
                    'description' => $a->description ?? $a->activity_type,
                    'location'    => null,
                    'user'        => $a->user ? trim($a->user->first_name . ' ' . $a->user->last_name) ?: $a->user->username : null,
                    'status'      => $a->status?->status_name ?? ucfirst($a->activity_type ?? 'Info'),
                    'timestamp'   => $a->created_at?->toIso8601String(),
                ];
            }
        } catch (\Throwable $_) {}

        try {
            $tableFriendly = [
                'sales' => 'Sale', 'sale_details' => 'Sale Item',
                'products' => 'Product', 'purchase_orders' => 'Purchase Order',
                'purchase_order_details' => 'PO Item', 'receivings' => 'Receiving',
                'receiving_details' => 'Receiving Item', 'issuances' => 'Issuance',
                'issuance_details' => 'Issuance Item', 'adjustments' => 'Adjustment',
                'adjustment_details' => 'Adjustment Item', 'transfers' => 'Transfer',
                'transfer_details' => 'Transfer Item', 'purchase_returns' => 'Purchase Return',
                'delivery_receipts' => 'Delivery Receipt', 'locations' => 'Location',
                'item_serials' => 'Item Serial', 'users' => 'User',
            ];
            $skipFields = ['created_at', 'updated_at', 'deleted_at'];

            foreach (AuditTrail::with('user')->orderBy('created_at', 'desc')->limit(5)->get() as $a) {
                $action      = $a->action ?? 'change';
                $recordId    = $a->record_id;
                $tableName   = $a->table_name;
                $moduleLabel = $tableName ? ($tableFriendly[$tableName] ?? ucwords(str_replace('_', ' ', $tableName))) : null;
                $userName    = trim(($a->user?->first_name ?? '') . ' ' . ($a->user?->last_name ?? ''))
                    ?: ($a->user?->username ?? 'System');

                if ($action === 'login') {
                    $entryLabel  = 'Login';
                    $description = 'Signed in to the system';
                    $status      = 'Login';
                } elseif ($action === 'logout') {
                    $entryLabel  = 'Logout';
                    $description = 'Signed out of the system';
                    $status      = 'Logout';
                } elseif ($action === 'update' && !empty($a->new_values)) {
                    $changed     = array_keys(array_diff_key((array) $a->new_values, array_flip($skipFields)));
                    $fieldList   = implode(', ', array_map(
                        fn($f) => ucwords(str_replace(['_id', '_'], ['', ' '], $f)),
                        array_slice($changed, 0, 3)
                    ));
                    $more        = count($changed) > 3 ? ' +' . (count($changed) - 3) . ' more' : '';
                    $entryLabel  = 'Updated ' . ($moduleLabel ?? 'Record') . ($recordId ? ' #' . $recordId : '');
                    $description = 'Changed: ' . $fieldList . $more;
                    $status      = 'Updated';
                } elseif ($action === 'create') {
                    $vals  = array_filter((array) ($a->new_values ?? []), fn($v) => $v !== null && $v !== '');
                    $vals  = array_diff_key($vals, array_flip($skipFields));
                    $parts = [];
                    foreach (array_slice($vals, 0, 2, true) as $k => $v) {
                        $lbl     = ucwords(str_replace(['_id', '_'], ['', ' '], $k));
                        $display = strlen((string) $v) > 20 ? substr((string) $v, 0, 20) . '…' : $v;
                        $parts[] = $lbl . ': ' . $display;
                    }
                    $mod         = $moduleLabel ?? 'Record';
                    $entryLabel  = 'Created ' . $mod . ($recordId ? ' #' . $recordId : '');
                    $description = $parts ? implode(', ', $parts) : $mod . ' added';
                    $status      = 'Created';
                } elseif ($action === 'delete') {
                    $mod         = $moduleLabel ?? 'Record';
                    $entryLabel  = 'Deleted ' . $mod . ($recordId ? ' #' . $recordId : '');
                    $description = $mod . ($recordId ? ' #' . $recordId : '') . ' removed from the system';
                    $status      = 'Deleted';
                } else {
                    $entryLabel  = ucfirst($action) . ($moduleLabel ? ' — ' . $moduleLabel : '');
                    $description = ucfirst($action) . ' performed';
                    $status      = ucfirst($action);
                }

                $rows[] = [
                    'type'        => 'audit',
                    'label'       => $entryLabel,
                    'description' => $description,
                    'location'    => null,
                    'user'        => $userName,
                    'status'      => $status,
                    'timestamp'   => $a->created_at?->toIso8601String(),
                ];
            }
        } catch (\Throwable $_) {}

        $rows = array_filter($rows, fn($r) => $r['timestamp'] !== null);
        usort($rows, fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']));

        return array_values(array_slice($rows, 0, 10));
    }
}
