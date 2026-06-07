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
     */
    public function dispatch(Transfer $ist): Transfer
    {
        if ((int) $ist->status_id !== $this->statusId('Approved')) {
            throw new RuntimeException('Only an approved IST can be dispatched.');
        }

        $ist->load('details.product');
        $totalScanned = (int) $ist->details->sum('quantity_scanned');
        if ($totalScanned < 1) {
            throw new RuntimeException('Scan at least one item before dispatch.');
        }

        return DB::transaction(function () use ($ist) {
            $totalShipped = 0;

            foreach ($ist->details as $detail) {
                $shipped = (int) $detail->quantity_scanned;
                if ($shipped < 1) {
                    continue;
                }

                $src = Inventory::where('location_id', $ist->from_location_id)
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
                    ItemSerial::where('transfer_id', $ist->transfer_id)
                        ->where('product_id', $detail->product_id)
                        ->update(['serial_type' => 'transferred']);
                }

                $detail->quantity_transferred = $shipped;
                $detail->save();
                $totalShipped += $shipped;
            }

            $ist->status_id = $this->statusId('In Transit');
            $ist->total_quantity_transferred = $totalShipped;
            $ist->save();

            return $ist->fresh('details');
        });
    }
}
