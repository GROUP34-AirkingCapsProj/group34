<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\PickReservation;
use Illuminate\Support\Facades\DB;

class TransferAvailabilityService
{
    /** Stock that can still be promised, after issuance holds, quarantine and approved transfers. */
    public function atLocation(int $locationId, array $productIds, ?int $excludeTransferId = null, bool $lock = false): array
    {
        $query = Inventory::where('location_id', $locationId)
            ->whereIn('product_id', $productIds)->orderBy('product_id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $inventory = $query->get()->keyBy('product_id');
        $holdsQuery = PickReservation::active()->where('location_id', $locationId)
            ->whereIn('product_id', $productIds);
        $committedQuery = DB::table('transfer_details as d')
            ->join('transfers as t', 't.transfer_id', '=', 'd.transfer_id')
            ->join('status_lookup as s', 's.status_id', '=', 't.status_id')
            ->where('t.from_location_id', $locationId)->whereNull('t.deleted_at')
            ->where('s.status_category', 'transfer')->where('s.status_name', 'Approved')
            ->whereIn('d.product_id', $productIds)
            ->when($excludeTransferId, fn ($q) => $q->where('t.transfer_id', '!=', $excludeTransferId));
        if ($lock) {
            // Current locking reads also see approvals committed while waiting
            // for the inventory lock, even under MySQL repeatable-read isolation.
            $holdsQuery->lockForUpdate();
            $committedQuery->lockForUpdate();
        }
        $held = $holdsQuery->get(['product_id', 'quantity'])->groupBy('product_id')
            ->map(fn ($rows) => $rows->sum('quantity'));
        $committed = $committedQuery->get(['d.product_id', 'd.quantity_requested'])->groupBy('product_id')
            ->map(fn ($rows) => $rows->sum('quantity_requested'));
        $quarantined = app(IncidentService::class)->quarantinedQuantities($locationId);

        $pool = [];
        foreach ($productIds as $pid) {
            $pool[$pid] = [
                'available' => max(0, (int) ($inventory[$pid]->available_quantity ?? 0)
                    - (int) ($held[$pid] ?? 0) - (int) ($committed[$pid] ?? 0)
                    - (int) ($quarantined[$pid] ?? 0)),
                'on_hand' => (int) ($inventory[$pid]->quantity_on_hand ?? 0),
            ];
        }
        return $pool;
    }
}
