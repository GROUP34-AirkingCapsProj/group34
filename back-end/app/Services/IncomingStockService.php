<?php

namespace App\Services;

use App\Support\PoLifecycle;
use Illuminate\Support\Facades\DB;

class IncomingStockService
{
    public static function markDirty(): void
    {
        // Written on the same connection/transaction as the stock change.
        // A rollback also rolls back this version; no post-commit cache failure
        // can turn a successful receipt into an apparent failure and retry.
        DB::table('system_settings')->updateOrInsert(['key' => 'po_incoming_changed'], [
            'value' => uniqid('', true), 'updated_at' => now(),
        ]);
    }

    /** Piece remainders are netted per line before warehouse/product aggregation. */
    public function byWarehouse(): array
    {
        $receipts = DB::table('receiving_details as rd')
            ->join('receivings as r', 'r.receiving_id', '=', 'rd.receiving_id')
            ->join('purchase_order_details as d', 'd.po_detail_id', '=', 'rd.po_detail_id')
            ->whereColumn('r.po_id', 'd.po_id')->whereColumn('rd.product_id', 'd.product_id')
            ->groupBy('rd.po_detail_id')->selectRaw('rd.po_detail_id, SUM(rd.quantity_amount) as received');
        $lines = DB::table('purchase_order_details as pod')
            ->join('purchase_orders as po', 'po.po_id', '=', 'pod.po_id')
            ->leftJoinSub($receipts, 'recv', 'recv.po_detail_id', '=', 'pod.po_detail_id')
            ->whereNull('po.deleted_at')->whereIn('po.status_id', [8, 10, 12])
            ->where(function ($q) {
                $q->whereNull('po.lifecycle_status')->orWhereNotIn('po.lifecycle_status', [
                    PoLifecycle::CANCELLED, PoLifecycle::SHORT_CLOSED, PoLifecycle::FULLY_RECEIVED,
                ]);
            })
            ->selectRaw('COALESCE(pod.location_id, po.location_id) as location_id, pod.product_id,
                GREATEST(0, pod.quantity_ordered * COALESCE(pod.pieces_per_order_unit, 1)
                - COALESCE(recv.received, 0) - pod.short_closed_quantity) as remaining')->get();
        $out = [];
        foreach ($lines as $line) {
            if ($line->location_id !== null) {
                $loc = (int) $line->location_id;
                $pid = (int) $line->product_id;
                $out[$loc][$pid] = ($out[$loc][$pid] ?? 0) + (int) $line->remaining;
            }
        }

        return $out;
    }
}
