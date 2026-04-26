<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Location;
use App\Models\Product;
use App\Models\Inventory;
use App\Models\Sale;
use App\Models\Transfer;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        return response()->json([
            'stats' => $this->getStats(),
            'inventory_by_category'  => $this->getInventoryByCategory(),
            'location_distribution'  => $this->getLocationDistribution(),
            'stock_status_by_location' => $this->getStockStatusByLocation(),
            'stock_trends'           => $this->getStockTrends(),
            'sales_trends'           => $this->getSalesTrends(),
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

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function getStats(): array
    {
        return [
            'total_users'       => User::count(),
            'active_locations'  => Location::count(),
            'total_products'    => Product::count(),
            'low_stock'         => Product::whereColumn('quantity', '<=', 'recommended_stocks')->count(),
            'total_sales'       => Sale::count(),
            'pending_transfers' => Transfer::whereHas('status', fn($q) => $q->where('status_name', 'Pending'))->count(),
        ];
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
}
