<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\PurchaseOrderDetail;
use App\Models\Receiving;
use App\Models\ReceivingDetail;

/**
 * The single shared write path for receiving stock, regardless of whether the
 * pieces arrived via a barcode scan or a manual count. Both entry paths must
 * move stock identically — this service is where that guarantee lives.
 *
 * TRANSACTION CONTRACT: this service does NOT open a transaction. Callers
 * must already be inside one holding a lockForUpdate() on the Inventory row
 * for the product+location being touched, exactly like InventoryBatchService
 * and MaterialTagService require.
 */
class ReceivingPostingService
{
    public function __construct(
        private InventoryBatchService $batches,
        private MaterialTagService $tags
    ) {
    }

    /**
     * @param  string  $entryMode  'scanned' | 'counted'
     * @return array{receiving: Receiving, batch: InventoryBatch, pieces: int, tags: ItemSerial[]}
     */
    public function post(
        Product $product,
        int $locationId,
        int $pieces,
        string $entryMode,
        ?PurchaseOrderDetail $poDetail = null,
        ?string $supplierBarcode = null,
        ?string $zoneLabel = null,
        ?int $userId = null,
        int $unitsEntered = 1
    ): array {
        $piecesPerPackage = $product->isSerialized() ? 0 : $product->getPiecesPerPackage();

        $inventory = Inventory::firstOrCreate(
            ['location_id' => $locationId, 'product_id' => $product->product_id],
            [
                'quantity_on_hand'   => 0,
                'packages_on_hand'   => 0,
                'available_quantity' => 0,
                'reorder_level'      => 0,
            ]
        );
        $inventory->setPieces((int) $inventory->quantity_on_hand + $pieces, $piecesPerPackage);
        $inventory->save();

        $receiving = $this->resolveReceiving($poDetail, $locationId, $userId);
        $receiving->increment('total_quantity_received', $pieces);

        $batch = $this->accumulateBatch($receiving, $product, $locationId, $pieces, $zoneLabel);

        ReceivingDetail::create([
            'receiving_id'    => $receiving->receiving_id,
            'product_id'      => $product->product_id,
            'po_detail_id'    => $poDetail?->po_detail_id,
            'quantity_amount' => $pieces,
            'prod_price'      => $product->cost_price ?? 0,
            'condition'       => 'Good',
            'scanned_barcode' => $supplierBarcode ?: null,
            'entry_mode'      => $entryMode,
        ]);

        $reference = $poDetail?->purchaseOrder?->po_number ?? 'RCV' . $receiving->receiving_number;

        $tags = $this->mintTags(
            $product,
            $batch,
            $locationId,
            $entryMode,
            $supplierBarcode,
            $reference,
            $userId,
            $unitsEntered
        );

        return [
            'receiving' => $receiving,
            'batch'     => $batch,
            'pieces'    => $pieces,
            'tags'      => $tags,
        ];
    }

    /**
     * `quantity_ordered` is in units for appliances and packages for
     * materials, while `quantity_amount` on receiving_details is always
     * pieces — so the ordered quantity has to be converted to pieces before
     * it can be compared against what has already been received.
     *
     * Returns null when $pieces fits within what remains on the PO line, or
     * a description of the overage otherwise.
     */
    public function checkOverage(PurchaseOrderDetail $poDetail, Product $product, int $pieces): ?array
    {
        $piecesPerPackage = $product->isSerialized() ? 1 : $product->getPiecesPerPackage();
        $orderedPieces    = $product->isSerialized()
            ? (int) $poDetail->quantity_ordered
            : (int) $poDetail->quantity_ordered * $piecesPerPackage;

        $alreadyReceived = (int) ReceivingDetail::where('po_detail_id', $poDetail->po_detail_id)->sum('quantity_amount');
        $remaining       = max(0, $orderedPieces - $alreadyReceived);

        if ($pieces <= $remaining) {
            return null;
        }

        return [
            'action'           => 'over_receipt',
            'ordered_pieces'   => $orderedPieces,
            'already_received' => $alreadyReceived,
            'remaining'        => $remaining,
            'counted'          => $pieces,
            'overage'          => $pieces - $remaining,
        ];
    }

    /**
     * Find the receiving header this posting belongs to, or create one.
     * PO-linked receipts group under the same header for that PO+location;
     * PO-less receipts group under today's header for that location so a
     * day's worth of unbarcoded receiving does not spawn one header per
     * scan.
     */
    private function resolveReceiving(?PurchaseOrderDetail $poDetail, int $locationId, ?int $userId): Receiving
    {
        $poId = $poDetail?->po_id;

        $receiving = $poId
            ? Receiving::where('po_id', $poId)->where('location_id', $locationId)->first()
            : Receiving::whereNull('po_id')
                ->where('location_id', $locationId)
                ->whereDate('created_at', now()->toDateString())
                ->first();

        if ($receiving) {
            return $receiving;
        }

        $receiving = new Receiving([
            'receiving_number'        => 'PENDING-' . str_replace('.', '', uniqid('', true)),
            'receiving_date'          => now()->toDateString(),
            'received_by'             => $userId,
            'total_quantity_received' => 0,
            'total_quantity_damaged'  => 0,
            'location_id'             => $locationId,
        ]);

        if ($poId) {
            $receiving->po_id = $poId;
        }

        $receiving->save();
        $receiving->assignReadableNumber();

        return $receiving;
    }

    /**
     * Accumulate received pieces into a single batch per receiving header +
     * product + location, rather than minting one tiny batch per scan. This
     * is deliberate: repeated scans against one receiving header build a
     * single coherent batch per product+location.
     */
    private function accumulateBatch(Receiving $receiving, Product $product, int $locationId, int $pieces, ?string $zoneLabel): InventoryBatch
    {
        $batch = InventoryBatch::where('receiving_id', $receiving->receiving_id)
            ->where('product_id', $product->product_id)
            ->where('location_id', $locationId)
            ->first();

        if ($batch) {
            $batch->quantity_received  += $pieces;
            $batch->quantity_remaining += $pieces;
            if ($zoneLabel !== null) {
                $batch->zone_label = $zoneLabel;
            }
            $batch->save();

            return $batch;
        }

        return $this->batches->createBatch(
            $product->product_id,
            $locationId,
            $receiving->receiving_id,
            $pieces,
            $receiving->receiving_date ?? now(),
            $zoneLabel
        );
    }

    /**
     * @return ItemSerial[]
     */
    private function mintTags(
        Product $product,
        InventoryBatch $batch,
        int $locationId,
        string $entryMode,
        ?string $supplierBarcode,
        string $referenceLabel,
        ?int $userId,
        int $unitsEntered
    ): array {
        if ($product->isSerialized()) {
            // Appliances keep their own scanned serial verbatim — no INT-
            // prefix. Warranty lookup, incident reports and the rest of the
            // appliance side resolve units by this exact serial, so minting
            // a generated lot tag here instead would break them silently.
            if (! $supplierBarcode) {
                return [];
            }

            $serial = ItemSerial::create([
                'product_id'    => $product->product_id,
                'location_id'   => $locationId,
                'batch_id'      => $batch->batch_id,
                'receiving_id'  => $batch->receiving_id,
                'scanned_by'    => $userId,
                'serial_number' => $supplierBarcode,
                'serial_type'   => 'received',
                'status_id'     => ItemSerial::IN_STOCK_STATUS_ID,
            ]);

            return [$serial];
        }

        if ($entryMode === 'counted') {
            return $this->tags->mintForCount($product, $batch, $locationId, $unitsEntered, $referenceLabel, $userId);
        }

        return [$this->tags->mintForScan($product, $batch, $locationId, $supplierBarcode, $referenceLabel, $userId)];
    }
}
