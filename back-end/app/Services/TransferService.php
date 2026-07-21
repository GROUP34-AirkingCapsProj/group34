<?php

namespace App\Services;

use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\BarcodeScan;
use App\Models\TblTransferTracking;
use App\Services\InventoryBatchService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransferService
{
    use \App\Traits\NormalizesBarcode;

    private InventoryBatchService $batches;

    public function __construct(?InventoryBatchService $batches = null)
    {
        $this->batches = $batches ?? new InventoryBatchService();
    }

    private function statusId(string $name): int
    {
        $id = DB::table('status_lookup')
            ->where('status_category', 'transfer')
            ->where('status_name', $name)
            ->value('status_id');

        if ($id === null) {
            throw new RuntimeException("Transfer status '{$name}' is missing from status_lookup.");
        }
        return (int) $id;
    }

    /** Test seam — exposes the private resolver. */
    public function statusIdForTest(string $name): int
    {
        return $this->statusId($name);
    }

    private function isSerialized(?TransferDetail $detail): bool
    {
        return ($detail?->product?->product_type) === 'appliance';
    }

    /**
     * FIFO pick suggestions per line for an Approved IST.
     * Appliances: individual serials tagged with their batch zone, oldest batch first.
     * Consumables: zone + quantity per batch, oldest received_at first, capped at outstanding qty.
     *
     * @return array<int, array{product_id:int, product_name:string, needed:int, suggestions:array}>
     */
    public function pickList(Transfer $ist): array
    {
        $ist->loadMissing('details.product');
        $out = [];

        foreach ($ist->details as $detail) {
            $needed = (int) $detail->quantity_requested - (int) $detail->quantity_scanned;
            $suggestions = [];

            if ($this->isSerialized($detail)) {
                $serials = ItemSerial::where('item_serial.product_id', $detail->product_id)
                    ->where('item_serial.location_id', $ist->from_location_id)
                    ->where('item_serial.serial_type', 'received')
                    ->whereNull('item_serial.transfer_id')
                    ->leftJoin('inventory_batches as b', function ($j) use ($ist) {
                        $j->on('b.receiving_id', '=', 'item_serial.receiving_id')
                          ->where('b.location_id', '=', $ist->from_location_id);
                    })
                    ->orderByRaw('b.received_at IS NULL, b.received_at ASC')
                    ->limit(max(0, $needed))
                    ->get(['item_serial.serial_number', 'item_serial.receiving_id', 'b.zone_label', 'b.received_at', 'b.batch_id']);

                foreach ($serials as $s) {
                    $suggestions[] = [
                        'serial_number' => $s->serial_number,
                        'zone_label'    => $s->zone_label,
                        'quantity'      => 1,
                        'batch_id'      => $s->batch_id !== null ? (int) $s->batch_id : null,
                        'received_at'   => $s->received_at,
                    ];
                }
            } else {
                $remaining = max(0, $needed);
                $batches = InventoryBatch::where('product_id', $detail->product_id)
                    ->where('location_id', $ist->from_location_id)
                    ->where('quantity_remaining', '>', 0)
                    ->orderBy('received_at', 'asc')
                    ->get();

                foreach ($batches as $batch) {
                    if ($remaining <= 0) break;
                    $take = min($remaining, (int) $batch->quantity_remaining);
                    $suggestions[] = [
                        'serial_number' => null,
                        'zone_label'    => $batch->zone_label,
                        'quantity'      => $take,
                        'batch_id'      => (int) $batch->batch_id,
                        'received_at'   => $batch->received_at,
                    ];
                    $remaining -= $take;
                }
            }

            $out[] = [
                'product_id'   => (int) $detail->product_id,
                'product_name' => $detail->product?->product_name ?? "Product #{$detail->product_id}",
                'needed'       => $needed,
                'suggestions'  => $suggestions,
            ];
        }

        return $out;
    }

    /** Fetch the inventory row for a location/product under a write lock, creating it (zeroed) if absent. */
    private function lockInventory(int $locationId, int $productId): Inventory
    {
        Inventory::firstOrCreate(
            ['location_id' => $locationId, 'product_id' => $productId],
            ['quantity_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0]
        );

        return Inventory::where('location_id', $locationId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Approved IST -> In Transit. shipped = quantity_scanned (auto-close remainder).
     * Deducts source quantity_on_hand AND available_quantity for every product type.
     * Serialized scanned serials become 'transferred' (location stays at source).
     * The header row is locked inside the transaction so concurrent dispatches
     * serialize and the second caller aborts on the re-checked status.
     */
    public function dispatch(Transfer $ist, ?int $userId = null): Transfer
    {
        if ((int) $ist->status_id !== $this->statusId('Approved')) {
            throw new RuntimeException('Only an approved IST can be dispatched.');
        }

        return DB::transaction(function () use ($ist, $userId) {
            $locked = Transfer::where('transfer_id', $ist->transfer_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->status_id !== $this->statusId('Approved')) {
                throw new RuntimeException('Only an approved IST can be dispatched.');
            }

            $locked->load('details.product');
            $totalScanned = (int) $locked->details->sum('quantity_scanned');
            if ($totalScanned < 1) {
                throw new RuntimeException('Scan at least one item before dispatch.');
            }

            $totalShipped = 0;

            foreach ($locked->details as $detail) {
                $shipped = (int) $detail->quantity_scanned;
                if ($shipped < 1) {
                    continue;
                }

                $src = Inventory::where('location_id', $locked->from_location_id)
                    ->where('product_id', $detail->product_id)
                    ->lockForUpdate()
                    ->first();

                if (!$src || (int) $src->available_quantity < $shipped) {
                    $have = $src ? (int) $src->available_quantity : 0;
                    throw new RuntimeException("Only {$have} available at source for product {$detail->product_id}.");
                }

                $src->quantity_on_hand   = (int) $src->quantity_on_hand - $shipped;
                $src->available_quantity = (int) $src->available_quantity - $shipped;
                $src->save();

                if ($this->isSerialized($detail)) {
                    ItemSerial::where('transfer_id', $locked->transfer_id)
                        ->where('product_id', $detail->product_id)
                        ->where('serial_type', 'received')
                        ->update(['serial_type' => 'transferred']);

                    // Exact per-unit batch deduction: group the just-shipped serials by
                    // their known receiving_id and decrement each linked batch directly.
                    // Serials with no receiving_id (e.g. pre-batch-tracking stock) fall into
                    // a single group whose key Laravel's groupBy() casts to '' (Collection
                    // keys are always string|int, so a null group key is stringified). We
                    // coerce that '' back to null before calling deductFromReceiving(), which
                    // is typed ?int and would otherwise fail to cast '' to an int.
                    // receiving_id is an auto-increment PK (starts at 1) so it is never 0,
                    // making '' and null the only falsy values here — safe to use `?:`.
                    ItemSerial::where('transfer_id', $locked->transfer_id)
                        ->where('product_id', $detail->product_id)
                        ->where('serial_type', 'transferred')
                        ->get()
                        ->groupBy('receiving_id')
                        ->each(function ($group, $receivingId) use ($locked, $detail) {
                            $this->batches->deductFromReceiving(
                                $receivingId ? (int) $receivingId : null,
                                $detail->product_id,
                                $locked->from_location_id,
                                $group->count()
                            );
                        });
                } else {
                    $this->batches->deductFifo($detail->product_id, $locked->from_location_id, $shipped);
                }

                $detail->quantity_transferred = $shipped;
                $detail->save();
                $totalShipped += $shipped;
            }

            $locked->status_id = $this->statusId('In Transit');
            $locked->total_quantity_transferred = $totalShipped;
            $locked->save();

            if ($userId !== null) {
                TblTransferTracking::create([
                    'transfer_id' => $locked->transfer_id,
                    'location_id' => $locked->from_location_id,
                    'status_note' => "Items dispatched — {$totalShipped} unit(s)",
                    'recorded_by' => $userId,
                    'recorded_at' => now(),
                ]);
            }

            return $locked->fresh('details');
        });
    }

    /**
     * Record received unit(s) (scan or manual). Increments the line's
     * quantity_received by $step and writes a barcode_scans row
     * (scan_mode 'receive', quantity = $step) so removal can undo the exact amount.
     * Serialized scans are always 1 unit and must match an in-transit serial of THIS transfer.
     */
    public function recordReceiveScan(Transfer $ist, string $barcode, ?int $transferDetailId, int $userId, int $qty = 1): array
    {
        if ((int) $ist->status_id !== $this->statusId('In Transit')) {
            throw new RuntimeException('Only an In Transit IST can be received.');
        }

        return DB::transaction(function () use ($ist, $barcode, $transferDetailId, $userId, $qty) {
            if ($transferDetailId === null) {
                $code = trim($barcode);
                $match = ItemSerial::where('transfer_id', $ist->transfer_id)
                    ->where('serial_type', 'transferred')
                    ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($code)])
                    ->first();

                if ($match) {
                    $line = TransferDetail::where('transfer_id', $ist->transfer_id)
                        ->where('product_id', $match->product_id)
                        ->first();
                } else {
                    // Consumable fallback: resolve the barcode to a product on this transfer.
                    $product = $this->findProductByBarcodeCandidates($this->barcodeLookupCandidates($code));
                    $line = $product
                        ? TransferDetail::where('transfer_id', $ist->transfer_id)
                            ->where('product_id', $product->product_id)
                            ->first()
                        : null;
                }

                if (!$line) {
                    throw new RuntimeException('This barcode is not part of this shipment.');
                }
                $transferDetailId = (int) $line->transfer_detail_id;
            }

            $detail = TransferDetail::where('transfer_detail_id', $transferDetailId)
                ->where('transfer_id', $ist->transfer_id)
                ->with('product')
                ->lockForUpdate()
                ->first();
            if (!$detail) {
                throw new RuntimeException('Line item does not belong to this transfer.');
            }

            $serialized = $this->isSerialized($detail);
            $step = $serialized ? 1 : max(1, $qty);

            $alreadyScanned = BarcodeScan::where('transfer_id', $ist->transfer_id)
                ->where('scan_mode', 'receive')
                ->where('barcode', $barcode)
                ->exists();
            if ($alreadyScanned) {
                throw new RuntimeException("Barcode '{$barcode}' was already received on this transfer.");
            }

            if ($serialized) {
                $match = ItemSerial::where('transfer_id', $ist->transfer_id)
                    ->where('product_id', $detail->product_id)
                    ->where('serial_number', $barcode)
                    ->where('serial_type', 'transferred')
                    ->exists();
                if (!$match) {
                    throw new RuntimeException('This serial is not part of this shipment.');
                }
            }

            $shipped = (int) $detail->quantity_transferred;
            if ((int) $detail->quantity_received + $step > $shipped) {
                $remaining = $shipped - (int) $detail->quantity_received;
                throw new RuntimeException("Only {$remaining} more unit(s) can be received on this line.");
            }

            $scan = BarcodeScan::create([
                'user_id'     => $userId,
                'product_id'  => $detail->product_id,
                'transfer_id' => $ist->transfer_id,
                'barcode'     => $barcode,
                'scan_mode'   => 'receive',
                'quantity'    => $step,
                'scanned_at'  => now(),
            ]);

            $detail->quantity_received = (int) $detail->quantity_received + $step;
            $detail->save();

            return [
                'scan_id'            => $scan->id,
                'transfer_detail_id' => (int) $detail->transfer_detail_id,
                'quantity_received'  => (int) $detail->quantity_received,
                'quantity_shipped'   => $shipped,
                'remaining'          => $shipped - (int) $detail->quantity_received,
            ];
        });
    }

    /**
     * In Transit -> Received. Finalizes whatever was recorded via recordReceiveScan.
     * Increments destination inventory (both columns), activates matched serials
     * at the destination, logs any shipped != received discrepancy.
     */
    public function receive(Transfer $ist, int $userId): Transfer
    {
        if ((int) $ist->status_id !== $this->statusId('In Transit')) {
            throw new RuntimeException('Only an In Transit IST can be received.');
        }

        return DB::transaction(function () use ($ist, $userId) {
            $locked = Transfer::where('transfer_id', $ist->transfer_id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $locked->status_id !== $this->statusId('In Transit')) {
                throw new RuntimeException('Only an In Transit IST can be received.');
            }

            $locked->load('details.product');
            $totalReceived = 0;
            $discrepancies = [];

            foreach ($locked->details as $detail) {
                $received = (int) $detail->quantity_received;
                $shipped  = (int) $detail->quantity_transferred;

                if ($received > 0) {
                    $dst = $this->lockInventory($locked->to_location_id, $detail->product_id);
                    $dst->quantity_on_hand   = (int) $dst->quantity_on_hand + $received;
                    $dst->available_quantity = (int) $dst->available_quantity + $received;
                    $dst->save();

                    if ($this->isSerialized($detail)) {
                        $scanned = BarcodeScan::where('transfer_id', $locked->transfer_id)
                            ->where('scan_mode', 'receive')
                            ->where('product_id', $detail->product_id)
                            ->pluck('barcode');
                        ItemSerial::where('transfer_id', $locked->transfer_id)
                            ->where('product_id', $detail->product_id)
                            ->whereIn('serial_number', $scanned)
                            ->update([
                                'serial_type' => 'received',
                                'location_id' => $locked->to_location_id,
                            ]);
                    }
                }

                if ($received !== $shipped) {
                    $discrepancies[] = "product {$detail->product_id}: shipped {$shipped}, received {$received}";
                }

                $totalReceived += $received;
            }

            $locked->status_id = $this->statusId('Received');
            $locked->received_by = $userId;
            $locked->total_quantity_received = $totalReceived;
            $locked->save();

            $note = "Items received — {$totalReceived} unit(s)";
            if (!empty($discrepancies)) {
                $note .= '. Discrepancies: ' . implode('; ', $discrepancies);
            }
            TblTransferTracking::create([
                'transfer_id' => $locked->transfer_id,
                'location_id' => $locked->to_location_id,
                'status_note' => $note,
                'recorded_by' => $userId,
                'recorded_at' => now(),
            ]);

            return $locked->fresh('details');
        });
    }

    /**
     * State-aware undo of an IST's inventory/serial movement.
     * - In Transit: restore source, reactivate serials at source, clear transfer link.
     * - Received: also pull received qty out of destination and move serials back.
     * Does not change the transfer status; callers handle that.
     *
     * The header row is locked and re-read inside the transaction so concurrent
     * reverses serialize (the second sees the already-reversed state via the caller's
     * status change, or operates on a consistent snapshot).
     *
     * Note: receive() intentionally leaves item_serial.transfer_id set, which is how
     * the serial reset below still matches destination-activated serials after receipt.
     * Destination decrements are floored at 0 on purpose: stock received earlier may have
     * been legitimately consumed at the destination before this reversal runs.
     */
    public function reverse(Transfer $ist): void
    {
        $inTransit = $this->statusId('In Transit');
        $received  = $this->statusId('Received');

        DB::transaction(function () use ($ist, $inTransit, $received) {
            $locked = Transfer::where('transfer_id', $ist->transfer_id)
                ->lockForUpdate()
                ->firstOrFail();

            $state = (int) $locked->status_id;
            if (!in_array($state, [$inTransit, $received], true)) {
                return; // nothing moved yet (Pending/Approved/Rejected)
            }

            $locked->load('details.product');

            foreach ($locked->details as $detail) {
                $shipped = (int) $detail->quantity_transferred;
                $recv    = (int) $detail->quantity_received;
                if ($shipped < 1) {
                    continue;
                }

                if ($state === $received && $recv > 0) {
                    $dst = Inventory::where('location_id', $locked->to_location_id)
                        ->where('product_id', $detail->product_id)
                        ->lockForUpdate()
                        ->first();
                    if ($dst) {
                        $dst->quantity_on_hand   = max(0, (int) $dst->quantity_on_hand - $recv);
                        $dst->available_quantity = max(0, (int) $dst->available_quantity - $recv);
                        $dst->save();
                    }
                }

                $src = $this->lockInventory($locked->from_location_id, $detail->product_id);
                $src->quantity_on_hand   = (int) $src->quantity_on_hand + $shipped;
                $src->available_quantity = (int) $src->available_quantity + $shipped;
                $src->save();

                if ($this->isSerialized($detail)) {
                    // Read receiving_id groupings BEFORE clearing transfer_id, so the
                    // exact batch each serial came from can still be identified.
                    ItemSerial::where('transfer_id', $locked->transfer_id)
                        ->where('product_id', $detail->product_id)
                        ->get()
                        ->groupBy('receiving_id')
                        ->each(function ($group, $receivingId) use ($locked, $detail) {
                            $this->batches->restoreFifo(
                                $detail->product_id,
                                $locked->from_location_id,
                                $group->count(),
                                $receivingId ? (int) $receivingId : null
                            );
                        });

                    ItemSerial::where('transfer_id', $locked->transfer_id)
                        ->where('product_id', $detail->product_id)
                        ->update([
                            'serial_type' => 'received',
                            'location_id' => $locked->from_location_id,
                            'transfer_id' => null,
                        ]);
                } else {
                    // Bulk products have no per-transfer batch trail, so restoration is
                    // best-effort: it goes to the legacy catch-all batch rather than the
                    // exact original batch. See plan header for rationale.
                    $this->batches->restoreFifo($detail->product_id, $locked->from_location_id, $shipped, null);
                }
            }
        });
    }

    /**
     * Admin-only express transfer: no request/approval, no manual scans.
     * Builds an Approved transfer, simulates scan+receipt, then runs dispatch()+receive().
     * Serialized lines require explicit serials (entered by the admin).
     *
     * @param array $lines each: ['product_id'=>int, 'quantity'=>int, 'serials'=>string[]]
     */
    public function directOverride(int $fromId, int $toId, array $lines, int $userId, string $date): Transfer
    {
        return DB::transaction(function () use ($fromId, $toId, $lines, $userId, $date) {
            $transfer = Transfer::create([
                'transfer_request_id'        => null,
                'from_location_id'           => $fromId,
                'to_location_id'             => $toId,
                'transfer_number'            => 'OVR-PENDING-' . uniqid('', true),
                'transfer_date'              => $date,
                'requested_by'               => $userId,
                'approved_by'                => $userId,
                'status_id'                  => $this->statusId('Approved'),
                'total_quantity_transferred' => 0,
                'total_quantity_received'    => 0,
            ]);
            $transfer->transfer_number = 'OVR-' . date('Ymd') . '-' . str_pad((string) $transfer->transfer_id, 4, '0', STR_PAD_LEFT);
            $transfer->save();

            foreach ($lines as $line) {
                $product = \App\Models\Product::find($line['product_id']);
                $qty = (int) $line['quantity'];
                $isAppliance = ($product?->product_type === 'appliance');

                if ($isAppliance) {
                    $serials = $line['serials'] ?? [];
                    if (count($serials) !== $qty) {
                        throw new RuntimeException("Serialized product {$line['product_id']} requires {$qty} serial number(s).");
                    }

                    $matched = ItemSerial::whereIn('serial_number', $serials)
                        ->where('product_id', $line['product_id'])
                        ->where('location_id', $fromId)
                        ->count();
                    if ($matched !== count($serials)) {
                        throw new RuntimeException("One or more serial numbers for product {$line['product_id']} were not found at the source location.");
                    }

                    ItemSerial::whereIn('serial_number', $serials)
                        ->where('product_id', $line['product_id'])
                        ->where('location_id', $fromId)
                        ->update(['transfer_id' => $transfer->transfer_id]);

                    foreach ($serials as $sn) {
                        BarcodeScan::create([
                            'user_id'     => $userId,
                            'product_id'  => $line['product_id'],
                            'transfer_id' => $transfer->transfer_id,
                            'barcode'     => $sn,
                            'scan_mode'   => 'receive',
                            'quantity'    => 1,
                            'scanned_at'  => now(),
                        ]);
                    }
                }

                $transfer->details()->create([
                    'product_id'           => $line['product_id'],
                    'quantity_requested'   => $qty,
                    'quantity_scanned'     => $qty,
                    'quantity_transferred' => 0,
                    'quantity_received'    => $qty,
                ]);
            }

            $transfer = $this->dispatch($transfer->fresh('details'), $userId);
            return $this->receive($transfer->fresh('details'), $userId);
        });
    }

    /**
     * Resolve a scanned barcode to a line on this Approved IST and record one
     * dispatch scan, enforcing integrity gates:
     *  - serialized: the serial must be at from_location, in stock
     *    (serial_type='received'), free of another transfer, and belong to a
     *    product on this IST (which also auto-routes it to the line).
     *  - consumable: barcode resolves to a product that is on this IST.
     * Duplicate barcodes on this IST are rejected. Returns the resolved line +
     * running totals. $transferDetailId may be passed by the manual per-line UI;
     * when null it is resolved here.
     */
    public function recordDispatchScan(Transfer $ist, string $barcode, ?int $transferDetailId, int $userId, int $qty = 1): array
    {
        if ((int) $ist->status_id !== $this->statusId('Approved')) {
            throw new RuntimeException('Only an approved IST can be scanned for dispatch.');
        }

        return DB::transaction(function () use ($ist, $barcode, $transferDetailId, $userId, $qty) {
            $ist->loadMissing('details.product');
            $code = trim($barcode);
            if ($code === '') {
                throw new RuntimeException('Enter a barcode or serial number.');
            }

            // Duplicate guard (same barcode already scanned on this IST).
            $dupe = BarcodeScan::where('transfer_id', $ist->transfer_id)
                ->where('scan_mode', 'transfer')
                ->where('barcode', $code)
                ->exists();
            if ($dupe) {
                throw new RuntimeException("Barcode '{$code}' has already been scanned for this IST.");
            }

            // Resolve which serial (if any) this code is, and which line it belongs to.
            $istProductIds = $ist->details->pluck('product_id')->all();
            $serial = ItemSerial::whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($code)])
                ->whereIn('product_id', $istProductIds)
                ->first();

            if ($serial) {
                // Serialized path.
                $detail = $ist->details->firstWhere('product_id', $serial->product_id);
                if (!$detail) {
                    throw new RuntimeException('This serial is not part of this transfer.');
                }
                if ((int) $serial->location_id !== (int) $ist->from_location_id) {
                    throw new RuntimeException('This serial is not in stock at the source location.');
                }
                if ($serial->serial_type !== 'received') {
                    throw new RuntimeException('This serial has already been shipped out.');
                }
                if ($serial->transfer_id !== null && (int) $serial->transfer_id !== (int) $ist->transfer_id) {
                    throw new RuntimeException('This serial is committed to another transfer.');
                }
                $step = 1;
            } else {
                // Consumable path — resolve product by barcode candidates.
                $product = $this->findProductByBarcodeCandidates($this->barcodeLookupCandidates($code));
                if (!$product) {
                    throw new RuntimeException('Barcode not found.');
                }
                $detail = $ist->details->firstWhere('product_id', $product->product_id);
                if (!$detail) {
                    throw new RuntimeException('This product is not part of this transfer.');
                }
                if ($this->isSerialized($detail)) {
                    throw new RuntimeException('Scan the individual serial number for this appliance.');
                }
                $step = max(1, (int) $qty);
            }

            // If the caller named a line, it must match the resolved one.
            if ($transferDetailId !== null && (int) $transferDetailId !== (int) $detail->transfer_detail_id) {
                throw new RuntimeException('Scanned item does not match the selected line.');
            }

            // Re-read the resolved line under a write lock so concurrent scans serialize.
            $locked = TransferDetail::where('transfer_detail_id', $detail->transfer_detail_id)
                ->lockForUpdate()
                ->first();

            $target  = (int) $locked->quantity_requested;
            $current = (int) $locked->quantity_scanned;
            if ($current + $step > $target) {
                $remaining = $target - $current;
                throw new RuntimeException("Only {$remaining} more unit(s) needed for this product.");
            }

            $locked->increment('quantity_scanned', $step);
            $locked->refresh();

            $scan = BarcodeScan::create([
                'user_id'     => $userId,
                'product_id'  => $locked->product_id,
                'transfer_id' => $ist->transfer_id,
                'barcode'     => $code,
                'scan_mode'   => 'transfer',
                'quantity'    => $step,
                'scanned_at'  => now(),
            ]);

            if ($serial) {
                $serial->transfer_id = $ist->transfer_id;
                $serial->save();
            }

            return [
                'scan_id'            => $scan->id,
                'transfer_detail_id' => (int) $locked->transfer_detail_id,
                'product_name'       => $detail->product?->product_name ?? "Product #{$locked->product_id}",
                'total_scanned'      => (int) $locked->quantity_scanned,
                'quantity_requested' => $target,
                'remaining'          => $target - (int) $locked->quantity_scanned,
            ];
        });
    }

    /** Undo a dispatch scan before confirmation: decrement the line and free the serial. */
    public function removeDispatchScan(Transfer $ist, int $scanId): void
    {
        if ((int) $ist->status_id !== $this->statusId('Approved')) {
            throw new RuntimeException('Only an approved IST can be edited.');
        }

        DB::transaction(function () use ($ist, $scanId) {
            $scan = BarcodeScan::where('id', $scanId)
                ->where('transfer_id', $ist->transfer_id)
                ->where('scan_mode', 'transfer')
                ->first();
            if (!$scan) {
                throw new RuntimeException('Dispatch scan not found.');
            }

            $step = max(1, (int) ($scan->quantity ?? 1));
            $detail = TransferDetail::where('transfer_id', $ist->transfer_id)
                ->where('product_id', $scan->product_id)
                ->lockForUpdate()
                ->first();
            if ($detail) {
                $detail->quantity_scanned = max(0, (int) $detail->quantity_scanned - $step);
                $detail->save();
            }

            ItemSerial::where('transfer_id', $ist->transfer_id)
                ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($scan->barcode)])
                ->update(['transfer_id' => null]);

            $scan->delete();
        });
    }

    /** Undo a received-scan before confirmation (undoes the scan's recorded quantity). */
    public function removeReceiveScan(Transfer $ist, int $scanId): void
    {
        if ((int) $ist->status_id !== $this->statusId('In Transit')) {
            throw new RuntimeException('Only an In Transit IST can be edited.');
        }

        DB::transaction(function () use ($ist, $scanId) {
            $scan = BarcodeScan::where('id', $scanId)
                ->where('transfer_id', $ist->transfer_id)
                ->where('scan_mode', 'receive')
                ->first();
            if (!$scan) {
                throw new RuntimeException('Receive scan not found.');
            }

            $step = max(1, (int) ($scan->quantity ?? 1));

            $detail = TransferDetail::where('transfer_id', $ist->transfer_id)
                ->where('product_id', $scan->product_id)
                ->lockForUpdate()
                ->first();
            if ($detail) {
                $detail->quantity_received = max(0, (int) $detail->quantity_received - $step);
                $detail->save();
            }

            $scan->delete();
        });
    }
}
