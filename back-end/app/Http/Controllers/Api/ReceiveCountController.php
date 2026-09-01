<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\PurchaseOrderDetail;
use App\Services\PoReceivingStatusService;
use App\Services\ReceivingPostingService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Receives installation materials by hand-count when the shipment arrived
 * without a usable barcode. Unlike the scan path, the product is resolved
 * from the PO line itself — there is no barcode to resolve it through.
 */
class ReceiveCountController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request, ReceivingPostingService $posting, PoReceivingStatusService $poStatus)
    {
        $validator = Validator::make($request->all(), [
            'po_detail_id'   => 'required|integer|exists:purchase_order_details,po_detail_id',
            'location_id'    => 'nullable|exists:locations,location_id',
            'quantity'       => 'required|integer|min:1',
            'actual_pieces'  => 'nullable|integer|min:0',
            'zone_label'     => 'nullable|string|max:100',
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

        // Serialized guard — must run before any stock moves. Without it an
        // appliance could be count-received and lose its per-unit serial
        // trail, silently breaking warranty lookup and incident reports.
        if ($product->isSerialized()) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Serialized items must be scanned individually.',
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

        $unitsEntered = (int) $validated['quantity'];
        $countUnit    = $product->consumableDetail?->count_unit ?? 'package';

        if ($countUnit === 'piece') {
            // The clerk counted pieces directly — expected and actual are the
            // same thing, so there is nothing to verify and nothing to record.
            $pieces           = $unitsEntered;
            $packagesReceived = null;
            $piecesExpected   = null;
        } else {
            // The expectation comes from the item master, never from the clerk.
            $piecesExpected   = $unitsEntered * $product->getPiecesPerPackage();
            $packagesReceived = $unitsEntered;
            $pieces           = isset($validated['actual_pieces'])
                ? (int) $validated['actual_pieces']
                : $piecesExpected;
        }

        $overage = $posting->checkOverage($poDetail, $product, $pieces);
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
                $product,
                (int) $locationId,
                $pieces,
                'counted',
                $poDetail,
                null,
                $validated['zone_label'] ?? null,
                $request->user()?->user_id,
                $unitsEntered,
                $packagesReceived,
                $piecesExpected
            );

            if ($po) {
                $poStatus->sync((int) $po->po_id, $request->user()?->user_id);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->error('Failed to post count: ' . $e->getMessage(), 500);
        }

        return $this->success([
            'action'            => 'applied',
            'quantity_received' => $result['pieces'],
            'units_entered'     => $unitsEntered,
            'count_unit'        => $countUnit,
            'packages_received' => $packagesReceived,
            'pieces_expected'   => $piecesExpected,
            'receiving_number'  => $result['receiving']->receiving_number,
            'batch_id'          => $result['batch']->batch_id,
            'tags'              => collect($result['tags'])->map(fn ($t) => [
                'serial_number'    => $t->serial_number,
                'supplier_barcode' => $t->supplier_barcode,
            ])->values()->all(),
        ], 'Receiving posted');
    }
}
