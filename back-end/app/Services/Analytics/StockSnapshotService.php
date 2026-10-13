<?php

namespace App\Services\Analytics;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Shared live stock population, thresholds, and cost valuation for reports and analytics. */
class StockSnapshotService
{
    public const PIECE_COST = 'COALESCE(p.cost_price, 0) / GREATEST(COALESCE(imd.pieces_per_package, 1), 1)';
    public const REORDER_POINT = 'COALESCE(NULLIF(i.reorder_level, 0), p.recommended_stocks, 0)';

    public function query(?int $locationId, ?int $categoryId): Builder
    {
        return DB::table('inventory as i')
            ->join('products as p', 'p.product_id', '=', 'i.product_id')
            ->join('locations as l', 'l.location_id', '=', 'i.location_id')
            ->leftJoin('installation_material_details as imd', 'imd.product_id', '=', 'p.product_id')
            ->leftJoin('category_lookup as c', 'c.category_id', '=', 'p.category_id')
            ->whereNull('p.deleted_at')
            ->whereNull('l.deleted_at')
            ->when($locationId !== null, fn ($q) => $q->where('i.location_id', $locationId))
            ->when($categoryId !== null, fn ($q) => $q->where('p.category_id', $categoryId));
    }

    public function forUser(User $user, ?int $locationId, ?int $categoryId): Collection
    {
        if (! $user->readsAllLocations()) {
            $own = $user->scopedLocationId();
            if ($own === null || ($locationId !== null && $locationId !== $own)) {
                return collect();
            }
            $locationId = $own;
        }

        return $this->rows($locationId, $categoryId);
    }

    public function rows(?int $locationId, ?int $categoryId): Collection
    {
        $cost = self::PIECE_COST;
        $reorder = self::REORDER_POINT;

        return $this->query($locationId, $categoryId)
            ->selectRaw("i.inventory_id, i.product_id, i.location_id, p.product_name AS product,
                p.product_code, COALESCE(c.category_name, 'Uncategorized') AS category,
                l.location_name AS location, i.quantity_on_hand, i.available_quantity,
                i.reorder_level, {$reorder} AS threshold, p.max_stock_level,
                {$cost} AS cost_price, i.quantity_on_hand * ({$cost}) AS stock_value")
            ->orderBy('l.location_name')->orderBy('p.product_name')->orderBy('i.inventory_id')
            ->get()->map(static function ($r) {
                $qty = (int) $r->quantity_on_hand;
                $threshold = (int) $r->threshold;
                $status = $qty <= 0 ? 'critical' : ($qty <= $threshold ? 'low'
                    : ($r->max_stock_level !== null && $qty > $r->max_stock_level ? 'overstock' : 'optimal'));

                return [
                    'id' => (int) $r->inventory_id,
                    'productId' => (int) $r->product_id,
                    'locationId' => (int) $r->location_id,
                    'product' => $r->product,
                    'product_code' => $r->product_code,
                    'category' => $r->category,
                    'location' => $r->location,
                    'quantity_on_hand' => $qty,
                    'available_quantity' => (int) $r->available_quantity,
                    'reorder_level' => (int) $r->reorder_level,
                    'threshold' => $threshold,
                    'threshold_source' => (int) $r->reorder_level > 0 ? 'Location reorder level' : 'Product default',
                    'shortfall' => max(0, $threshold - $qty),
                    'max_stock_level' => $r->max_stock_level === null ? null : (int) $r->max_stock_level,
                    'cost_price' => round((float) $r->cost_price, 4),
                    'stock_value' => round((float) $r->stock_value, 2),
                    'status' => $status,
                ];
            });
    }
}
