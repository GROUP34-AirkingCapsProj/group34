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

        $lines = $issuance->details->map(function ($detail) use ($issuance, $showCost) {
            $requested = (int) $detail->quantity_requested;
            $available = $this->availableFor(
                (int) $issuance->location_id,
                (int) $detail->product_id,
                (int) $issuance->issuance_id
            );

            $unitPrice = (float) $detail->unit_price;
            $costPrice = (float) ($detail->cost_price_at_issue ?? $detail->product?->cost_price ?? 0);
            $shortBy   = max(0, $requested - $available);

            return array_filter([
                'issuance_detail_id' => (int) $detail->issuance_detail_id,
                'issuance_set_id'    => $detail->issuance_set_id !== null ? (int) $detail->issuance_set_id : null,
                'product_id'         => (int) $detail->product_id,
                'product_name'       => $detail->product?->product_name,
                'product_type'       => $detail->product?->product_type,
                'quantity_requested' => $requested,
                'unit_price'         => $unitPrice,
                'line_total'         => round($requested * $unitPrice, 2),
                'available'          => $available,
                'short_by'           => $shortBy,
                'is_short'           => $shortBy > 0,
                'cost_price'         => $showCost ? $costPrice : null,
                'line_cost'          => $showCost ? round($requested * $costPrice, 2) : null,
            ], fn ($v, $k) => ! in_array($k, ['cost_price', 'line_cost'], true) || $v !== null, ARRAY_FILTER_USE_BOTH);
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
     * On-hand availability minus stock held by other orders' live reservations.
     * Own-order holds are excluded so re-opening your own quote never reads as short.
     */
    protected function availableFor(int $locationId, int $productId, int $ownIssuanceId): int
    {
        $onHand = (int) (Inventory::where('location_id', $locationId)
            ->where('product_id', $productId)
            ->value('available_quantity') ?? 0);

        $heldElsewhere = (int) PickReservation::active()
            ->where('location_id', $locationId)
            ->where('product_id', $productId)
            ->where(function ($q) use ($ownIssuanceId) {
                $q->where('source_type', '!=', 'issuance')
                  ->orWhere('source_id', '!=', $ownIssuanceId);
            })
            ->sum('quantity');

        return max(0, $onHand - $heldElsewhere);
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
