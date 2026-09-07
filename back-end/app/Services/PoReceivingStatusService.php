<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDetail;

class PoReceivingStatusService
{
    public function sync(int $poId, $userId = null): void
    {
        $details = PurchaseOrderDetail::with('product.installationMaterialDetail')
            ->where('po_id', $poId)
            ->get(['po_detail_id', 'product_id', 'quantity_ordered']);

        if ($details->isEmpty()) {
            return;
        }

        $totalOrdered  = 0;
        $totalReceived = 0;

        foreach ($details as $d) {
            $product = $d->product;
            if (! $product) {
                continue;
            }

            // Ensure extension tables are loaded before calling V5 helpers.
            if (! $product->relationLoaded('applianceDetail')) {
                $product->load('applianceDetail');
            }
            if (! $product->relationLoaded('installationMaterialDetail')) {
                $product->load('installationMaterialDetail');
            }

            $isSerialized  = $product->isSerialized();
            $pcsPerPackage = $product->getPiecesPerPackage();

            // quantity_ordered is in units for appliances, packages for installation materials.
            // quantity_amount is always stored in pieces — convert ordered to pieces.
            $orderedPieces = $isSerialized
                ? (int) $d->quantity_ordered
                : (int) $d->quantity_ordered * $pcsPerPackage;

            $receivedPieces = (int) ReceivingDetail::where('po_detail_id', $d->po_detail_id)
                ->sum('quantity_amount');

            $totalOrdered  += $orderedPieces;
            $totalReceived += $receivedPieces;
        }

        $updateData = [];

        if ($totalReceived > 0) {
            // V5 status IDs — hardcoded; no DB string lookup needed.
            // 11 = Completed, 12 = Partially Received
            $newStatusId             = ($totalReceived >= $totalOrdered) ? 11 : 12;
            $updateData['status_id'] = $newStatusId;

            if ($userId !== null) {
                $updateData['received_by'] = $userId;
            }

            PurchaseOrder::where('po_id', $poId)->update($updateData);

            // When PO is fully completed: move budget from committed → spent.
            // Only applies to POs sourced from a PR that has a budget_id.
            if ($newStatusId === 11) {
                $po = PurchaseOrder::with('purchaseRequest')->find($poId);
                if ($po) {
                    app(\App\Services\BudgetService::class)->spend($po, $userId);
                }
            }
        } elseif ($userId !== null) {
            // No items received yet — still stamp received_by on the PO.
            PurchaseOrder::where('po_id', $poId)->update(['received_by' => $userId]);
        }
    }
}
