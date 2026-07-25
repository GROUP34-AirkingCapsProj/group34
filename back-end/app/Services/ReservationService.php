<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\PickReservation;
use Illuminate\Support\Facades\DB;

/**
 * Sole writer of pick_reservations. A reservation is a ROW, never a column
 * mutation. Availability is DERIVED — inventory.available_quantity minus the sum
 * of active reservations — so a hold never touches available_quantity, and an
 * expired hold frees its stock automatically by falling out of active().
 */
class ReservationService
{
    /** How long a confirmed quote holds its stock before the hold lapses. */
    public const TTL_MINUTES = 240;

    /**
     * Reserve stock for a source document by inserting rows. Locks each product's
     * inventory row to serialize concurrent confirms, then enforces the invariant
     * that total active reservations never exceed on-hand available_quantity —
     * counting holds already added in this same call, so sibling lines of one
     * product cannot together overshoot. Throws when a line cannot be covered.
     *
     * @param  array<int,array{product_id:int,quantity:int,batch_id?:int|null,serial_id?:int|null}>  $lines
     */
    public function reserve(string $sourceType, int $sourceId, int $locationId, array $lines): void
    {
        // Acquire inventory-row locks in a canonical order (ascending product_id)
        // so two multi-product orders can never lock the same pair in opposite
        // order and deadlock. Without this, order X (products 3 then 5) and order
        // Y (5 then 3) can each hold one lock and wait on the other.
        usort($lines, fn ($a, $b) => (int) $a['product_id'] <=> (int) $b['product_id']);

        DB::transaction(function () use ($sourceType, $sourceId, $locationId, $lines) {
            foreach ($lines as $line) {
                $productId = (int) $line['product_id'];
                $quantity  = (int) $line['quantity'];
                if ($quantity <= 0) {
                    continue;
                }

                // Lock the inventory row first (always this order) to serialize
                // concurrent confirms on the product.
                $onHand = (int) $this->lockInventory($locationId, $productId)->available_quantity;

                // Locking read: sees holds another confirm just committed, and any
                // sibling line added earlier in this same loop.
                $held = (int) PickReservation::active()
                    ->where('location_id', $locationId)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->sum('quantity');

                if ($held + $quantity > $onHand) {
                    throw new \RuntimeException(
                        "Insufficient available stock for product {$productId}: "
                        . 'requested ' . $quantity . ', available ' . max(0, $onHand - $held) . '.'
                    );
                }

                PickReservation::create([
                    'source_type' => $sourceType,
                    'source_id'   => $sourceId,
                    'product_id'  => $productId,
                    'location_id' => $locationId,
                    'batch_id'    => $line['batch_id'] ?? null,
                    'serial_id'   => $line['serial_id'] ?? null,
                    'quantity'    => $quantity,
                    'expires_at'  => now()->addMinutes(self::TTL_MINUTES),
                ]);
            }
        });
    }

    /**
     * End every live hold for a source document by stamping released_at. Nothing
     * is credited back — availability is derived, so the instant a hold leaves
     * active() its stock is visible again. This is the ONLY terminal operation:
     * both completing and voiding an order call it. On a completed order the scan
     * path already moved the real quantity, so there is nothing extra to settle.
     */
    public function releaseFor(string $sourceType, int $sourceId): void
    {
        PickReservation::active()
            ->forSource($sourceType, $sourceId)
            ->update(['released_at' => now()]);
    }

    private function lockInventory(int $locationId, int $productId): Inventory
    {
        // firstOrCreate's existence check is non-locking, so two concurrent
        // confirms for a product/location with no inventory row can both try to
        // insert; the loser hits the unique (location_id, product_id) constraint.
        // Swallow that — the row now exists — and fall through to the locking read.
        try {
            Inventory::firstOrCreate(
                ['location_id' => $locationId, 'product_id' => $productId],
                ['quantity_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0]
            );
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Another transaction created it first; nothing to do.
        }

        return Inventory::where('location_id', $locationId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();
    }
}
