<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDetail;
use App\Services\PoReceivingStatusService;
use App\Services\ReceivingPostingService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Reconciles an already-scanned receiving line to the piece count a clerk
 * actually verified after opening the packages.
 *
 * The count path can verify in one go because nothing has been written yet.
 * The scan path cannot: each scan posts its package immediately, so six scans
 * mean six receiving_details rows and 300 pieces already in stock before anyone
 * opens a box. Verification here is therefore a RECONCILIATION — the delta
 * between what was posted and what was really found, applied on top.
 *
 * Without it, a box labelled 50 that holds 47 posts 50 and the shortfall
 * silently disappears.
 */
class ReceiveVerifyController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request, ReceivingPostingService $posting, PoReceivingStatusService $poStatus)
    {
        $validator = Validator::make($request->all(), [
            'po_detail_id'   => 'required|integer|exists:purchase_order_details,po_detail_id',
            'location_id'    => 'nullable|exists:locations,location_id',
            'packages'       => 'required|integer|min:1',
            'actual_pieces'  => 'required|integer|min:0',
            'accept_overage' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $validated = $validator->validated();

        $poDetail = PurchaseOrderDetail::with(['product.consumableDetail', 'product.applianceDetail', 'purchaseOrder'])
            ->find($validated['po_detail_id']);

        $product = $poDetail?->product;
        if (! $product) {
            return $this->error('Line item has no product.', 422);
        }

        // An appliance is one unit carrying one serial — there are no package
        // contents to verify, and nothing here could reconcile without
        // orphaning a serial from its stock. Refuse before anything moves.
        if ($product->isSerialized()) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Serialized items are received one unit per scan and have nothing to verify.',
            ], 'Rejected');
        }

        $locationId = $validated['location_id'] ?? $poDetail->location_id;
        if (! $locationId) {
            return $this->error('A location is required to verify stock.', 422);
        }

        $posted = (int) ReceivingDetail::where('po_detail_id', $poDetail->po_detail_id)->sum('quantity_amount');

        if ($posted === 0) {
            // Nothing has been booked against this line, so there is no delta
            // to apply. The clerk wants the count panel, which posts the real
            // figure directly instead of correcting one.
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Nothing has been received on this line yet — use the count panel to receive it.',
            ], 'Rejected');
        }

        $piecesPerPackage = $product->getPiecesPerPackage();
        $packages         = (int) $validated['packages'];
        $actualPieces     = (int) $validated['actual_pieces'];
        $expected         = $packages * $piecesPerPackage;
        $delta            = $actualPieces - $posted;

        // Finding MORE than was posted is an over-receipt like any other, and
        // is checked by the same rule as the scan and count paths. Two
        // different over-receipt rules depending on how the stock was entered
        // would be impossible to explain to an auditor.
        if ($delta > 0) {
            $overage = $posting->checkOverage($poDetail, $product, $posted + $delta);
            if ($overage !== null && ! $request->boolean('accept_overage')) {
                return $this->success($overage, 'Over-receipt requires confirmation');
            }
        }

        $po = $poDetail->purchaseOrder;

        DB::beginTransaction();
        try {
            $inventory = Inventory::where('location_id', $locationId)
                ->where('product_id', $product->product_id)
                ->lockForUpdate()
                ->first();

            if (! $inventory) {
                DB::rollBack();

                return $this->success([
                    'action' => 'rejected',
                    'reason' => 'No inventory record at this location for this product.',
                ], 'Rejected');
            }

            $onHand = (int) $inventory->quantity_on_hand;

            // If stock has already been issued out of this receipt, reducing it
            // further would strand negative quantity. setPieces() clamps at
            // zero, which would silently swallow the difference — so refuse
            // here instead. Correcting that case is an adjustment, not a
            // verification.
            if ($onHand + $delta < 0) {
                DB::rollBack();

                return $this->success([
                    'action' => 'rejected',
                    'reason' => 'Stock has already moved since receiving. Raise a stock adjustment instead.',
                ], 'Rejected');
            }

            $inventory->setPieces($onHand + $delta, $piecesPerPackage);
            $inventory->save();

            // The line's most recent receiving_details row carries the
            // correction. A line scanned six times has six rows and the delta
            // has to land on exactly one of them: the latest row is the one the
            // clerk just finished scanning, and it is the row the verification
            // panel is showing.
            $row = ReceivingDetail::where('po_detail_id', $poDetail->po_detail_id)
                ->orderByDesc('receiving_detail_id')
                ->first();

            if ($row) {
                // The batch is found exactly as ReceivingPostingService::
                // accumulateBatch() finds it, so the delta lands on the same
                // batch the scans accumulated into.
                //
                // This relies on scans accumulating into ONE batch per
                // receiving header + product + location, which is what
                // accumulateBatch() guarantees today — so a line's delta always
                // has exactly one batch to land on. If a line ever spans
                // several batches, this needs a rule for distributing the delta
                // between them; there is no such rule yet.
                $batch = InventoryBatch::where('receiving_id', $row->receiving_id)
                    ->where('product_id', $product->product_id)
                    ->where('location_id', $locationId)
                    ->first();

                if ($batch) {
                    // Both columns move by the same delta. Verification
                    // corrects what ARRIVED, not what was consumed: pieces that
                    // were never in the box were never received either. Moving
                    // only quantity_remaining would leave the batch reading
                    // "100 received, 97 remaining" — indistinguishable from
                    // three pieces having been issued, and enough to make the
                    // PO group's total_received overstate the delivery.
                    $batch->quantity_received  = max(0, (int) $batch->quantity_received + $delta);
                    $batch->quantity_remaining = max(0, (int) $batch->quantity_remaining + $delta);
                    $batch->save();
                }

                $row->packages_received = $packages;
                $row->pieces_expected   = $expected;
                $row->quantity_amount   = max(0, (int) $row->quantity_amount + $delta);
                $row->save();
            }

            if ($po) {
                $poStatus->sync((int) $po->po_id, $request->user()?->user_id);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->error('Failed to verify receipt: ' . $e->getMessage(), 500);
        }

        return $this->success([
            'action'            => 'applied',
            'posted_before'     => $posted,
            'posted_after'      => $posted + $delta,
            'pieces_expected'   => $expected,
            'packages_received' => $packages,
            'delta'             => $delta,
        ], 'Receipt verified');
    }
}
