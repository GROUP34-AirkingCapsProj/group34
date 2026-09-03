<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDraft;
use App\Models\ReceivingDraftItem;
use Illuminate\Support\Facades\DB;

/**
 * Posts a staged delivery to inventory.
 *
 * This is the only new code in the staged-receiving feature that moves stock,
 * and it moves it by replaying each staged row through the pre-existing
 * ReceivingPostingService::post() — the same call the scan endpoint has always
 * made. Nothing about how stock is posted changes; only *when* it happens does.
 *
 * ALL-OR-NOTHING. One transaction wraps the whole draft. A single bad row means
 * nothing posts and the draft survives untouched, because a half-posted truck
 * cannot be reconciled against a delivery note.
 */
class ReceivingDraftSubmitService
{
    public function __construct(
        private ReceivingPostingService $posting,
        private PoReceivingStatusService $poStatus
    ) {
    }

    /**
     * @param  bool  $acceptOverage  Receiving more than was ordered is a real
     *         thing that happens; this is how an authorized user accepts it.
     *         Unlike the old per-scan confirm, the clerk sets this on a
     *         deliberate re-submit after seeing the full list of what is over.
     * @return array{action: 'submitted'|'failed', ...}
     */
    public function submit(ReceivingDraft $draft, ?int $userId, bool $acceptOverage = false): array
    {
        if ($draft->status !== 'open') {
            return [
                'action'   => 'failed',
                'failures' => [],
                'message'  => 'This delivery has already been submitted.',
            ];
        }

        $items = $draft->items()->with(['product.applianceDetail', 'product.consumableDetail'])->get();

        if ($items->isEmpty()) {
            return [
                'action'   => 'failed',
                'failures' => [],
                'message'  => 'There is nothing staged to submit.',
            ];
        }

        DB::beginTransaction();
        try {
            // Lock every inventory row this draft will touch, up front, per
            // ReceivingPostingService's documented transaction contract. Locking
            // the distinct set before posting anything also avoids two
            // concurrent submits deadlocking by grabbing the same rows in
            // different orders.
            $productIds = $items->pluck('product_id')->unique()->sort()->values();
            foreach ($productIds as $productId) {
                Inventory::where('location_id', $draft->location_id)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();
            }

            $failures = [];
            $posted   = [];
            $tags     = [];
            $receiving = null;

            foreach ($items as $item) {
                $outcome = $this->postItem($draft, $item, $userId, $acceptOverage);

                if (isset($outcome['failure'])) {
                    $failures[] = $outcome['failure'];
                    // Keep going rather than bailing on the first problem. The
                    // clerk gets one complete list to fix instead of
                    // discovering faults one submit at a time.
                    continue;
                }

                $receiving = $outcome['receiving'];
                $posted[]  = (int) $item->draft_item_id;
                $tags      = array_merge($tags, $outcome['tags']);
            }

            if ($failures !== []) {
                DB::rollBack();

                return [
                    'action'   => 'failed',
                    'failures' => $failures,
                    'message'  => 'Nothing was posted. Fix the flagged rows and submit again.',
                ];
            }

            $draft->update([
                'status'       => 'submitted',
                'receiving_id' => $receiving?->receiving_id,
                'submitted_at' => now(),
            ]);

            $this->poStatus->sync((int) $draft->po_id, $userId);

            DB::commit();

            return [
                'action'           => 'submitted',
                'receiving_id'     => $receiving?->receiving_id,
                'receiving_number' => $receiving?->receiving_number,
                'items_posted'     => count($posted),
                'tags'             => collect($tags)->map(fn ($t) => [
                    'serial_number'    => $t->serial_number,
                    'supplier_barcode' => $t->supplier_barcode,
                ])->values()->all(),
            ];
        } catch (\Throwable $e) {
            DB::rollBack();

            return [
                'action'   => 'failed',
                'failures' => [],
                'message'  => 'Failed to submit the delivery: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Post one staged row, or describe why it cannot be posted.
     *
     * @return array{failure: array}|array{receiving: \App\Models\Receiving, tags: array}
     */
    private function postItem(ReceivingDraft $draft, ReceivingDraftItem $item, ?int $userId, bool $acceptOverage): array
    {
        /** @var ?Product $product */
        $product = $item->product;

        if (! $product) {
            return ['failure' => $this->failure($item, 'This row has no product.')];
        }

        /** @var ?PurchaseOrderDetail $poDetail */
        $poDetail = $item->po_detail_id
            ? PurchaseOrderDetail::with('purchaseOrder')->find($item->po_detail_id)
            : null;

        if ($poDetail) {
            // checkOverage sums live receiving_details, so once the previous
            // row in this loop has posted, this call already accounts for it.
            // Do NOT keep a running tally here — it would double-count.
            $overage = $this->posting->checkOverage(
                $poDetail,
                $product,
                (int) $item->quantity_amount,
                $item->packages_received === null ? null : (int) $item->packages_received
            );

            if ($overage !== null && ! $acceptOverage) {
                return ['failure' => $this->failure($item, $this->explainOverage($overage), $overage)];
            }
        }

        try {
            $result = $this->posting->post(
                product: $product,
                locationId: (int) $draft->location_id,
                pieces: (int) $item->quantity_amount,
                entryMode: $item->entry_mode,
                poDetail: $poDetail,
                supplierBarcode: $item->scanned_barcode ?: null,
                // A delivery is put away in one zone, so it lives on the header
                // and every row inherits it.
                zoneLabel: $draft->zone_label,
                userId: $userId,
                unitsEntered: (int) $item->units_entered,
                packagesReceived: $item->packages_received === null ? null : (int) $item->packages_received,
                piecesExpected: $item->pieces_expected === null ? null : (int) $item->pieces_expected,
                contentsVerified: (bool) $item->contents_verified,
            );
        } catch (\Throwable $e) {
            // A duplicate serial claimed since staging lands here, via the
            // global item_serial.serial_number unique key. Naming the row is
            // what lets the clerk find the one label that is wrong.
            return ['failure' => $this->failure($item, $this->explainThrowable($item, $e))];
        }

        return ['receiving' => $result['receiving'], 'tags' => $result['tags']];
    }

    /** @return array{draft_item_id: int, product_name: ?string, barcode: ?string, reason: string, detail: ?array} */
    private function failure(ReceivingDraftItem $item, string $reason, ?array $detail = null): array
    {
        return [
            'draft_item_id' => (int) $item->draft_item_id,
            'product_name'  => $item->product?->product_name,
            'barcode'       => $item->scanned_barcode,
            'reason'        => $reason,
            'detail'        => $detail,
        ];
    }

    /**
     * Turn checkOverage's machine-readable payload into a sentence a clerk can
     * act on. The package limit is phrased in cartons because that is what is
     * physically on the floor; the piece limit is phrased in pieces.
     */
    private function explainOverage(array $overage): string
    {
        if (($overage['limit'] ?? null) === 'packages') {
            $over      = (int) ($overage['overage_packages'] ?? 0);
            $remaining = (int) ($overage['remaining_packages'] ?? 0);

            return "This line no longer has room — {$over} package(s) over, with {$remaining} still expected.";
        }

        $over      = (int) ($overage['overage'] ?? 0);
        $remaining = (int) ($overage['remaining'] ?? 0);

        return "This line no longer has room — {$over} piece(s) over, with {$remaining} still expected.";
    }

    private function explainThrowable(ReceivingDraftItem $item, \Throwable $e): string
    {
        $message = $e->getMessage();

        if ($item->scanned_barcode && str_contains(strtolower($message), 'duplicate')) {
            return "Barcode \"{$item->scanned_barcode}\" was claimed by another delivery "
                . 'while this one was being staged. Remove this row and check the label.';
        }

        return 'Could not post this row: ' . $message;
    }
}
