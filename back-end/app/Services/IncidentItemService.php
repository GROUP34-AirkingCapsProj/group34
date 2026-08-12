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
                $created[] = IncidentItem::create([
                    'incident_id'   => (int) $incident->incident_id,
                    'product_id'    => (int) $row['product_id'],
                    'serial_id'     => $row['serial_id'] ?? null,
                    'batch_id'      => $row['batch_id'] ?? null,
                    'unit_role'     => $row['unit_role'] ?? IncidentItem::ROLE_SINGLE,
                    'model_code'    => $row['model_code'] ?? null,
                    'serial_number' => $row['serial_number'] ?? null,
                    'quantity'      => max(1, (int) $row['quantity']),
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

            foreach ($items as $item) {
                if ($item->status !== IncidentItem::STATUS_QUARANTINED) {
                    continue;
                }

                // Only the outcomes where the goods physically leave drop the
                // count — and because quarantine never deducted, this is the
                // single deduction for the whole lifecycle. The old check was
                // "anything that isn't restock is a departure", which would
                // delete a unit sitting on the bench under repair or awaiting a
                // warranty claim.
                if (in_array($item->outcome, IncidentItem::OUTCOMES_THAT_REMOVE_STOCK, true)) {
                    $this->deduct($item);
                }

                $status = IncidentItem::OUTCOME_STATUS[$item->outcome];

                $item->update(['status' => $status]);

                $this->stampSerial(
                    $item->serial_id,
                    self::SERIAL_STATUS_NAME[$status],
                    makeReceivable: $status === IncidentItem::STATUS_AVAILABLE,
                );
            }
        });
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

    private function deduct(IncidentItem $item): void
    {
        $quantity = (int) $item->quantity;

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
            }
        }

        $inventory = Inventory::where('location_id', $item->incident->location_id)
            ->where('product_id', $item->product_id)
            ->lockForUpdate()
            ->first();

        if ($inventory !== null) {
            $inventory->decrement('quantity_on_hand', min($quantity, (int) $inventory->quantity_on_hand));
            $inventory->decrement('available_quantity', min($quantity, (int) $inventory->available_quantity));
        }
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
