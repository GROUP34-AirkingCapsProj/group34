<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Issuance;
use App\Models\PickReservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IssuanceOrderController extends Controller
{
    public function quote(int $id): JsonResponse
    {
        $issuance = Issuance::with(['details.product', 'sets'])->findOrFail($id);
        $showCost = $this->canSeeMargin();

        // Availability is stock-per-product, so two lines of the same product draw
        // from one shared pool. `available` reports that pool (fetched once per
        // distinct product, not once per line), but shortage is cumulative: a
        // second line of the same product is short of whatever the earlier lines
        // already claimed, so their combined demand can't silently exceed on-hand.
        $locationId = (int) $issuance->location_id;
        $productIds = $issuance->details->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values();
        $poolFor    = $this->availabilityPool($locationId, (int) $issuance->issuance_id, $productIds->all());
        $remaining  = $poolFor; // drawn down as lines claim their share, in line order

        $lines = $issuance->details->map(function ($detail) use ($showCost, &$remaining, $poolFor) {
            $productId = (int) $detail->product_id;
            $requested = (int) $detail->quantity_requested;

            $pool      = $poolFor[$productId] ?? 0;
            $left      = $remaining[$productId] ?? 0;
            $granted   = max(0, min($requested, $left));   // what this line can actually claim
            $remaining[$productId] = $left - $granted;

            $unitPrice = (float) $detail->unit_price;
            $costPrice = (float) ($detail->cost_price_at_issue ?? $detail->product?->cost_price ?? 0);
            $shortBy   = $requested - $granted;
            $available = $pool;

            return [
                'issuance_detail_id' => (int) $detail->issuance_detail_id,
                'issuance_set_id'    => $detail->issuance_set_id !== null ? (int) $detail->issuance_set_id : null,
                'product_id'         => $productId,
                'product_name'       => $detail->product?->product_name,
                'product_type'       => $detail->product?->product_type,
                'quantity_requested' => $requested,
                'unit_price'         => $unitPrice,
                'line_total'         => round($requested * $unitPrice, 2),
                'available'          => $available,
                'short_by'           => $shortBy,
                'is_short'           => $shortBy > 0,
                // Cost/margin are hidden as explicit null, never a missing key, so
                // the frontend can branch on the value rather than key presence —
                // matching total_cost/total_margin below.
                'cost_price'         => $showCost ? $costPrice : null,
                'line_cost'          => $showCost ? round($requested * $costPrice, 2) : null,
            ];
        })->values();

        $subtotal        = round($lines->sum('line_total'), 2);
        $discountPercent = (float) ($issuance->discount_percent ?? 0);
        $discountAmount  = round($subtotal * $discountPercent / 100, 2);
        $total           = round($subtotal - $discountAmount, 2);
        $totalCost       = $showCost ? round($lines->sum('line_cost'), 2) : null;

        $setGroups = $issuance->sets->map(function ($group) use ($lines) {
            $groupLines = $lines->where('issuance_set_id', (int) $group->issuance_set_id);

            return [
                'issuance_set_id'   => (int) $group->issuance_set_id,
                'set_id'            => (int) $group->set_id,
                'set_name_snapshot' => $group->set_name_snapshot,
                'set_quantity'      => (int) $group->set_quantity,
                'was_modified'      => (bool) $group->was_modified,
                'line_count'        => $groupLines->count(),
                'group_total'       => round($groupLines->sum('line_total'), 2),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'issuance_id'      => (int) $issuance->issuance_id,
                'dr_number'        => $issuance->dr_number,
                'status_id'        => (int) $issuance->status_id,
                'location_id'      => (int) $issuance->location_id,
                'customer_id'      => (int) $issuance->customer_id,
                'discount_type'    => $issuance->discount_type,
                'discount_percent' => $discountPercent,
                'subtotal'         => $subtotal,
                'discount_amount'  => $discountAmount,
                'total_amount'     => $total,
                'total_cost'       => $totalCost,
                'total_margin'     => $totalCost === null ? null : round($total - $totalCost, 2),
                'has_shortages'    => $lines->contains('is_short', true),
                'set_groups'       => $setGroups,
                'lines'            => $lines,
            ],
        ]);
    }

    /**
     * Availability per product, keyed by product_id, in two queries regardless of
     * line count: on-hand `available_quantity` minus stock held by OTHER orders'
     * live reservations. Own-order holds are excluded so re-opening your own quote
     * never reads your own reservation as a shortage.
     *
     * @param  array<int,int>  $productIds
     * @return array<int,int>
     */
    protected function availabilityPool(int $locationId, int $ownIssuanceId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $onHand = Inventory::where('location_id', $locationId)
            ->whereIn('product_id', $productIds)
            ->pluck('available_quantity', 'product_id');

        $heldElsewhere = PickReservation::active()
            ->where('location_id', $locationId)
            ->whereIn('product_id', $productIds)
            ->where(function ($q) use ($ownIssuanceId) {
                $q->where('source_type', '!=', 'issuance')
                  ->orWhere('source_id', '!=', $ownIssuanceId);
            })
            ->selectRaw('product_id, SUM(quantity) AS held')
            ->groupBy('product_id')
            ->pluck('held', 'product_id');

        $pool = [];
        foreach ($productIds as $pid) {
            $pool[$pid] = max(0, (int) ($onHand[$pid] ?? 0) - (int) ($heldElsewhere[$pid] ?? 0));
        }

        return $pool;
    }

    protected function canSeeMargin(): bool
    {
        $user = Auth::user();
        if ($user === null) {
            return false;
        }

        return app(\App\Support\PermissionResolver::class)
            ->canUser((int) $user->user_id, 'inventory_issuance', 'view_margin');
    }
}
