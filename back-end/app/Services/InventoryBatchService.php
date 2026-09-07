<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\ItemSerial;
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

    /**
     * Add $qty to the bucket that holds $receivingId's stock at $locationId, opening
     * that bucket if the location has never held any of this receiving before.
     *
     * A batch does not travel. `inventory_batches` is keyed by product + location +
     * receiving, so one receipt spread across two warehouses is two rows sharing a
     * receiving_id, not one row that moves. That is what lets a transfer send part of
     * a batch onward while the rest stays put -- a single relocatable row could not
     * describe stock in two places at once.
     *
     * $receivedAt carries the ORIGINAL receipt date, not now(). FIFO orders by
     * received_at, so stamping the arrival date would let stock that has been in the
     * business for a year queue behind stock received last week purely because it
     * changed warehouse. Age belongs to the goods, not to the shelf.
     *
     * A null $receivingId credits the legacy catch-all, for stock whose lineage was
     * already untraceable before it moved.
     */
    public function creditFromReceiving(
        int $productId,
        int $locationId,
        ?int $receivingId,
        int $qty,
        $receivedAt = null,
        ?string $zoneLabel = null
    ): InventoryBatch {
        $batch = $receivingId
            ? InventoryBatch::firstOrCreate(
                ['product_id' => $productId, 'location_id' => $locationId, 'receiving_id' => $receivingId],
                ['quantity_received' => 0, 'quantity_remaining' => 0, 'received_at' => $receivedAt, 'zone_label' => $zoneLabel]
            )
            : $this->legacyBatch($productId, $locationId);

        if ($qty > 0) {
            $batch->quantity_received  += $qty;
            $batch->quantity_remaining += $qty;
            $batch->save();
        }

        // The legacy batch never has tags of its own, so there is nothing to reconcile.
        if ($receivingId) {
            $this->reconcileTagsForBatch((int) $batch->batch_id);
        }

        return $batch;
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

        foreach (array_keys($touched) as $touchedBatchId) {
            $this->reconcileTagsForBatch((int) $touchedBatchId);
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

        $isLegacy = false;
        if (! $batch) {
            $batch = $this->legacyBatch($productId, $locationId);
            $isLegacy = true;
        }

        $batch->quantity_remaining = max(0, $batch->quantity_remaining - $qty);
        $batch->save();

        // The legacy catch-all batch never has tags of its own (nothing mints
        // against it), so reconciling it would be a query with no rows to find.
        if (! $isLegacy) {
            $this->reconcileTagsForBatch((int) $batch->batch_id);
        }
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

        $this->reconcileTagsForBatch((int) $batch->batch_id);

        return [(int) $batch->batch_id => $before - (int) $batch->quantity_remaining];
    }

    /**
     * Restore $qty to a specific batch (up to its remaining capacity), spilling any
     * excess into the legacy batch. If $receivingId is null, everything goes to legacy.
     */
    public function restoreFifo(int $productId, int $locationId, int $qty, ?int $receivingId = null): void
    {
        $remaining = $qty;
        $touched   = [];

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
                    $touched[(int) $batch->batch_id] = true;
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
                $touched[(int) $batch->batch_id] = true;
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

        // The legacy batch is deliberately absent from $touched: nothing mints
        // tags against it, so there is never anything there to revive.
        foreach (array_keys($touched) as $touchedBatchId) {
            $this->reconcileTagsForBatch((int) $touchedBatchId);
        }
    }

    /**
     * Keep a batch's tags honest about whether stock is left.
     *
     * A tag names a physical package or lot; the batch owns the quantity. When a
     * batch is exhausted its tags are spent, so they stop being pickable and stop
     * reading as In Stock. Restoring stock revives them.
     *
     * Retirement is batch-level on purpose. Retiring tags one at a time as pieces
     * drain would mislabel real packages - the picker took one box, but we cannot
     * know which, so retiring "the oldest" would kill the tag on a box still
     * sitting on the shelf.
     *
     * Only generated material tags are touched. An appliance's item_serial row is
     * linked to a batch too, but its lifecycle is the hard-delete/recreate one in
     * IssuanceScanController; flipping its serial_type here would corrupt that
     * flow. The scope below mirrors Product::isSerialized() - a tag belongs to
     * this feature exactly when its product is an installation material that is
     * not serialized, which is the same fork ReceivingPostingService::mintTags()
     * takes when it decides to mint an INT- tag at all. Matching on intent rather
     * than on the "INT-" string keeps this correct if the serial format changes.
     *
     * A 'transferred' tag is never touched in either direction: it is out on a
     * transfer and TransferService::receive() owns putting it back.
     */
    public function reconcileTagsForBatch(int $batchId): void
    {
        $batch = InventoryBatch::whereKey($batchId)->first();

        if (! $batch) {
            return;
        }

        $exhausted = (int) $batch->quantity_remaining <= 0;
        $from      = $exhausted ? 'received' : 'issued';
        $to        = $exhausted ? 'issued' : 'received';

        ItemSerial::where('batch_id', $batch->batch_id)
            ->where('serial_type', $from)
            ->whereIn('product_id', function ($q) {
                $q->select('p.product_id')
                    ->from('products as p')
                    ->leftJoin('installation_material_details as d', 'd.product_id', '=', 'p.product_id')
                    ->where('p.product_type', 'installation_material')
                    ->whereRaw('COALESCE(d.is_serialized, 0) = 0');
            })
            ->update(['serial_type' => $to]);
    }

    /**
     * Batches for the monitoring UI drill-down: PO-linked batches oldest-first, legacy
     * batch (if any) last.
     *
     * Drained batches are excluded. A batch at zero is a receipt this location has
     * finished with -- issued out, or transferred on -- and listing it under "what is
     * in this warehouse" is how a shipped-out batch appeared to still be sitting at
     * its origin. inventory_batch_movements holds where it went.
     */
    public function batchesForDisplay(int $productId, int $locationId)
    {
        return InventoryBatch::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('quantity_remaining', '>', 0)
            ->with('receiving.purchaseOrder')
            ->orderByRaw('receiving_id IS NULL, received_at ASC')
            ->get();
    }
}
