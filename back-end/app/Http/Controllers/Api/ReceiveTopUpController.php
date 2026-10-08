<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDetail;
use App\Services\PoReceivingStatusService;
use App\Services\ReceivingPostingService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Completes the shortfall on a line that was already received short.
 *
 * Boxes arrive under-filled — three cartons marked 82 holding 232 between them
 * — and the missing 14 pieces follow later as LOOSE stock, in a bag, not as a
 * fourth carton. Before this endpoint the only way to book them was to claim
 * another package through the count panel, which is exactly what pushed a
 * 3-package order to 4 packages on the record and tripped the package ceiling.
 *
 * So this posts pieces and only pieces: no package is claimed, no package
 * expectation is stated, and the package budget is untouched.
 */
class ReceiveTopUpController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request, ReceivingPostingService $posting, PoReceivingStatusService $poStatus)
    {
        $validator = Validator::make($request->all(), [
            'po_detail_id'   => 'required|integer|exists:purchase_order_details,po_detail_id',
            'location_id'    => 'nullable|exists:locations,location_id',
            'pieces'         => 'required|integer|min:1',
            'accept_overage' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $validated = $validator->validated();

        $poDetail = PurchaseOrderDetail::with(['product.installationMaterialDetail', 'product.applianceDetail', 'purchaseOrder'])
            ->find($validated['po_detail_id']);

        $product = $poDetail?->product;
        if (! $product) {
            return $this->error('Line item has no product.', 422);
        }

        // An appliance is one unit carrying one serial — there are no package
        // contents to complete, and posting loose pieces here would put stock
        // on hand with no serial behind it, breaking warranty lookup. Refuse
        // before anything moves.
        if ($product->isSerialized()) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Serialized items are received one unit per scan and have no package contents to top up.',
            ], 'Rejected');
        }

        $po = $poDetail->purchaseOrder;

        if ($po) {
            if ($po->verification_status !== 'verified') {
                return $this->success([
                    'action' => 'rejected',
                    'reason' => 'This purchase order must be verified by an auditor before receiving.',
                ], 'Rejected');
            }

            $receivableStatusIds = [8, 10, 12]; // Approved, Authorized, Partially Received
            if (! in_array((int) $po->status_id, $receivableStatusIds, true)) {
                return $this->success([
                    'action' => 'rejected',
                    'reason' => 'PO must be Approved, Authorized, or Partially Received.',
                ], 'Rejected');
            }
        }

        $locationId = $validated['location_id'] ?? $poDetail->location_id;
        if (! $locationId) {
            return $this->error('A location is required to receive stock.', 422);
        }

        // A top-up COMPLETES a shortfall, so there has to be a shortfall. With
        // nothing booked on the line there is no delivery to complete, and
        // accepting pieces here would record stock against packages that never
        // arrived. The first receipt belongs in the count panel.
        $posted = (int) ReceivingDetail::where('po_detail_id', $poDetail->po_detail_id)->sum('quantity_amount');

        if ($posted === 0) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Nothing has been received on this line yet — use the count panel to record the delivery first.',
            ], 'Rejected');
        }

        $pieces = (int) $validated['pieces'];

        // Zero packages, not null: null would opt OUT of the package ceiling
        // entirely, while zero opts in and asks it whether one more carton
        // fits — the answer is trivially yes, because no carton is arriving.
        // Loose pieces are therefore bounded by the piece ceiling alone, which
        // is exactly right, and the intent stays legible next to the count
        // path that passes a real package figure.
        $overage = $posting->checkOverage($poDetail, $product, $pieces, 0);
        if ($overage !== null && ! $request->boolean('accept_overage')) {
            return $this->success($overage, 'Over-receipt requires confirmation');
        }

        DB::beginTransaction();
        try {
            Inventory::where('location_id', $locationId)
                ->where('product_id', $product->product_id)
                ->lockForUpdate()
                ->first();

            $result = $posting->post(
                product: $product,
                locationId: (int) $locationId,
                pieces: $pieces,
                entryMode: 'counted',
                poDetail: $poDetail,
                supplierBarcode: null,
                zoneLabel: null,
                userId: $request->user()?->user_id,
                unitsEntered: 1,
                // packages_received = 0, NEVER null. Null means "this row
                // predates the column"; zero means "no carton arrived, only
                // loose pieces". The package ceiling sums this column with
                // COALESCE, so both values happen to add nothing today — but
                // only zero says so deliberately. A null here would be right
                // by accident and would misreport the row to every reader that
                // distinguishes legacy data from a real zero.
                packagesReceived: 0,
                // No package, so no package expectation to state. A shortfall
                // is pieces_expected minus quantity_amount, and loose pieces
                // have no expectation of their own — the shortfall they settle
                // is recorded on the package rows they complete.
                piecesExpected: null,
                // Loose pieces are counted by hand as they are booked, so the
                // contents are verified at entry by definition.
                contentsVerified: true,
            );

            if ($po) {
                $poStatus->sync((int) $po->po_id, $request->user()?->user_id);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->error('Failed to post top-up: ' . $e->getMessage(), 500);
        }

        return $this->success([
            'action'            => 'applied',
            'quantity_received' => $result['pieces'],
            'posted_before'     => $posted,
            'posted_after'      => $posted + $pieces,
            'packages_received' => 0,
            'receiving_number'  => $result['receiving']->receiving_number,
            'batch_id'          => $result['batch']->batch_id,
            'tags'              => collect($result['tags'])->map(fn ($t) => [
                'serial_id'        => $t->serial_id,
                'serial_number'    => $t->serial_number,
                'supplier_barcode' => $t->supplier_barcode,
            ])->values()->all(),
        ], 'Top-up posted');
    }
}
