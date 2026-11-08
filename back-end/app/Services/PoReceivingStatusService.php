<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Support\PoLifecycle;

class PoReceivingStatusService
{
    public function sync(int $poId, $userId = null): void
    {
        $po = PurchaseOrder::find($poId);
        if (! $po) {
            return;
        }
        $lines = app(PoLineBalanceService::class)->forPo($po);
        if ($lines === []) {
            return;
        }
        $state = PoLifecycle::compute(array_values($lines), in_array((int) $po->status_id, [8, 10, 11, 12], true),
            $po->lifecycle_status === PoLifecycle::CANCELLED);
        $update = ['lifecycle_status' => $state];
        // Legacy IDs remain compatible until Step 2 replaces receiving guards.
        if ($state === PoLifecycle::FULLY_RECEIVED) {
            $update['status_id'] = 11;
        } elseif ($state === PoLifecycle::PARTIALLY_RECEIVED) {
            $update['status_id'] = 12;
        }
        if ($userId !== null) {
            $update['received_by'] = $userId;
        }
        $po->update($update);
    }
}
