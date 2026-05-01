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
use App\Models\PendingProduct;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        return response()->json([
            'stats'                    => $this->getStats(),
            'low_stock_items'          => $this->getLowStockItems(),
            'inventory_by_category'    => $this->getInventoryByCategory(),
            'location_distribution'    => $this->getLocationDistribution(),
            'stock_status_by_location' => $this->getStockStatusByLocation(),
            'warehouse_capacity'       => $this->getWarehouseCapacity(),
            'stock_trends'             => $this->getStockTrends(),
            'transaction_trends'       => $this->getSalesTrends(),
            'recent_activity'          => $this->getRecentActivity(),
        ]);
    }

    public function stats()
    {
        return response()->json(['success' => true, 'data' => $this->getStats()]);
    }

    public function lowStock()
    {
        $items = Product::whereColumn('quantity', '<=', 'recommended_stocks')
            ->with(['category', 'brand'])
            ->get();
        return response()->json(['success' => true, 'data' => $items]);
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
        $transfers = Transfer::with(['fromLocation', 'toLocation', 'requestedBy', 'status'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();
        return response()->json(['success' => true, 'data' => $transfers]);
    }

    public function stockMovementTrends()
    {
        $chartDays = 7;

        $inboundRows = DB::table('receivings')
            ->selectRaw('COALESCE(DATE(receiving_date), DATE(created_at)) as day, SUM(total_quantity_received) as total')
            ->whereRaw('COALESCE(DATE(receiving_date), DATE(created_at)) >= ?', [now()->subDays($chartDays - 1)->toDateString()])
            ->groupBy('day')
            ->pluck('total', 'day')
            ->toArray();

        $outboundRows = DB::table('sales_details as sd')
            ->join('sales as s', 'sd.sales_id', '=', 's.sales_id')
            ->selectRaw('COALESCE(s.sale_date, DATE(s.created_at)) as day, SUM(sd.quantity) as total')
            ->whereRaw('COALESCE(s.sale_date, DATE(s.created_at)) >= ?', [now()->subDays($chartDays - 1)->toDateString()])
            ->groupBy('day')
            ->pluck('total', 'day')
            ->toArray();

        $labels = [];
        $inbound = [];
        $outbound = [];

        for ($i = $chartDays - 1; $i >= 0; $i--) {
            $date      = now()->subDays($i)->toDateString();
            $labels[]  = now()->subDays($i)->format('D');
            $inbound[]  = (int) ($inboundRows[$date]  ?? 0);
            $outbound[] = (int) ($outboundRows[$date] ?? 0);
        }

        // 30-day totals for the stat cards
        $totalIn = (int) DB::table('receivings')
            ->whereRaw('COALESCE(DATE(receiving_date), DATE(created_at)) >= ?', [now()->subDays(30)->toDateString()])
            ->sum('total_quantity_received');

        $totalOut = (int) DB::table('sales_details as sd')
            ->join('sales as s', 'sd.sales_id', '=', 's.sales_id')
            ->whereRaw('COALESCE(s.sale_date, DATE(s.created_at)) >= ?', [now()->subDays(30)->toDateString()])
            ->sum('sd.quantity');

        // Previous 30-day totals for period-over-period badges
        $prevTotalIn = (int) DB::table('receivings')
            ->whereRaw('COALESCE(DATE(receiving_date), DATE(created_at)) BETWEEN ? AND ?', [
                now()->subDays(60)->toDateString(),
                now()->subDays(31)->toDateString(),
            ])
            ->sum('total_quantity_received');

        $prevTotalOut = (int) DB::table('sales_details as sd')
            ->join('sales as s', 'sd.sales_id', '=', 's.sales_id')
            ->whereRaw('COALESCE(s.sale_date, DATE(s.created_at)) BETWEEN ? AND ?', [
                now()->subDays(60)->toDateString(),
                now()->subDays(31)->toDateString(),
            ])
            ->sum('sd.quantity');

        return response()->json([
            'success' => true,
            'data' => [
                'labels'         => $labels,
                'inbound'        => $inbound,
                'outbound'       => $outbound,
                'total_in'       => $totalIn,
                'total_out'      => $totalOut,
                'prev_total_in'  => $prevTotalIn,
                'prev_total_out' => $prevTotalOut,
            ],
        ]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    public function pendingApprovals()
    {
        $pendingPOs = PurchaseOrder::whereHas('status', fn($q) => $q->where('status_name', 'Pending'))
            ->with(['supplier', 'createdBy'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($po) => [
                'id'           => $po->po_id,
                'po_number'    => $po->po_number,
                'supplier'     => $po->supplier?->supplier_name,
                'total_amount' => (float) ($po->total_amount ?? 0),
                'order_date'   => $po->order_date?->toDateString(),
                'created_by'   => trim(($po->createdBy?->first_name ?? '') . ' ' . ($po->createdBy?->last_name ?? '')) ?: 'Unknown',
                'created_at'   => $po->created_at?->toIso8601String(),
            ]);

        $pendingProducts = PendingProduct::where('status', 'pending')
            ->with('category')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($p) => [
                'id'                => $p->pending_product_id,
                'generated_name'    => $p->generated_name,
                'registration_kind' => $p->registration_kind,
                'category'          => $p->category?->category_name,
                'created_at'        => $p->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'pending_po_count'      => $pendingPOs->count(),
                'pending_product_count' => $pendingProducts->count(),
                'total_pending'         => $pendingPOs->count() + $pendingProducts->count(),
                'pending_pos'           => $pendingPOs->values(),
                'pending_products'      => $pendingProducts->values(),
            ],
        ]);
    }

    private function getStats(): array
    {
        $pendingTransfers    = Transfer::whereHas('status', fn($q) => $q->where('status_name', 'Pending'))->count();
        $pendingPOs          = PurchaseOrder::whereHas('status', fn($q) => $q->where('status_name', 'Pending'))->count();
        $pendingRegistrations = PendingProduct::where('status', 'pending')->count();
        $activeLocations     = Location::count();
        $totalProducts       = Product::count();

        return [
            'total_users'          => User::count(),
            'active_locations'     => $activeLocations,
            'active_branches'      => $activeLocations,
            'total_products'       => $totalProducts,
            'total_items'          => $totalProducts,
            'low_stock'            => Product::whereColumn('quantity', '<=', 'recommended_stocks')->count(),
            'total_sales'          => Sale::count(),
            'pending_transfers'    => $pendingTransfers,
            'pending_pos'          => $pendingPOs,
            'pending_registrations' => $pendingRegistrations,
            'pending_actions'      => $pendingTransfers + $pendingPOs + $pendingRegistrations,
        ];
    }

    private function getLowStockItems(): array
    {
        return Product::with(['category', 'brand'])
            ->whereColumn('quantity', '<=', 'recommended_stocks')
            ->orderByRaw('(recommended_stocks - quantity) DESC')
            ->limit(20)
            ->get()
            ->map(fn($p) => [
                'product_id'         => $p->product_id,
                'product_name'       => $p->product_name,
                'category'           => $p->category?->category_name,
                'brand'              => $p->brand?->brand_name,
                'quantity'           => (int) $p->quantity,
                'recommended_stocks' => (int) $p->recommended_stocks,
                'deficiency'         => max(0, (int) $p->recommended_stocks - (int) $p->quantity),
            ])
            ->values()
            ->all();
    }

    private function getInventoryByCategory(): array
    {
        $categories = Product::with('category')
            ->whereNotNull('category_id')
            ->get()
            ->groupBy(fn($p) => $p->category?->category_name ?? 'Uncategorized')
            ->map(fn($g) => $g->count());

        if ($categories->isEmpty()) {
            return [
                'labels' => ['Air Conditioning', 'Television', 'Washing Machine'],
                'values' => [3, 4, 3],
            ];
        }

        return [
            'labels' => $categories->keys()->toArray(),
            'values' => $categories->values()->toArray(),
        ];
    }

    private function getLocationDistribution(): array
    {
        $locations = Location::withCount('inventories')->get();

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
        $locations = Location::all();

        if ($locations->isEmpty()) {
            return [
                'labels'   => ['Main Warehouse', 'Branch 1'],
                'inStock'  => [3, 2],
                'lowStock' => [1, 1],
            ];
        }

        $inStock  = [];
        $lowStock = [];

        foreach ($locations as $location) {
            $inStock[]  = Inventory::where('location_id', $location->location_id)
                ->where('quantity_on_hand', '>', 5)->count();
            $lowStock[] = Inventory::where('location_id', $location->location_id)
                ->where('quantity_on_hand', '<=', 5)->count();
        }

        return [
            'labels'   => $locations->pluck('location_name')->toArray(),
            'inStock'  => $inStock,
            'lowStock' => $lowStock,
        ];
    }

    private function getWarehouseCapacity(): array
    {
        $locations = Location::all();

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
        $labels = [];
        $trends = [];

        for ($i = 3; $i >= 0; $i--) {
            $weekStart = now()->subWeeks($i)->startOfWeek();
            $weekEnd   = now()->subWeeks($i)->endOfWeek();
            $labels[]  = 'Week ' . (4 - $i);
            $trends[]  = round(Inventory::whereBetween('updated_at', [$weekStart, $weekEnd])->avg('quantity_on_hand') ?? 0);
        }

        if (empty(array_filter($trends))) {
            return ['labels' => ['Week 1', 'Week 2', 'Week 3', 'Week 4'], 'values' => [180, 165, 140, 160]];
        }

        return ['labels' => $labels, 'values' => $trends];
    }

    private function getSalesTrends(): array
    {
        $labels = [];
        $trends = [];

        for ($i = 3; $i >= 0; $i--) {
            $date     = now()->subDays($i);
            $labels[] = $date->format('M d');
            $trends[] = Sale::whereDate('created_at', $date)->count();
        }

        if (empty(array_filter($trends))) {
            return ['labels' => ['Day 1', 'Day 2', 'Day 3', 'Day 4'], 'values' => [5, 8, 3, 10]];
        }

        return ['labels' => $labels, 'values' => $trends];
    }

    private function getRecentActivity(): array
    {
        $rows = [];

        try {
            foreach (Sale::with(['customer', 'location', 'soldBy'])->orderBy('created_at', 'desc')->limit(5)->get() as $s) {
                $rows[] = [
                    'type'        => 'sale',
                    'label'       => 'Sale ' . ($s->invoice_number ?? ('#' . $s->sales_id)),
                    'description' => ($s->customer?->customer_name ?? 'Walk-in') . ' — ₱' . number_format((float) $s->total_amount, 2),
                    'location'    => $s->location?->location_name,
                    'user'        => $s->soldBy?->name,
                    'status'      => 'Completed',
                    'timestamp'   => $s->created_at?->toIso8601String(),
                ];
            }
        } catch (\Throwable $_) {}

        try {
            foreach (Transfer::with(['fromLocation', 'toLocation', 'requestedBy', 'status'])->orderBy('created_at', 'desc')->limit(5)->get() as $t) {
                $rows[] = [
                    'type'        => 'transfer',
                    'label'       => 'Transfer ' . ($t->transfer_number ?? ('#' . $t->transfer_id)),
                    'description' => ($t->fromLocation?->location_name ?? '?') . ' → ' . ($t->toLocation?->location_name ?? '?') . ' (' . ($t->total_quantity_transferred ?? 0) . ' units)',
                    'location'    => null,
                    'user'        => $t->requestedBy?->name,
                    'status'      => $t->status?->status_name ?? 'Pending',
                    'timestamp'   => $t->created_at?->toIso8601String(),
                ];
            }
        } catch (\Throwable $_) {}

        try {
            foreach (Receiving::with(['supplier', 'location', 'receivedBy'])->orderBy('created_at', 'desc')->limit(5)->get() as $r) {
                $rows[] = [
                    'type'        => 'receiving',
                    'label'       => 'Receiving ' . ($r->receiving_number ?? ('#' . $r->receiving_id)),
                    'description' => ($r->supplier?->supplier_name ?? 'Unknown supplier') . ' — ' . ($r->total_quantity_received ?? 0) . ' units received',
                    'location'    => $r->location?->location_name,
                    'user'        => $r->receivedBy?->name,
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
                    'user'        => $a->user?->name,
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
                $tableName   = $a->table_name ?? 'record';
                $moduleLabel = $tableFriendly[$tableName] ?? ucwords(str_replace('_', ' ', $tableName));
                $action      = $a->action ?? 'change';
                $recordId    = $a->record_id;
                $userName    = trim(($a->user?->first_name ?? '') . ' ' . ($a->user?->last_name ?? ''))
                    ?: ($a->user?->username ?? 'System');

                if ($action === 'update' && !empty($a->new_values)) {
                    $changed = array_keys(array_diff_key((array) $a->new_values, array_flip($skipFields)));
                    $fieldList = implode(', ', array_map(
                        fn($f) => str_replace(['_id', '_'], ['', ' '], $f),
                        array_slice($changed, 0, 3)
                    ));
                    $more = count($changed) > 3 ? ' +' . (count($changed) - 3) . ' more' : '';
                    $description = 'Changed: ' . $fieldList . $more;
                } elseif ($action === 'create' && !empty($a->new_values)) {
                    $vals  = array_filter((array) $a->new_values, fn($v) => $v !== null && $v !== '');
                    $vals  = array_diff_key($vals, array_flip($skipFields));
                    $parts = [];
                    foreach (array_slice($vals, 0, 2, true) as $k => $v) {
                        $label   = str_replace(['_id', '_'], ['', ' '], $k);
                        $display = strlen((string) $v) > 20 ? substr((string) $v, 0, 20) . '…' : $v;
                        $parts[] = $label . ': ' . $display;
                    }
                    $description = $parts ? implode(', ', $parts) : 'New record created';
                } else {
                    $description = $recordId ? 'Record #' . $recordId . ' deleted' : 'Record deleted';
                }

                $actionLabels = ['create' => 'Created', 'update' => 'Updated', 'delete' => 'Deleted'];
                $rows[] = [
                    'type'        => 'audit',
                    'label'       => ($actionLabels[$action] ?? ucfirst($action)) . ' — ' . $moduleLabel . ($recordId ? ' #' . $recordId : ''),
                    'description' => $description,
                    'location'    => null,
                    'user'        => $userName,
                    'status'      => $actionLabels[$action] ?? ucfirst($action),
                    'timestamp'   => $a->created_at?->toIso8601String(),
                ];
            }
        } catch (\Throwable $_) {}

        $rows = array_filter($rows, fn($r) => $r['timestamp'] !== null);
        usort($rows, fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']));

        return array_values(array_slice($rows, 0, 10));
    }
}
