<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use Illuminate\Support\Facades\DB;

class PoLineBalanceService
{
    /** Historical receipts stay authoritative; no mutable received counter. */
    public function forPo(PurchaseOrder $po): array
    {
        $lines = $po->details()->get();
        $received = DB::table('receiving_details as rd')
            ->join('receivings as r', 'r.receiving_id', '=', 'rd.receiving_id')
            ->join('purchase_order_details as pod', 'pod.po_detail_id', '=', 'rd.po_detail_id')
            ->whereColumn('rd.product_id', 'pod.product_id')
            ->where('r.po_id', $po->po_id)
            ->whereIn('rd.po_detail_id', $lines->pluck('po_detail_id'))
            ->groupBy('rd.po_detail_id')
            ->selectRaw('rd.po_detail_id, SUM(rd.quantity_amount) as received')
            ->pluck('received', 'po_detail_id');

        return $lines->mapWithKeys(fn ($line) => [
            $line->po_detail_id => $this->balance($line, (int) ($received[$line->po_detail_id] ?? 0)),
        ])->all();
    }

    public function balance(PurchaseOrderDetail $line, int $received): array
    {
        $size = max(1, (int) ($line->pieces_per_order_unit ?? 1));
        $ordered = (int) $line->quantity_ordered * $size;
        $closed = (int) $line->short_closed_quantity;

        return [
            'ordered' => $ordered,
            'received' => $received,
            'short_closed' => $closed,
            'remaining' => max(0, $ordered - $received - $closed),
            'over_received' => max(0, $received + $closed - $ordered),
            'quantity_unit' => 'piece',
            'order_unit' => $line->order_unit,
            'pieces_per_order_unit' => $size,
            'snapshot_source' => $line->quantity_snapshot_source,
        ];
    }
}
