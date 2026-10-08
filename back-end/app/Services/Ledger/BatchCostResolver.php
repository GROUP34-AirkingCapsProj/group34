<?php

namespace App\Services\Ledger;

use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\PurchaseOrderDetail;

/**
 * Where a batch's unit cost comes from, and how honest that number is.
 *
 * Receiving used to stamp receiving_details.prod_price from the product's list
 * cost, which is not what the business paid. The PO line price is; the list cost
 * is a stand-in, and a batch that accumulates pieces at two different prices has
 * only a weighted average. cost_basis records which of those we are looking at so
 * the Explorer can show an auditor what rests on evidence and what does not.
 */
class BatchCostResolver
{
    /** @return array{unit_cost: ?float, cost_basis: string} */
    public function costForReceivingLine(?PurchaseOrderDetail $poDetail, Product $product): array
    {
        $poPrice = $poDetail?->unit_price;
        if ($poPrice !== null && (float) $poPrice > 0) {
            return ['unit_cost' => round((float) $poPrice, 2), 'cost_basis' => 'actual'];
        }

        $listCost = $product->cost_price;
        if ($listCost !== null && (float) $listCost > 0) {
            return ['unit_cost' => round((float) $listCost, 2), 'cost_basis' => 'product_default'];
        }

        return ['unit_cost' => null, 'cost_basis' => 'unavailable'];
    }

    /**
     * Fold an incoming line's cost into a batch that may already hold stock.
     *
     * $addedPieces is what is arriving now; the batch's quantity_received must
     * still be its value BEFORE the addition, or the average is weighted wrongly.
     *
     * @return array{unit_cost: ?float, cost_basis: string}
     */
    public function blendIntoBatch(InventoryBatch $batch, int $addedPieces, ?float $lineCost, string $lineBasis): array
    {
        $existingQty  = (int) $batch->quantity_received;
        $existingCost = $batch->unit_cost !== null ? (float) $batch->unit_cost : null;

        if ($existingCost === null || $existingQty <= 0) {
            return ['unit_cost' => $lineCost, 'cost_basis' => $lineBasis];
        }

        if ($lineCost === null) {
            // Pieces joined the batch at an unknown price. The existing cost is the
            // best number available, but it no longer describes the whole batch.
            return ['unit_cost' => $existingCost, 'cost_basis' => 'derived_weighted_avg'];
        }

        if (abs($existingCost - $lineCost) < 0.005) {
            return ['unit_cost' => $existingCost, 'cost_basis' => $batch->cost_basis ?? $lineBasis];
        }

        $totalQty = $existingQty + max(0, $addedPieces);
        if ($totalQty <= 0) {
            return ['unit_cost' => $existingCost, 'cost_basis' => $batch->cost_basis ?? $lineBasis];
        }

        $blended = (($existingCost * $existingQty) + ($lineCost * max(0, $addedPieces))) / $totalQty;

        return ['unit_cost' => round($blended, 2), 'cost_basis' => 'derived_weighted_avg'];
    }
}
