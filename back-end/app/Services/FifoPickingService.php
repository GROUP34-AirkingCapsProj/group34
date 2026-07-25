<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Support\ScanResult;
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

    /**
     * Judge one scan against a document's line list.
     *
     * @param  array<int,array{product_id:int,quantity:int,picked:int}>  $lines
     * @param  string|null  $confirmedZone  zone the picker confirmed (consumables)
     * @param  string|null  $unitHint       reserved for unit-aware picking; unused today
     * @param  array<int,int>  $excludedBatchIds  batches under an open incident
     */
    public function validateScan(
        int $locationId,
        array $lines,
        string $barcode,
        ?string $confirmedZone = null,
        ?string $unitHint = null,
        array $excludedBatchIds = []
    ): ScanResult {
        $resolved = $this->resolveScan($barcode, $locationId);
        $product  = $resolved['product'];
        $serial   = $resolved['serial'];

        if ($product === null) {
            return new ScanResult(
                status:  ScanResult::UNKNOWN_BARCODE,
                message: 'Barcode not recognised. Check the label, or that the item is stocked at this location.'
            );
        }

        $productId = (int) $product->product_id;
        $line      = collect($lines)->firstWhere('product_id', $productId);

        if ($line === null) {
            return new ScanResult(
                status:      ScanResult::WRONG_PRODUCT,
                message:     "{$product->product_name} is not on this order. Remove it and scan a listed item.",
                productId:   $productId,
                productName: $product->product_name
            );
        }

        if ((int) ($line['picked'] ?? 0) >= (int) $line['quantity']) {
            return new ScanResult(
                status:      ScanResult::OVER_PICK,
                message:     "{$product->product_name} is already fully picked for this order.",
                productId:   $productId,
                productName: $product->product_name
            );
        }

        $oldest = $this->orderedBatches($locationId, $productId, $excludedBatchIds)->first();

        if ($oldest === null) {
            // orderedBatches() is empty for two very different reasons, and only
            // the first may be waved through:
            //   (a) the product has NO inventory_batches rows at all — legacy
            //       stock predating batch tracking, with nothing to order against.
            //   (b) batches exist but none are pickable: all depleted, or all
            //       under an open incident (excludedBatchIds). Accepting here
            //       would silently pick quarantined or phantom stock — the exact
            //       hole this engine exists to close — so it must be blocked.
            $hasAnyBatch = InventoryBatch::where('product_id', $productId)
                ->where('location_id', $locationId)
                ->exists();

            if (! $hasAnyBatch) {
                return new ScanResult(
                    status:       ScanResult::ACCEPTED,
                    message:      'Accepted.',
                    productId:    $productId,
                    productName:  $product->product_name,
                    serialId:     $serial !== null ? (int) $serial->serial_id : null,
                    serialNumber: $serial?->serial_number
                );
            }

            return new ScanResult(
                status:      ScanResult::FIFO_VIOLATION,
                message:     "No available stock of {$product->product_name} is recorded here — "
                             . 'its batches are depleted or under review. File an Incident Report to proceed.',
                productId:   $productId,
                productName: $product->product_name,
                serialId:    $serial !== null ? (int) $serial->serial_id : null,
                serialNumber: $serial?->serial_number
            );
        }

        $scannedBatch = $serial !== null
            ? $this->batchForSerial($locationId, $serial)
            : $this->batchForZone($locationId, $productId, $confirmedZone, $excludedBatchIds);

        // When the scan maps to no batch, only ONE case is trusted: a consumable
        // scanned without a zone (the picker is trusted to take the plan's oldest).
        // A serial with no batch linkage, or a confirmed zone matching no batch,
        // is a violation while older pickable stock exists — we cannot confirm the
        // scan is that oldest stock, so it must not slip past FIFO.
        $isOldest = $scannedBatch === null
            ? ($serial === null && $confirmedZone === null)
            : (int) $scannedBatch->batch_id === (int) $oldest->batch_id;

        if (! $isOldest) {
            $expectedZone = $oldest->zone_label ?? 'the oldest batch';

            return new ScanResult(
                status:  ScanResult::FIFO_VIOLATION,
                message: "Oldest stock of {$product->product_name} is in {$expectedZone}"
                         . ($oldest->received_at ? ' (received ' . $oldest->received_at->format('M j, Y') . ')' : '')
                         . '. File an Incident Report to pick from elsewhere.',
                productId:          $productId,
                productName:        $product->product_name,
                serialId:           $serial !== null ? (int) $serial->serial_id : null,
                serialNumber:       $serial?->serial_number,
                batchId:            $scannedBatch !== null ? (int) $scannedBatch->batch_id : null,
                zoneLabel:          $scannedBatch?->zone_label ?? $confirmedZone,
                expectedBatchId:    (int) $oldest->batch_id,
                expectedZoneLabel:  $oldest->zone_label,
                expectedReceivedAt: optional($oldest->received_at)->toDateTimeString()
            );
        }

        return new ScanResult(
            status:       ScanResult::ACCEPTED,
            message:      'Accepted.',
            productId:    $productId,
            productName:  $product->product_name,
            serialId:     $serial !== null ? (int) $serial->serial_id : null,
            serialNumber: $serial?->serial_number,
            batchId:      (int) $oldest->batch_id,
            zoneLabel:    $oldest->zone_label
        );
    }

    protected function batchForSerial(int $locationId, ItemSerial $serial): ?InventoryBatch
    {
        if ($serial->receiving_id === null) {
            return null;
        }

        return InventoryBatch::where('location_id', $locationId)
            ->where('receiving_id', $serial->receiving_id)
            ->where('product_id', $serial->product_id)
            ->first();
    }

    protected function batchForZone(int $locationId, int $productId, ?string $zone, array $excluded): ?InventoryBatch
    {
        if ($zone === null) {
            return null;
        }

        return $this->orderedBatches($locationId, $productId, $excluded)
            ->first(fn ($batch) => strcasecmp(trim((string) $batch->zone_label), trim($zone)) === 0);
    }
}
