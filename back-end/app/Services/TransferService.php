<?php

namespace App\Services;

use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\Inventory;
use App\Models\ItemSerial;
use App\Models\BarcodeScan;
use App\Models\TblTransferTracking;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransferService
{
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
     * Approved IST -> In Transit. shipped = quantity_scanned (auto-close remainder).
     * Deducts source quantity_on_hand AND available_quantity for every product type.
     * Serialized scanned serials become 'transferred' (location stays at source).
     * The header row is locked inside the transaction so concurrent dispatches
     * serialize and the second caller aborts on the re-checked status.
     */
    public function dispatch(Transfer $ist): Transfer
    {
        if ((int) $ist->status_id !== $this->statusId('Approved')) {
            throw new RuntimeException('Only an approved IST can be dispatched.');
        }

        return DB::transaction(function () use ($ist) {
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
                }

                $detail->quantity_transferred = $shipped;
                $detail->save();
                $totalShipped += $shipped;
            }

            $locked->status_id = $this->statusId('In Transit');
            $locked->total_quantity_transferred = $totalShipped;
            $locked->save();

            return $locked->fresh('details');
        });
    }

    /**
     * Record one received unit (scan or manual). Increments the line's
     * quantity_received and writes a barcode_scans row (scan_mode 'receive').
     * Serialized scans must match an in-transit serial of THIS transfer.
     */
    public function recordReceiveScan(Transfer $ist, string $barcode, int $transferDetailId, int $userId, int $qty = 1): array
    {
        if ((int) $ist->status_id !== $this->statusId('In Transit')) {
            throw new RuntimeException('Only an In Transit IST can be received.');
        }

        $detail = TransferDetail::where('transfer_detail_id', $transferDetailId)
            ->where('transfer_id', $ist->transfer_id)
            ->with('product')
            ->first();
        if (!$detail) {
            throw new RuntimeException('Line item does not belong to this transfer.');
        }

        $serialized = $this->isSerialized($detail);
        $step = $serialized ? 1 : max(1, $qty);

        return DB::transaction(function () use ($ist, $detail, $barcode, $userId, $step, $serialized) {
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
                'scanned_at'  => now(),
            ]);

            $detail->quantity_received = (int) $detail->quantity_received + $step;
            $detail->save();

            return [
                'scan_id'           => $scan->id,
                'quantity_received' => (int) $detail->quantity_received,
                'quantity_shipped'  => $shipped,
                'remaining'         => $shipped - (int) $detail->quantity_received,
            ];
        });
    }

    /** Undo a received-scan before confirmation. */
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

            $detail = TransferDetail::where('transfer_id', $ist->transfer_id)
                ->where('product_id', $scan->product_id)
                ->first();
            if ($detail && (int) $detail->quantity_received > 0) {
                $detail->quantity_received = (int) $detail->quantity_received - 1;
                $detail->save();
            }

            $scan->delete();
        });
    }
}
