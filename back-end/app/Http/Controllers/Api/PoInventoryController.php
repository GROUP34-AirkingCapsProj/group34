<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PoInventoryController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/po-inventory-groups
     * One group per (PO, warehouse), FIFO-ordered, with nested batches.
     */
    public function index(Request $request)
    {
        $batches = InventoryBatch::forUser($request->user())
            ->whereNotNull('receiving_id')
            ->with(['product', 'location', 'receiving.purchaseOrder.supplier'])
            ->orderBy('received_at', 'asc')
            ->get();

        $groups = [];
        foreach ($batches as $b) {
            $po = $b->receiving?->purchaseOrder;
            if (! $po) {
                continue;
            }
            $key = $po->po_id . '-' . $b->location_id;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'group_key'         => $key,
                    'po_id'             => $po->po_id,
                    'po_number'         => $po->po_number,
                    'warehouse_id'      => $b->location_id,
                    'warehouse_name'    => $b->location?->location_name,
                    'supplier_name'     => $po->supplier?->supplier_name,
                    'order_date'        => $po->order_date?->toDateString(),
                    'first_received_at' => $b->received_at?->toDateString(),
                    '_zone_labels'      => [],
                    '_product_ids'      => [],
                    'total_received'    => 0,
                    'total_remaining'   => 0,
                    'batches'           => [],
                ];
            }
            $g = &$groups[$key];
            if ($b->zone_label) {
                $g['_zone_labels'][$b->zone_label] = true;
            }
            $g['_product_ids'][$b->product_id] = true;
            $g['total_received']  += (int) $b->quantity_received;
            $g['total_remaining'] += (int) $b->quantity_remaining;
            $g['batches'][] = [
                'batch_id'           => $b->batch_id,
                'product_id'         => $b->product_id,
                'product_name'       => $b->product?->product_name,
                'zone_label'         => $b->zone_label,
                'quantity_received'  => $b->quantity_received,
                'quantity_remaining' => $b->quantity_remaining,
                'received_at'        => $b->received_at?->toDateString(),
            ];
            unset($g);
        }

        $data = collect($groups)->map(function ($g) {
            $g['location_label'] = empty($g['_zone_labels']) ? null : implode(', ', array_keys($g['_zone_labels']));
            $g['item_count']     = count($g['_product_ids']);
            unset($g['_zone_labels'], $g['_product_ids']);
            return $g;
        })->sortBy('first_received_at')->values();

        return $this->success($data, 'PO inventory groups retrieved successfully');
    }
}
