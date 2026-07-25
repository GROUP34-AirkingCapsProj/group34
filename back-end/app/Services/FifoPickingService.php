<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Traits\NormalizesBarcode;
use Illuminate\Support\Carbon;

/**
 * The single source of truth for FIFO order across every picking flow.
 * Stateless: it reads inventory and returns verdicts, and writes nothing.
 * Issuance picking and transfer dispatch both go through here so the rule
 * cannot drift between them.
 */
class FifoPickingService
{
    use NormalizesBarcode;

    /**
     * Oldest-first pick steps for a set of lines at one location.
     *
     * @param  array<int,array{product_id:int,quantity:int}>  $lines
     * @return array<int,array{product_id:int,product_name:?string,needed:int,suggestions:array}>
     */
    public function suggest(int $locationId, array $lines, array $excludedBatchIds = []): array
    {
        $out = [];

        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            $needed    = max(0, (int) $line['quantity']);
            $product   = Product::find($productId);

            $out[] = [
                'product_id'   => $productId,
                'product_name' => $product?->product_name,
                'needed'       => $needed,
                'suggestions'  => $this->isSerialized($product)
                    ? $this->serialSteps($locationId, $productId, $needed, $excludedBatchIds)
                    : $this->batchSteps($locationId, $productId, $needed, $excludedBatchIds),
            ];
        }

        return $out;
    }

    /** Batches with stock left, oldest first, excluding anything under an open incident. */
    public function orderedBatches(int $locationId, int $productId, array $excludedBatchIds = [])
    {
        return InventoryBatch::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('quantity_remaining', '>', 0)
            ->when($excludedBatchIds !== [], fn ($q) => $q->whereNotIn('batch_id', $excludedBatchIds))
            ->orderBy('received_at', 'asc')
            ->orderBy('batch_id', 'asc')
            ->get();
    }

    protected function isSerialized(?Product $product): bool
    {
        if ($product === null) {
            return false;
        }

        if ($product->product_type === 'appliance') {
            return true;
        }

        return (bool) ($product->applianceDetail?->is_serialized ?? false);
    }

    protected function batchSteps(int $locationId, int $productId, int $needed, array $excluded): array
    {
        $steps     = [];
        $remaining = $needed;

        foreach ($this->orderedBatches($locationId, $productId, $excluded) as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (int) $batch->quantity_remaining);

            $steps[] = [
                'serial_number' => null,
                'serial_id'     => null,
                'batch_id'      => (int) $batch->batch_id,
                'zone_label'    => $batch->zone_label,
                'received_at'   => optional($batch->received_at)->toDateTimeString(),
                'quantity'      => $take,
            ];

            $remaining -= $take;
        }

        return $steps;
    }

    protected function serialSteps(int $locationId, int $productId, int $needed, array $excluded): array
    {
        if ($needed <= 0) {
            return [];
        }

        // item_serial has no batch_id; the batch is reached through receiving_id,
        // scoped to this location — the same join TransferService::pickList() used.
        $rows = ItemSerial::where('item_serial.product_id', $productId)
            ->where('item_serial.location_id', $locationId)
            ->where('item_serial.serial_type', 'received')
            ->whereNull('item_serial.transfer_id')
            ->leftJoin('inventory_batches as b', function ($join) use ($locationId) {
                $join->on('b.receiving_id', '=', 'item_serial.receiving_id')
                     ->where('b.location_id', '=', $locationId);
            })
            ->when($excluded !== [], fn ($q) => $q->where(function ($w) use ($excluded) {
                $w->whereNull('b.batch_id')->orWhereNotIn('b.batch_id', $excluded);
            }))
            ->orderByRaw('b.received_at IS NULL, b.received_at ASC')
            ->orderBy('item_serial.serial_id')
            ->limit($needed)
            ->get([
                'item_serial.serial_id',
                'item_serial.serial_number',
                'b.batch_id',
                'b.zone_label',
                'b.received_at',
            ]);

        return $rows->map(fn ($row) => [
            'serial_number' => $row->serial_number,
            'serial_id'     => (int) $row->serial_id,
            'batch_id'      => $row->batch_id !== null ? (int) $row->batch_id : null,
            'zone_label'    => $row->zone_label,
            // b.received_at comes back as a raw string from the joined table, not
            // through the InventoryBatch cast. Normalise it to the same
            // 'Y-m-d H:i:s' shape batchSteps() emits so a consumer can compare
            // received_at across serial and consumable steps without surprises.
            'received_at'   => $row->received_at !== null
                ? Carbon::parse($row->received_at)->toDateTimeString()
                : null,
            'quantity'      => 1,
        ])->all();
    }
}
