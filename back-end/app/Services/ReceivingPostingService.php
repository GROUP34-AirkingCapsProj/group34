<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\PurchaseOrderDetail;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use App\Services\Ledger\BatchCostResolver;
use App\Services\Ledger\LedgerPoster;
use Illuminate\Support\Facades\DB;

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
        private MaterialTagService $tags,
        private LedgerPoster $ledger,
        private BatchCostResolver $costs
    ) {
    }

    /**
     * @param  string  $entryMode  'scanned' | 'counted'
     * @param  ?bool  $contentsVerified  True when a human confirmed what was
     *         physically inside — which a hand count does by definition, and a
     *         barcode scan never does. Threaded through here rather than
     *         written by a controller so the single write path stays single.
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
        int $unitsEntered = 1,
        ?int $packagesReceived = null,
        ?int $piecesExpected = null,
        ?bool $contentsVerified = null
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

        $lineCost = $this->costs->costForReceivingLine($poDetail, $product);

        $batch = $this->accumulateBatch(
            $receiving, $product, $locationId, $pieces, $zoneLabel,
            $lineCost['unit_cost'], $lineCost['cost_basis']
        );

        $detail = ReceivingDetail::create([
            'receiving_id'      => $receiving->receiving_id,
            'product_id'        => $product->product_id,
            'po_detail_id'      => $poDetail?->po_detail_id,
            'quantity_amount'   => $pieces,
            'packages_received' => $packagesReceived,
            'pieces_expected'   => $piecesExpected,
            // Stamped only when the caller states the contents were confirmed.
            // Anything else — a scan, or a caller with no opinion — leaves NULL,
            // which is the honest reading: the box has not been opened.
            'contents_verified_at' => $contentsVerified ? now() : null,
            // What the business is paying, per the PO line — not the product's
            // list cost, which is a stand-in that made every valuation downstream
            // wrong. Falls back to the list cost only when there is no PO line.
            'prod_price'        => $lineCost['unit_cost'] ?? 0,
            'condition'         => 'Good',
            'scanned_barcode'   => $supplierBarcode ?: null,
            'entry_mode'        => $entryMode,
        ]);

        $this->ledger->post([
            'occurred_at'    => $receiving->receiving_date ?? now(),
            'direction'      => 'in',
            'movement_type'  => 'receiving',
            'product_id'     => $product->product_id,
            'batch_id'       => $batch->batch_id,
            'serial_number'  => $product->isSerialized() ? $supplierBarcode : null,
            'quantity'       => $pieces,
            'unit_cost'      => $lineCost['unit_cost'],
            'cost_basis'     => $lineCost['cost_basis'],
            'to_location_id' => $locationId,
            'source_type'    => 'receivings',
            'source_id'      => $receiving->receiving_id,
            'source_line_id' => $detail->receiving_detail_id,
            'correlation_id' => 'receivings:' . $receiving->receiving_id,
            'actor_user_id'  => $userId,
            'posting_key'    => 'receiving:' . $receiving->receiving_id
                                . ':line:' . $detail->receiving_detail_id . ':in',
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
     *
     * Two ceilings bound receiving, and $packages (null when the caller has
     * no package concept, e.g. a piece-counted line) opts into the second.
     * The package ceiling is checked first: when both would fire, "the order
     * has no room for another carton" is the fact the clerk can act on,
     * whereas a piece overage invites them to shave the count down to fit —
     * a correction that does not address the extra box on the floor.
     */
    public function checkOverage(
        PurchaseOrderDetail $poDetail,
        Product $product,
        int $pieces,
        ?int $packages = null
    ): ?array {
        // Short-filled boxes decoupled the two ceilings. Before piece
        // verification every package posted exactly ppp pieces, so bounding
        // pieces implicitly bounded packages. Now three half-full boxes consume
        // half the piece budget and leave room for a fourth carton the order
        // never asked for.
        if ($packages !== null && ! $product->isSerialized()) {
            $orderedPackages  = (int) $poDetail->quantity_ordered;
            $receivedPackages = (int) ReceivingDetail::where('po_detail_id', $poDetail->po_detail_id)
                ->sum(DB::raw('COALESCE(packages_received, 0)'));

            if ($receivedPackages + $packages > $orderedPackages) {
                return [
                    'action'             => 'over_receipt',
                    'limit'              => 'packages',
                    'ordered_packages'   => $orderedPackages,
                    'received_packages'  => $receivedPackages,
                    'remaining_packages' => max(0, $orderedPackages - $receivedPackages),
                    'counted_packages'   => $packages,
                    'overage_packages'   => ($receivedPackages + $packages) - $orderedPackages,
                ];
            }
        }

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
            'limit'            => 'pieces',
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
     *
     * Because one batch can absorb several postings, its unit_cost is blended
     * rather than overwritten — two deliveries at two prices leave a weighted
     * average, and cost_basis records that it is derived.
     */
    private function accumulateBatch(
        Receiving $receiving,
        Product $product,
        int $locationId,
        int $pieces,
        ?string $zoneLabel,
        ?float $lineCost = null,
        string $lineBasis = 'unavailable'
    ): InventoryBatch {
        $batch = InventoryBatch::where('receiving_id', $receiving->receiving_id)
            ->where('product_id', $product->product_id)
            ->where('location_id', $locationId)
            ->first();

        if ($batch) {
            // Blend before incrementing: the weighting needs the pre-addition quantity.
            $blend = $this->costs->blendIntoBatch($batch, $pieces, $lineCost, $lineBasis);

            $batch->quantity_received  += $pieces;
            $batch->quantity_remaining += $pieces;
            $batch->unit_cost  = $blend['unit_cost'];
            $batch->cost_basis = $blend['cost_basis'];
            if ($zoneLabel !== null) {
                $batch->zone_label = $zoneLabel;
            }
            $batch->save();

            return $batch;
        }

        $batch = $this->batches->createBatch(
            $product->product_id,
            $locationId,
            $receiving->receiving_id,
            $pieces,
            $receiving->receiving_date ?? now(),
            $zoneLabel
        );

        $batch->unit_cost  = $lineCost;
        $batch->cost_basis = $lineBasis;
        $batch->save();

        return $batch;
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
            //
            // An appliance must never enter stock without a barcode. Callers
            // already prevent it — the count endpoint rejects serialized
            // products outright, and the scan endpoint requires a barcode that
            // Laravel's `required` rule trims before checking, so whitespace is
            // refused. This throw is the invariant's last line of defence: it
            // belongs to the code that owns the rule rather than to a
            // validation rule a future caller might bypass. Throwing inside the
            // caller's transaction rolls the receipt back, so no stock moves.
            if (! $supplierBarcode) {
                throw new \RuntimeException(
                    "Refusing to receive serialized product {$product->product_id} without a serial number. "
                    . 'Appliances must be scanned individually.'
                );
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
