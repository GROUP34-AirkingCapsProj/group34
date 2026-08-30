<?php

namespace App\Services;

use App\Models\InventoryBatch;
use Illuminate\Support\Facades\Log;

/**
 * Contract: every method in this service must be called from inside a DB transaction
 * that has already locked the relevant Inventory row (Inventory::...->lockForUpdate())
 * for the product+location being touched. This service's own lockForUpdate() calls only
 * take effect inside that outer transaction, and rely on the Inventory-row lock (not on
 * any lock of their own) to serialize access and prevent concurrent legacy-batch
 * duplication via legacyBatch()'s firstOrCreate.
 */
class InventoryBatchService
{
    public function createBatch(int $productId, int $locationId, ?int $receivingId, int $qty, $receivedAt, ?string $zoneLabel = null): InventoryBatch
    {
        return InventoryBatch::create([
            'product_id'         => $productId,
            'location_id'        => $locationId,
            'receiving_id'       => $receivingId,
            'quantity_received'  => $qty,
            'quantity_remaining' => $qty,
            'received_at'        => $receivedAt,
            'zone_label'         => $zoneLabel,
        ]);
    }

    /** The single catch-all batch (receiving_id null) for stock that predates batch tracking or has run out of traceable batches. */
    public function legacyBatch(int $productId, int $locationId): InventoryBatch
    {
        return InventoryBatch::firstOrCreate(
            ['product_id' => $productId, 'location_id' => $locationId, 'receiving_id' => null],
            ['quantity_received' => 0, 'quantity_remaining' => 0, 'received_at' => null]
        );
    }

    /**
     * Deduct $qty oldest-batch-first. Spills into the legacy batch (floored at 0,
     * never negative) if traceable batches run out. Returns [batch_id => qty_deducted].
     */
    public function deductFifo(int $productId, int $locationId, int $qty): array
    {
        $remaining = $qty;
        $touched = [];

        $batches = InventoryBatch::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->whereNotNull('receiving_id')
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at', 'asc')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($batch->quantity_remaining, $remaining);
            $batch->quantity_remaining -= $take;
            $batch->save();
            $touched[$batch->batch_id] = $take;
            $remaining -= $take;
        }

        // A shortfall here means traceable batches ran out and the legacy batch had to
        // spill over; if it still can't fully cover the request (floored at 0), that's
        // an inventory-tracking discrepancy worth investigating, so it's logged below.
        if ($remaining > 0) {
            $legacy = $this->legacyBatch($productId, $locationId);
            $before = $legacy->quantity_remaining;
            $legacy->quantity_remaining = max(0, $legacy->quantity_remaining - $remaining);
            $legacy->save();
            $actualDeducted = $before - $legacy->quantity_remaining;
            $touched[$legacy->batch_id] = ($touched[$legacy->batch_id] ?? 0) + $actualDeducted;

            if ($actualDeducted < $remaining) {
                Log::warning('InventoryBatchService: unfulfillable FIFO deduction shortfall', [
                    'product_id'  => $productId,
                    'location_id' => $locationId,
                    'shortfall'   => $remaining - $actualDeducted,
                ]);
            }
        }

        return $touched;
    }

    /** Exact-batch deduction for serialized items, where the unit's receiving_id is already known. */
    public function deductFromReceiving(?int $receivingId, int $productId, int $locationId, int $qty): void
    {
        $batch = $receivingId
            ? InventoryBatch::where('receiving_id', $receivingId)
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->first()
            : null;

        if (! $batch) {
            $batch = $this->legacyBatch($productId, $locationId);
        }

        $batch->quantity_remaining = max(0, $batch->quantity_remaining - $qty);
        $batch->save();
    }

    /**
     * Exact-batch deduction when the caller already knows which batch the stock
     * physically came from - an authorised FIFO override, where the picker took
     * newer stock on purpose and the ledger must follow the goods rather than
     * the FIFO order.
     *
     * Keyed on the batch itself rather than on receiving_id, unlike
     * deductFromReceiving(): a transfer-created batch has a null receiving_id
     * and would silently fall through to legacyBatch(), which is a different
     * wrong answer.
     *
     * If the batch id does not resolve, falls back to deductFifo() rather than
     * throwing. A pick that has already been authorised should not be blocked by
     * a batch row that vanished between the scan and the commit; deducting
     * oldest-first is the pre-existing behaviour and stays correct in aggregate.
     * That fallback needs the product and location, which cannot be read off a
     * batch row that is not there - hence the two optional arguments. Without
     * them an unresolvable batch deducts nothing, and says so in the log.
     *
     * @return array<int,int> batch_id => qty_deducted, matching deductFifo()'s shape
     */
    public function deductFromBatch(int $batchId, int $qty, ?int $productId = null, ?int $locationId = null): array
    {
        $batch = InventoryBatch::whereKey($batchId)->lockForUpdate()->first();

        if (! $batch) {
            Log::warning('InventoryBatchService: deductFromBatch could not resolve the batch, falling back to FIFO', [
                'batch_id'    => $batchId,
                'quantity'    => $qty,
                'product_id'  => $productId,
                'location_id' => $locationId,
            ]);

            return ($productId !== null && $locationId !== null)
                ? $this->deductFifo($productId, $locationId, $qty)
                : [];
        }

        $before = (int) $batch->quantity_remaining;
        $batch->quantity_remaining = max(0, $before - $qty);
        $batch->save();

        return [(int) $batch->batch_id => $before - (int) $batch->quantity_remaining];
    }

    /**
     * Restore $qty to a specific batch (up to its remaining capacity), spilling any
     * excess into the legacy batch. If $receivingId is null, everything goes to legacy.
     */
    public function restoreFifo(int $productId, int $locationId, int $qty, ?int $receivingId = null): void
    {
        $remaining = $qty;

        if ($receivingId) {
            $batch = InventoryBatch::where('receiving_id', $receivingId)
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->first();

            if ($batch) {
                $room = max(0, $batch->quantity_received - $batch->quantity_remaining);
                $restore = min($room, $remaining);
                if ($restore > 0) {
                    $batch->quantity_remaining += $restore;
                    $batch->save();
                    $remaining -= $restore;
                }
            }
        }

        if ($remaining > 0) {
            // If the original receiving link was not recorded, restore into the
            // oldest traceable batch that has room before creating legacy stock.
            $batches = InventoryBatch::where('product_id', $productId)
                ->where('location_id', $locationId)
                ->whereNotNull('receiving_id')
                ->whereColumn('quantity_remaining', '<', 'quantity_received')
                ->orderBy('received_at')
                ->lockForUpdate()
                ->get();

            foreach ($batches as $batch) {
                if ($remaining <= 0) break;
                $restore = min(
                    $remaining,
                    (int) $batch->quantity_received - (int) $batch->quantity_remaining
                );
                $batch->quantity_remaining += $restore;
                $batch->save();
                $remaining -= $restore;
            }

            if ($remaining > 0) {
                $legacy = $this->legacyBatch($productId, $locationId);
                $legacy->quantity_remaining += $remaining;
                $legacy->quantity_received = max(
                    $legacy->quantity_received,
                    $legacy->quantity_remaining
                );
                $legacy->save();
            }
        }
    }

    /** Batches for the monitoring UI drill-down: PO-linked batches oldest-first, legacy batch (if any) last. */
    public function batchesForDisplay(int $productId, int $locationId)
    {
        return InventoryBatch::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->with('receiving.purchaseOrder')
            ->orderByRaw('receiving_id IS NULL, received_at ASC')
            ->get();
    }
}
