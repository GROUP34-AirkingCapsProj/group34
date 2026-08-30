<?php

namespace App\Services;

use App\Models\IncidentItem;
use App\Models\InventoryBatch;
use Illuminate\Support\Facades\DB;

/**
 * Shelf-level FIFO: does the tag the picker scanned belong to the batch they
 * were supposed to take?
 *
 * InventoryBatchService::deductFifo() already keeps the BOOKS oldest-first, but
 * it consumes whichever batch rows are oldest regardless of which physical box
 * left the building. This is the other half: every received installation
 * material now carries a digital tag naming exactly one batch, so the check is
 * a batch comparison.
 *
 * The eligibility rules below exist because each of them, left out, deadlocks
 * issuance: an expected batch no scan can ever satisfy refuses every pick of
 * that material forever.
 */
class FifoEnforcementService
{
    /**
     * @return array|null null when the pick is FIFO-correct, otherwise a violation payload
     */
    public function check(int $productId, int $locationId, ?int $scannedBatchId): ?array
    {
        // Deliberate: stock received before digital tags existed carries no tag, so a
        // scan of it resolves to no batch at all. There is nothing to judge, and
        // refusing a picker over data we never captured is worse than letting it pass.
        if ($scannedBatchId === null) {
            return null;
        }

        $oldest = $this->oldestEligibleBatch($productId, $locationId);

        if ($oldest === null || (int) $oldest->batch_id === $scannedBatchId) {
            return null;
        }

        $scanned = InventoryBatch::find($scannedBatchId);

        return [
            'action'        => 'fifo_violation',
            'scanned_batch' => [
                'batch_id'    => $scannedBatchId,
                'received_at' => $scanned?->received_at,
            ],
            'expected_batch' => [
                'batch_id'    => (int) $oldest->batch_id,
                'received_at' => $oldest->received_at,
                'po_number'   => $this->poNumberFor($oldest),
                // Required, not decorative. A refusal that does not say WHERE the right
                // stock is gets worked around — marked damaged, or overridden every time.
                'zone_label'  => $oldest->zone_label,
                'remaining'   => (int) $oldest->quantity_remaining,
            ],
        ];
    }

    /**
     * The batch the picker should have taken from, or null when nothing qualifies.
     *
     * Three exclusions, each of which would otherwise name a batch no scan can satisfy:
     *
     * - receiving_id null is the legacy catch-all row from
     *   InventoryBatchService::legacyBatch(). Its received_at is null too, and MySQL
     *   sorts NULL first on ASC, so it always looks oldest — while no tag can ever
     *   point at it. deductFifo() excludes it for the same reason.
     * - quantity_remaining 0 means the batch is gone; an empty older batch must not
     *   block the one behind it.
     * - quarantined pieces are physically present and still counted, but unissuable.
     *   A batch qualifies only while some of it is actually pickable.
     *
     * The batch_id tie-break is not cosmetic: two batches received in the same second
     * order non-deterministically, so "the oldest" flips between queries and the picker
     * gets a violation that a re-scan cannot clear.
     */
    private function oldestEligibleBatch(int $productId, int $locationId): ?InventoryBatch
    {
        $quarantined = IncidentItem::quarantinedByBatch($locationId);

        $batches = InventoryBatch::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->whereNotNull('receiving_id')
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at')
            ->orderBy('batch_id')
            ->get();

        foreach ($batches as $batch) {
            $held = $quarantined[(int) $batch->batch_id] ?? 0;

            if ((int) $batch->quantity_remaining - $held > 0) {
                return $batch;
            }
        }

        return null;
    }

    /** The PO the batch arrived against, or null when it has no receiving to trace. */
    private function poNumberFor(InventoryBatch $batch): ?string
    {
        if ($batch->receiving_id === null) {
            return null;
        }

        return DB::table('receivings as r')
            ->join('purchase_orders as po', 'po.po_id', '=', 'r.po_id')
            ->where('r.receiving_id', $batch->receiving_id)
            ->value('po.po_number');
    }
}
