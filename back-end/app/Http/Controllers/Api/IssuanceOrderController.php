<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Issuance;
use App\Models\PickReservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IssuanceOrderController extends Controller
{
    public function quote(int $id): JsonResponse
    {
        $issuance = Issuance::with(['details.product.consumableDetail', 'sets'])->findOrFail($id);
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

            $piecesPerPackage = max(1, (int) ($detail->product?->getPiecesPerPackage() ?? 1));
            $unitPrice = $detail->product
                ? round((float) $detail->product->unit_price / $piecesPerPackage, 4)
                : (float) $detail->unit_price;
            $costPrice = $detail->product
                ? round((float) $detail->product->cost_price / $piecesPerPackage, 4)
                : (float) ($detail->cost_price_at_issue ?? 0);
            $shortBy   = $requested - $granted;
            $available = $pool;

            return [
                'issuance_detail_id' => (int) $detail->issuance_detail_id,
                'issuance_set_id'    => $detail->issuance_set_id !== null ? (int) $detail->issuance_set_id : null,
                'product_id'         => $productId,
                'product_name'       => $detail->product?->product_name,
                'product_type'       => $detail->product?->product_type,
                'quantity_requested' => $requested,
                // Packaging so the UI can show a pack/box breakdown like Transfers.
                'pieces_per_package' => (int) ($detail->product?->pieces_per_package ?? 0),
                'package_unit'       => $detail->product?->consumableDetail?->package_unit,
                'package_price'      => (float) ($detail->product?->unit_price ?? $unitPrice),
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
                'external_reference' => $issuance->external_reference,
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

    public function pickPlan(int $id): JsonResponse
    {
        $issuance = Issuance::with('details.product')->findOrFail($id);

        $lines = $issuance->details
            ->groupBy('product_id')
            ->map(fn ($rows, $pid) => [
                'product_id' => (int) $pid,
                'quantity'   => max(0, (int) $rows->sum('quantity_requested') - (int) $rows->sum('quantity_issued')),
            ])
            ->filter(fn ($line) => $line['quantity'] > 0)
            ->values()->all();

        $excluded = app(\App\Services\IncidentService::class)->excludedBatchIds((int) $issuance->location_id);

        return response()->json([
            'success' => true,
            'data'    => app(\App\Services\FifoPickingService::class)
                ->suggest((int) $issuance->location_id, $lines, $excluded),
        ]);
    }

    public function confirmQuote(Request $request, int $id): JsonResponse
    {
        $request->validate(['accept_shortages' => 'nullable|boolean']);

        $issuance = Issuance::with('details.product')->findOrFail($id);

        if ((int) $issuance->status_id !== \App\Support\IssuanceStatus::draft()) {
            return response()->json([
                'success' => false,
                'message' => 'Only a Draft issuance can be confirmed.',
            ], 422);
        }

        if ($issuance->details->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Add at least one line before confirming.',
            ], 422);
        }

        $locationId = (int) $issuance->location_id;
        $productIds = $issuance->details->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        // One shared pool per product, drawn down in line order, so two lines of
        // the same product cannot both be granted the full quantity.
        $remaining = $this->availabilityPool($locationId, (int) $issuance->issuance_id, $productIds);

        $plan      = [];
        $shortages = [];

        foreach ($issuance->details as $detail) {
            $productId = (int) $detail->product_id;
            $requested = (int) $detail->quantity_requested;
            $left      = $remaining[$productId] ?? 0;
            $grant     = max(0, min($requested, $left));
            $remaining[$productId] = $left - $grant;

            if ($grant < $requested) {
                $shortages[] = [
                    'product_id'   => $productId,
                    'product_name' => $detail->product?->product_name,
                    'requested'    => $requested,
                    'available'    => $grant,
                ];
            }

            $plan[] = ['detail' => $detail, 'grant' => $grant, 'requested' => $requested];
        }

        if ($shortages !== [] && ! $request->boolean('accept_shortages')) {
            return response()->json([
                'success'   => false,
                'message'   => 'Some lines cannot be fully served. Re-submit with accept_shortages to proceed short.',
                'shortages' => $shortages,
            ], 422);
        }

        if (collect($plan)->sum('grant') === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No stock is available for any line on this order.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($issuance, $plan, $locationId) {
                $subtotal = 0.0;
                $lines    = [];

                foreach ($plan as $row) {
                    /** @var \App\Models\IssuanceDetail $detail */
                    $detail = $row['detail'];
                    $grant  = (int) $row['grant'];
                    $piecesPerPackage = max(1, (int) ($detail->product?->getPiecesPerPackage() ?? 1));
                    $price = $detail->product
                        ? round((float) $detail->product->unit_price / $piecesPerPackage, 4)
                        : (float) $detail->unit_price;

                    $detail->update([
                        'quantity_requested'  => $grant,
                        'quantity_short'      => (int) $row['requested'] - $grant,
                        'unit_price'           => $price,
                        'cost_price_at_issue' => $detail->product
                            ? round(
                                (float) $detail->product->cost_price
                                / max(1, $detail->product->isConsumable()
                                    ? $detail->product->getPiecesPerPackage()
                                    : 1),
                                4
                            )
                            : 0,
                        'subtotal'            => round($grant * $price, 2),
                    ]);

                    $subtotal += $grant * $price;

                    if ($grant > 0) {
                        $lines[] = ['product_id' => (int) $detail->product_id, 'quantity' => $grant];
                    }
                }

                app(\App\Services\ReservationService::class)
                    ->reserve('issuance', (int) $issuance->issuance_id, $locationId, $lines);

                $subtotal        = round($subtotal, 2);
                $discountPercent = (float) ($issuance->discount_percent ?? 0);
                $discountAmount  = round($subtotal * $discountPercent / 100, 2);

                $issuance->update([
                    'subtotal'        => $subtotal,
                    'discount_amount' => $discountAmount,
                    'total_amount'    => round($subtotal - $discountAmount, 2),
                    'status_id'       => \App\Support\IssuanceStatus::pending(),
                ]);
            });
        } catch (\RuntimeException $e) {
            // Availability moved between the pool read and the lock — another order won the race.
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Illuminate\Database\QueryException $e) {
            // Deadlock (1213) or lock-wait timeout (1205) under heavy contention.
            // reserve() locks in canonical product order so this should not arise,
            // but if it does the request is retryable — a 409, not a 500.
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The counter is busy reserving stock. Please try confirming again.',
                ], 409);
            }
            throw $e;
        }

        return $this->quote((int) $issuance->issuance_id);
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
    public function addSetLine(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'set_id'       => 'required|integer|exists:product_sets,set_id',
            'set_quantity' => 'required|integer|min:1',
        ]);

        $issuance = Issuance::findOrFail($id);

        try {
            app(\App\Services\IssuanceOrderService::class)
                ->addSet($issuance, (int) $validated['set_id'], (int) $validated['set_quantity']);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->quote($id);
    }

    public function addCustomLine(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,product_id',
            'quantity'   => 'required|integer|min:1',
        ]);

        $issuance = Issuance::findOrFail($id);

        try {
            app(\App\Services\IssuanceOrderService::class)
                ->addCustomLine($issuance, (int) $validated['product_id'], (int) $validated['quantity']);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->quote($id);
    }

    public function updateLine(Request $request, int $id, int $lineId): JsonResponse
    {
        $validated = $request->validate(['quantity' => 'required|integer|min:1']);
        $issuance  = Issuance::findOrFail($id);

        try {
            app(\App\Services\IssuanceOrderService::class)
                ->updateLine($issuance, $lineId, (int) $validated['quantity']);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->quote($id);
    }

    public function removeLine(int $id, int $lineId): JsonResponse
    {
        $issuance = Issuance::findOrFail($id);

        try {
            app(\App\Services\IssuanceOrderService::class)->removeLine($issuance, $lineId);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->quote($id);
    }

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

        // Stock named by an unsettled incident is physically present, so it is
        // still in available_quantity — but it must not be promised to anybody.
        // Subtracted here rather than deducted at filing, for the same reason
        // reservations are: confirming or dismissing the report returns the
        // quantity to the pool with no cleanup step to forget.
        $quarantined = app(\App\Services\IncidentService::class)->quarantinedQuantities($locationId);

        $pool = [];
        foreach ($productIds as $pid) {
            $pool[$pid] = max(0, (int) ($onHand[$pid] ?? 0)
                - (int) ($heldElsewhere[$pid] ?? 0)
                - (int) ($quarantined[$pid] ?? 0));
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
