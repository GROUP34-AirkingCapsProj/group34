<?php

namespace App\Services;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use Illuminate\Support\Facades\DB;

/**
 * The SOLE mutator of incident_items.
 *
 * Quarantine does NOT deduct: the goods are physically present and a stock
 * count would find them, so `quantity_on_hand = pickable + quarantined` holds
 * from filing until confirmation. Stock moves once, in applyOutcomes(), inside
 * one transaction — a partial confirm (stock gone, report unconfirmed, or the
 * reverse) is undetectable without a physical count.
 *
 * That is also why filing a report never retires a material's digital tags:
 * quarantine touches no batch, so there is no quantity change to reconcile.
 * Quarantined stock is made unpickable by IncidentItem::quarantinedBatchIds()
 * excluding the batch from FIFO, not by draining it, and the goods are still on
 * the shelf. Tags retire only in deduct(), i.e. only for the outcomes where the
 * goods physically leave the building, and come back in returnToStock().
 */
class IncidentItemService
{
    /**
     * The serial status each item status maps to.
     *
     * Written out rather than derived with ucfirst(): that would produce
     * "Scrapped" and "Available", and only the names in this map are actually
     * seeded into status_lookup. A derived name that happens not to exist
     * throws at the worst possible moment — mid-confirmation, after the stock
     * has already been deducted inside the transaction.
     */
    private const SERIAL_STATUS_NAME = [
        IncidentItem::STATUS_QUARANTINED => 'Quarantined',
        IncidentItem::STATUS_AVAILABLE   => 'In Stock',
        IncidentItem::STATUS_SCRAPPED    => 'Scrapped',
        IncidentItem::STATUS_RETURNED    => 'Returned',
        IncidentItem::STATUS_MISSING     => 'Missing',
        // Both units are still in the building — one on the bench, one waiting
        // on a claim — so neither reads as gone to anyone who scans it.
        IncidentItem::STATUS_UNDER_REPAIR            => 'Under Repair',
        IncidentItem::STATUS_AWAITING_WARRANTY_CLAIM => 'Awaiting Warranty Claim',
    ];

    /**
     * Attach the physical things a report is about and make them unpickable.
     *
     * @param array<int,array{product_id:int,batch_id?:int|null,serial_id?:int|null,quantity:int,unit_role?:string,model_code?:string|null,serial_number?:string|null}> $items
     * @return array<int,IncidentItem>
     */
    public function quarantine(IncidentReport $incident, array $items): array
    {
        if ($items === []) {
            throw new \InvalidArgumentException('An incident that moves stock must name at least one item.');
        }

        return DB::transaction(function () use ($incident, $items) {
            $created = [];

            foreach ($items as $row) {
                // Inbound goods are ARRIVING. They left inventory when they were
                // issued out, so there is no batch to take them from and nothing
                // to deduct. The item records that they are physically here and
                // not yet accepted back.
                if (! empty($row['is_inbound'])) {
                    $created[] = IncidentItem::create([
                        'incident_id'   => (int) $incident->incident_id,
                        'product_id'    => (int) $row['product_id'],
                        'serial_id'     => $row['serial_id'] ?? null,
                        // NULLED on purpose. The reporter may have named the
                        // batch the goods originally shipped from, but they are
                        // not in it any more — restocking onto it would inflate
                        // a traceable batch with units that did not come back
                        // from it. A restocked inbound return lands in the
                        // legacy batch, whose null received_at sorts it first in
                        // FIFO.
                        'batch_id'      => null,
                        'unit_role'     => $row['unit_role'] ?? IncidentItem::ROLE_SINGLE,
                        'is_inbound'    => true,
                        'model_code'    => $row['model_code'] ?? null,
                        'serial_number' => $row['serial_number'] ?? null,
                        'quantity'      => max(1, (int) $row['quantity']),
                        'expected_product_id' => $row['expected_product_id'] ?? null,
                        'expected_quantity'   => $row['expected_quantity'] ?? null,
                        'status'        => IncidentItem::STATUS_QUARANTINED,
                    ]);

                    $this->stampSerial($row['serial_id'] ?? null, 'Quarantined');
                    continue;
                }

                $created[] = IncidentItem::create([
                    'incident_id'   => (int) $incident->incident_id,
                    'product_id'    => (int) $row['product_id'],
                    'serial_id'     => $row['serial_id'] ?? null,
                    'batch_id'      => $row['batch_id'] ?? null,
                    'unit_role'     => $row['unit_role'] ?? IncidentItem::ROLE_SINGLE,
                    'model_code'    => $row['model_code'] ?? null,
                    'serial_number' => $row['serial_number'] ?? null,
                    'quantity'      => max(1, (int) $row['quantity']),
                    'expected_product_id' => $row['expected_product_id'] ?? null,
                    'expected_quantity'   => $row['expected_quantity'] ?? null,
                    'is_inbound'    => false,
                    'status'        => IncidentItem::STATUS_QUARANTINED,
                ]);

                // A scan must say "quarantined", not "in stock", the moment a
                // report names the unit.
                $this->stampSerial($row['serial_id'] ?? null, 'Quarantined');
            }

            return $created;
        });
    }

    /** The inspector's decision. Recorded now, applied only when the admin confirms. */
    public function setOutcome(IncidentItem $item, string $outcome, ?string $note): IncidentItem
    {
        if (! in_array($outcome, IncidentItem::OUTCOMES, true)) {
            throw new \InvalidArgumentException("Unknown outcome: {$outcome}");
        }

        if ($item->status !== IncidentItem::STATUS_QUARANTINED) {
            throw new \RuntimeException('This item has already been settled.');
        }

        $item->update(['outcome' => $outcome, 'outcome_note' => $note]);

        return $item->fresh();
    }

    /**
     * Apply every item's outcome. This is the admin's confirmation, and the one
     * moment stock actually moves.
     */
    public function applyOutcomes(IncidentReport $incident, int $actorId): void
    {
        DB::transaction(function () use ($incident, $actorId) {
            $items = IncidentItem::where('incident_id', $incident->incident_id)
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw new \RuntimeException('This incident has no items to settle.');
            }

            // Re-checked UNDER the lock: two admins confirming at once must not
            // both pass the guard and deduct the quantity twice.
            if ($items->every(fn (IncidentItem $i) => $i->status !== IncidentItem::STATUS_QUARANTINED)) {
                throw new \RuntimeException('This incident has already been confirmed.');
            }

            $undecided = $items->firstWhere(fn (IncidentItem $i) =>
                $i->status === IncidentItem::STATUS_QUARANTINED && $i->outcome === null);

            if ($undecided !== null) {
                throw new \InvalidArgumentException(
                    'Every item needs an inspection outcome before this can be confirmed.'
                );
            }

            $shortfalls = [];

            foreach ($items as $item) {
                if ($item->status !== IncidentItem::STATUS_QUARANTINED) {
                    continue;
                }

                if ($item->is_inbound) {
                    // Only acceptance counts them. Every other ending leaves the
                    // stock figure alone, because it never included them.
                    if ($item->outcome === IncidentItem::OUTCOME_RESTOCK) {
                        $this->returnToStock($item);
                    }
                } elseif (in_array($item->outcome, IncidentItem::OUTCOMES_THAT_REMOVE_STOCK, true)) {
                    // Only the outcomes where the goods physically leave drop the
                    // count — and because quarantine never deducted, this is the
                    // single deduction for the whole lifecycle. The old check was
                    // "anything that isn't restock is a departure", which would
                    // delete a unit sitting on the bench under repair or awaiting
                    // a warranty claim.
                    $short = $this->deduct($item);

                    if ($short > 0) {
                        $shortfalls[] = "product {$item->product_id}: {$short} of "
                            . (int) $item->quantity . ' pc could not be deducted';
                    }
                }

                $status = IncidentItem::OUTCOME_STATUS[$item->outcome];

                $item->update(['status' => $status]);

                $this->stampSerial(
                    $item->serial_id,
                    self::SERIAL_STATUS_NAME[$status],
                    makeReceivable: $status === IncidentItem::STATUS_AVAILABLE,
                );
            }

            $this->recordShortfalls($incident, $shortfalls);
        });
    }

    /**
     * Write down what the confirmation could not take.
     *
     * A deduction can only remove stock that is still there, so goods picked out
     * from under an open report leave the count short of what the report says.
     * Clamping alone loses that difference silently and the next physical count
     * finds an unexplained gap — so the gap goes onto the report itself, in the
     * same transaction as the deduction that caused it.
     *
     * @param array<int,string> $shortfalls
     */
    private function recordShortfalls(IncidentReport $incident, array $shortfalls): void
    {
        if ($shortfalls === []) {
            return;
        }

        // Re-read rather than trusting the passed model: applyOutcomes may have
        // been handed a stale instance, and this appends to the note rather than
        // replacing it.
        $current = (string) (IncidentReport::whereKey($incident->getKey())->value('resolution_note') ?? '');

        $line = 'Stock shortfall on confirmation — ' . implode('; ', $shortfalls)
            . '. The stock was gone before this report was settled; a physical count is needed.';

        IncidentReport::whereKey($incident->getKey())->update([
            'resolution_note' => trim($current === '' ? $line : $current . "\n" . $line),
        ]);
    }

    /**
     * Let the goods go back to the picking pool with no stock movement at all.
     *
     * This is a DISMISSAL — the report was raised in error, so there is nothing
     * to apply. Nothing was ever deducted, so releasing is purely a status
     * change; it deliberately ignores whatever outcome an inspector may have
     * recorded, because dismissing says that inspection described nothing real.
     * It lives here rather than in IncidentService so incident_items still has
     * exactly one writer.
     */
    public function release(IncidentReport $incident): void
    {
        DB::transaction(function () use ($incident) {
            $items = IncidentItem::where('incident_id', $incident->incident_id)
                ->where('status', IncidentItem::STATUS_QUARANTINED)
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                $item->update(['status' => IncidentItem::STATUS_AVAILABLE]);

                $this->stampSerial(
                    $item->serial_id,
                    self::SERIAL_STATUS_NAME[IncidentItem::STATUS_AVAILABLE],
                    makeReceivable: true,
                );
            }
        });
    }

    /**
     * Accept arriving goods into stock — the inbound counterpart of deduct().
     *
     * The quantity must land in a BATCH as well as in inventory: FIFO picks out
     * of inventory_batches, so units added to quantity_on_hand alone are
     * countable and unpickable at the same time, and the two figures drift apart
     * permanently.
     *
     * A batchless quantity goes to the legacy catch-all batch rather than onto
     * the nearest traceable one. An inbound return belongs to no traceable
     * batch — inflating one would attribute units to a receiving they did not
     * come from — and the legacy batch's null received_at sorts it first in
     * FIFO, so returned goods go back out before newer stock.
     */
    private function returnToStock(IncidentItem $item): void
    {
        $quantity = (int) $item->quantity;

        if ($quantity < 1) {
            return;
        }

        $locationId = (int) $item->incident->location_id;
        $productId  = (int) $item->product_id;

        // Locked FIRST: InventoryBatchService's contract is that its callers
        // already hold the Inventory row lock for the product+location, which is
        // what serializes legacyBatch()'s firstOrCreate against a concurrent
        // confirmation creating a second catch-all batch.
        $inventory = Inventory::where('location_id', $locationId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        $batch = $item->batch_id !== null
            ? InventoryBatch::lockForUpdate()->find($item->batch_id)
            : app(InventoryBatchService::class)->legacyBatch($productId, $locationId);

        if ($batch !== null) {
            $batch->quantity_remaining += $quantity;
            // quantity_received is the batch's high-water mark, and restoreFifo
            // reads it as "room". Left behind, the legacy batch would look
            // permanently over-full and refuse later restorations.
            $batch->quantity_received = max((int) $batch->quantity_received, (int) $batch->quantity_remaining);
            $batch->save();

            // Saved first, then reconciled: reconcileTagsForBatch() re-reads the
            // row, so acting on the in-memory mutation above would see the old
            // quantity and leave the revived batch's tags retired.
            app(InventoryBatchService::class)->reconcileTagsForBatch((int) $batch->batch_id);
        }

        if ($inventory !== null) {
            $inventory->increment('quantity_on_hand', $quantity);
            $inventory->increment('available_quantity', $quantity);

            return;
        }

        // No inventory row at all: the product has never been counted here. The
        // goods are still physically arriving, so the row is created rather than
        // the quantity dropped.
        Inventory::create([
            'location_id'        => $locationId,
            'product_id'         => $productId,
            'quantity_on_hand'   => $quantity,
            'available_quantity' => $quantity,
            'reorder_level'      => 0,
        ]);
    }

    /**
     * Take the goods out of the count.
     *
     * @return int pieces the report named that were NOT there to take — see
     *             recordShortfalls(). Zero on a clean deduction.
     */
    private function deduct(IncidentItem $item): int
    {
        $requested = (int) $item->quantity;
        $quantity  = $requested;

        if ($item->batch_id !== null) {
            $batch = InventoryBatch::lockForUpdate()->find($item->batch_id);
            if ($batch !== null) {
                // Clamp to what the batch can actually give up, and carry that
                // clamped figure into the location deduction below. Clamping the
                // two independently makes them diverge: a report of 10 against a
                // batch holding 3 emptied the batch by 3 but the location by 10,
                // vanishing 7 units no batch ever lost.
                $quantity = min($quantity, (int) $batch->quantity_remaining);
                $batch->decrement('quantity_remaining', $quantity);

                // Keyed off the id, not off $batch: decrement() writes straight
                // to the database and leaves the model holding the pre-decrement
                // value, so passing the stale row would misjudge exhaustion.
                app(InventoryBatchService::class)->reconcileTagsForBatch((int) $batch->batch_id);
            }
        }

        $inventory = Inventory::where('location_id', $item->incident->location_id)
            ->where('product_id', $item->product_id)
            ->lockForUpdate()
            ->first();

        if ($inventory === null) {
            // No inventory row: nothing was ever counted here, so the whole
            // quantity is unaccounted for rather than zero.
            return $requested;
        }

        $deducted = min($quantity, (int) $inventory->quantity_on_hand);

        $inventory->decrement('quantity_on_hand', $deducted);
        // Availability is clamped independently on purpose: it can legitimately
        // sit below on-hand, and driving it from $deducted would push it
        // negative on a product that is already fully reserved.
        $inventory->decrement('available_quantity', min($quantity, (int) $inventory->available_quantity));

        return max(0, $requested - $deducted);
    }

    /**
     * Keep the serial's own status honest.
     *
     * `$makeReceivable` also resets serial_type to 'received'. That pair moves
     * together: scopeIssuableAt and FifoPickingService::serialSteps both require
     * serial_type 'received', so a serial flipped back to In Stock while still
     * typed 'issued' reads as in stock everywhere and is pickable nowhere.
     */
    private function stampSerial(?int $serialId, string $statusName, bool $makeReceivable = false): void
    {
        if ($serialId === null) {
            return;
        }

        $columns = ['status_id' => $this->inventoryStatusId($statusName)];

        if ($makeReceivable) {
            $columns['serial_type'] = 'received';
        }

        ItemSerial::where('serial_id', $serialId)->update($columns);
    }

    /** @var array<string,int> */
    private static array $statusCache = [];

    /** Resolved by NAME — these ids differ between the dev and test databases. */
    private function inventoryStatusId(string $name): int
    {
        if (isset(self::$statusCache[$name])) {
            return self::$statusCache[$name];
        }

        $id = DB::table('status_lookup')
            ->where('status_category', 'inventory')
            ->where('status_name', $name)
            ->value('status_id');

        if ($id === null) {
            throw new \RuntimeException("Unknown inventory status: {$name}. Run IncidentStatusSeeder.");
        }

        return self::$statusCache[$name] = (int) $id;
    }
}
