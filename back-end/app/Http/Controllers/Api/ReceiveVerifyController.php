<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\PurchaseOrderDetail;
use App\Models\Receiving;
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

            // The line's most recent receiving_details row carries the batch
            // correction.
            //
            // The delta is DISTRIBUTED across the line's rows newest
            // first, draining each to zero before moving to the next, because a
            // shortfall can be larger than any single row: six scans of 50
            // reconciled to 20 is a delta of -280 against a newest row of 50.
            // Clamping that at zero would leave SUM(quantity_amount) reading
            // 250 while the ledger reads 20 — and that sum is not cosmetic.
            // checkOverage() reads it as `already_received`, so the next scan
            // would wave a genuine over-receipt through; PoReceivingStatusService
            // reads it as `totalReceived` and could flip the PO to Completed,
            // firing the committed-to-spent budget transition, on a delivery
            // that barely arrived. BudgetService and reporting read it too.
            //
            // Letting the one row go negative would also make the sum exact and
            // is less code, but quantity_amount is consumed elsewhere as a
            // PHYSICAL count, not a signed correction: BarcodeAdjustmentController
            // subtracts a single row's value from stock when voiding a scan
            // (so a negative row would ADD stock), writes it into
            // AdjustmentDetail::deduct_quantity, and InventoryController
            // publishes it as a "Stock In" movement quantity. Distributing keeps
            // every row a non-negative count, which is what those consumers
            // already assume. The cost is that a drained row no longer
            // corresponds to its own scan — acceptable, since verification is
            // once per line rather than per box, so that correspondence was
            // already only as strong as the arbitrary choice of newest row.
            //
            // The shortfall can never exceed the sum of the rows, because
            // $posted IS that sum — so the distribution always absorbs the
            // delta exactly and the loop cannot exit with a remainder.
            $rows = ReceivingDetail::where('po_detail_id', $poDetail->po_detail_id)
                ->orderByDesc('receiving_detail_id')
                ->get();

            $row = $rows->first();

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

                // The expectation columns are ROW-scoped, exactly as the count
                // path writes them: each row states what its OWN entry should
                // have held. Every row of the line is stamped, not only the
                // ones the delta happens to drain, because a row that reads
                // 50 expected against 50 actual is the statement "this box was
                // full" — and a NULL there is no statement at all.
                //
                // One package per row is exact on the scan path:
                // InventoryScanTransactionController::processReceiving() posts
                // `$quantity = $isSerialized ? 1 : $piecesPerPackage` and
                // deliberately ignores the request's quantity field, so a
                // non-serialized scan can never post a partial package.
                //
                // Stamping the clerk's line-wide $packages/$expected on the
                // newest row instead — as this did before — contradicts the
                // distribution below, which spreads the shortfall over several
                // rows. Two boxes of 50 verified to 97 left the newest row
                // reading 100 expected against 47 actual: a 53-piece shortfall
                // that never happened, on the very screen that compares those
                // two columns per row.
                //
                // Rows written by the count path already carry their own
                // row-scoped figures for an entry that was never one package,
                // so they are left as they are.
                foreach ($rows as $r) {
                    if ($r->entry_mode === 'counted') {
                        continue;
                    }
                    $r->packages_received = 1;
                    $r->pieces_expected   = $piecesPerPackage;
                }

                // How much each receiving header's rows moved, so the header
                // totals can follow by exactly the amount taken from them.
                $headerDeltas = [];

                if ($delta >= 0) {
                    // Extra pieces found in the boxes all belong to the newest
                    // row; there is no floor to run into on the way up.
                    $row->quantity_amount = (int) $row->quantity_amount + $delta;
                    $headerDeltas[$row->receiving_id] = $delta;
                } else {
                    $shortfall = -$delta;

                    foreach ($rows as $r) {
                        if ($shortfall <= 0) {
                            break;
                        }
                        $take = min($shortfall, (int) $r->quantity_amount);
                        if ($take <= 0) {
                            continue;
                        }
                        $r->quantity_amount = (int) $r->quantity_amount - $take;
                        $shortfall -= $take;
                        $headerDeltas[$r->receiving_id] = ($headerDeltas[$r->receiving_id] ?? 0) - $take;
                    }
                }

                // Every row is saved, not only the ones the delta moved: when
                // the delta is zero the loop above never runs, and each row
                // still carries the verification stamp that records it as
                // checked and full.
                foreach ($rows as $r) {
                    $r->save();
                }

                // The header is incremented by every scan, so it has to follow
                // the correction or it drifts from both SUM(quantity_amount)
                // and the batch it funded. It feeds the receiving picker label
                // and several reports. Floored at zero — it is a physical
                // running total, like the batch columns.
                foreach ($headerDeltas as $receivingId => $headerDelta) {
                    if ($headerDelta === 0) {
                        continue;
                    }
                    $receiving = Receiving::find($receivingId);
                    if ($receiving) {
                        $receiving->total_quantity_received = max(
                            0,
                            (int) $receiving->total_quantity_received + $headerDelta
                        );
                        $receiving->save();
                    }
                }
            }

            if ($po) {
                $poStatus->sync((int) $po->po_id, $request->user()?->user_id);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->error('Failed to verify receipt: ' . $e->getMessage(), 500);
        }

        // These two are LINE-wide on purpose, and deliberately differ from the
        // per-row columns written above. They echo back what the clerk just
        // entered — "2 packages, 100 expected" — so the panel can confirm the
        // verification it performed. The rows answer a different question:
        // what each individual scan should have held. Two boxes of 50 leave
        // packages_received = 2 here and 1 on every row, and both are correct.
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
