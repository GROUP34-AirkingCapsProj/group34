<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Support\PoLifecycle;
use Illuminate\Support\Facades\DB;

class PoLifecycleBackfillService
{
    public function run(): void
    {
        DB::transaction(function () {
            DB::table('purchase_order_details')->orderBy('po_detail_id')->chunkById(200, function ($lines) {
                foreach ($lines as $line) {
                    if ($line->pieces_per_order_unit !== null) {
                        continue;
                    }
                    $product = DB::table('products')->where('product_id', $line->product_id)->first();
                    $material = $product?->product_type === 'installation_material';
                    $size = $material
                        ? DB::table('installation_material_details')->where('product_id', $line->product_id)->value('pieces_per_package')
                        : 1;
                    DB::table('purchase_order_details')->where('po_detail_id', $line->po_detail_id)->update([
                        'order_unit' => $material ? 'package' : 'piece',
                        'pieces_per_order_unit' => max(1, (int) $size),
                        'quantity_snapshot_source' => 'legacy_product_master',
                    ]);
                    if ($material || ! $product) {
                        $this->record($line->po_id, $line->po_detail_id, null,
                            $product ? 'historical_package_size_unverified' : 'missing_product',
                            ['assumed_pieces_per_order_unit' => max(1, (int) $size)]);
                    }
                }
            }, 'po_detail_id');

            DB::table('receiving_details as rd')->join('receivings as r', 'r.receiving_id', '=', 'rd.receiving_id')
                ->leftJoin('purchase_order_details as pod', 'pod.po_detail_id', '=', 'rd.po_detail_id')
                ->whereNotNull('r.po_id')->select('rd.*', 'r.po_id as receipt_po_id', 'pod.po_id as line_po_id',
                    'pod.product_id as line_product_id')->orderBy('rd.receiving_detail_id')->chunk(200, function ($rows) {
                        foreach ($rows as $row) {
                            if ($row->line_po_id === null || (int) $row->line_po_id !== (int) $row->receipt_po_id
                                || (int) $row->line_product_id !== (int) $row->product_id || $row->quantity_amount < 0) {
                                $this->record($row->receipt_po_id, $row->po_detail_id, $row->receiving_detail_id,
                                    'receipt_requires_reconciliation', ['quantity' => $row->quantity_amount,
                                        'line_po_id' => $row->line_po_id, 'product_id' => $row->product_id]);
                            }
                        }
                    });

            PurchaseOrder::withTrashed()->orderBy('po_id')->chunkById(100, function ($pos) {
                foreach ($pos as $po) {
                    $balances = app(PoLineBalanceService::class)->forPo($po);
                    $statusName = strtolower((string) DB::table('status_lookup')->where('status_id', $po->status_id)->value('status_name'));
                    $state = PoLifecycle::compute(array_values($balances), in_array((int) $po->status_id, [8, 10, 11, 12], true),
                        $statusName === 'cancelled');
                    $updates = ['lifecycle_status' => $state];
                    if ($po->lifecycle_status === null) {
                        $updates['original_expected_delivery_date'] = $po->expected_delivery_date?->toDateString();
                        if ($po->expected_delivery_date !== null) {
                            $this->record($po->po_id, null, null, 'original_delivery_date_unverified',
                                ['baseline_date' => $po->expected_delivery_date->toDateString()]);
                        }
                    }
                    DB::table('purchase_orders')->where('po_id', $po->po_id)->update($updates);
                    foreach ($balances as $id => $balance) {
                        if ($balance['over_received'] > 0) {
                            $this->record($po->po_id, $id, null, 'over_received', $balance);
                        }
                    }
                    if ($balances === [] || ((int) $po->status_id === 11 && $state !== PoLifecycle::FULLY_RECEIVED)
                        || ((int) $po->status_id === 9 && array_sum(array_column($balances, 'received')) > 0)) {
                        $this->record($po->po_id, null, null, 'legacy_status_inconsistent',
                            ['legacy_status_id' => $po->status_id, 'computed_status' => $state]);
                    }
                }
            }, 'po_id');
        });
    }

    private function record(?int $poId, ?int $lineId, ?int $receiptId, string $code, array $context): void
    {
        if (DB::table('po_lifecycle_exceptions')->where([
            'po_id' => $poId, 'po_detail_id' => $lineId, 'receiving_detail_id' => $receiptId, 'code' => $code,
        ])->exists()) {
            return;
        }
        DB::table('po_lifecycle_exceptions')->insert([
            'po_id' => $poId, 'po_detail_id' => $lineId, 'receiving_detail_id' => $receiptId,
            'code' => $code, 'context' => json_encode($context, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
