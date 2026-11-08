<?php

namespace App\Services;

use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Support\PoLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PoReceivingGuard
{
    /** Always lock PO then line, before inventory, within the caller's transaction. */
    public function lock(PurchaseOrderDetail $line, int $locationId, int $productId, bool $verification = false): PurchaseOrderDetail
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('PO receiving requires a database transaction.');
        }
        $po = PurchaseOrder::whereKey($line->po_id)->lockForUpdate()->first();
        $locked = PurchaseOrderDetail::whereKey($line->po_detail_id)->lockForUpdate()->first();
        if (! $po || ! $locked || (int) $locked->po_id !== (int) $po->po_id) {
            $this->refuse('The PO or line is no longer available.');
        }
        if ((int) $locked->product_id !== $productId || $locationId !== (int) ($locked->location_id ?? $po->location_id)) {
            $this->refuse('The product and receiving warehouse must match the PO line.');
        }
        $user = auth()->user();
        if ($user && ! $user->isCentralized() && $user->scopedLocationId() !== $locationId) {
            $this->refuse('You can receive only at your assigned warehouse.');
        }
        if (! Location::whereKey($locationId)->where('location_type', 'warehouse')->where('is_active', true)->exists()) {
            $this->refuse('Goods can be received only at an active warehouse.');
        }
        if ($po->verification_status !== 'verified') {
            $this->refuse('The purchase order must be verified before receiving.');
        }
        if (in_array($po->lifecycle_status, [PoLifecycle::SHORT_CLOSED, PoLifecycle::CANCELLED], true)) {
            $this->refuse('Receiving is blocked on a Short-Closed or Cancelled PO.');
        }
        if (! $verification && $po->lifecycle_status === PoLifecycle::FULLY_RECEIVED) {
            $this->refuse('New receiving is blocked on a Fully Received PO.');
        }
        if (! in_array((int) $po->status_id, $verification ? [8, 10, 11, 12] : [8, 10, 12], true)) {
            $this->refuse('PO must be Approved/Open or Partially Received.');
        }
        $balances = app(PoLineBalanceService::class)->forPo($po);
        if (! $verification && $balances[$locked->po_detail_id]['remaining'] <= 0) {
            $this->refuse('This PO line has no remaining quantity to receive.');
        }
        $state = PoLifecycle::compute(array_values($balances), true);
        if (! $verification && ! in_array($state, [PoLifecycle::OPEN, PoLifecycle::PARTIALLY_RECEIVED], true)) {
            $this->refuse('Receiving is blocked on a Fully Received or Short-Closed PO.');
        }
        // Historical orphan receipts cannot safely be interpreted as remaining stock.
        $ambiguous = DB::table('receiving_details as rd')
            ->join('receivings as r', 'r.receiving_id', '=', 'rd.receiving_id')
            ->leftJoin('purchase_order_details as pod', 'pod.po_detail_id', '=', 'rd.po_detail_id')
            ->where('r.po_id', $po->po_id)->where(function ($q) {
                $q->whereNull('pod.po_detail_id')->orWhereColumn('pod.po_id', '<>', 'r.po_id')
                    ->orWhereColumn('pod.product_id', '<>', 'rd.product_id')->orWhere('rd.quantity_amount', '<', 0);
            })->exists();
        if ($ambiguous) {
            $this->refuse('Historical receipts require reconciliation before more goods can be received.');
        }

        return $locked;
    }

    public function assertQuantity(PurchaseOrderDetail $line, int $pieces, ?int $packages = null): void
    {
        if ($pieces < 0) {
            $this->refuse('Received quantity cannot be negative.');
        }
        $po = $line->purchaseOrder;
        $balance = app(PoLineBalanceService::class)->forPo($po)[$line->po_detail_id];
        if ($pieces > $balance['remaining']) {
            $this->refuse("Over-receiving is blocked: only {$balance['remaining']} piece(s) remain (0% tolerance).");
        }
        if ($packages !== null && $line->order_unit === 'package') {
            $receivedPackages = (int) DB::table('receiving_details')->where('po_detail_id', $line->po_detail_id)->sum('packages_received');
            if ($receivedPackages + $packages > (int) $line->quantity_ordered) {
                $this->refuse('No room remains for these packages; use loose-piece top-up for missing package contents.');
            }
        }
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['receiving' => $message]);
    }
}
