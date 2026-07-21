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

    /**
     * GET /api/po-inventory-groups/{po}?location_id={warehouseId}
     * The 3-tab detail for one (PO, warehouse) group.
     */
    public function show(Request $request, $po)
    {
        $poId       = (int) $po;
        $locationId = (int) $request->query('location_id');

        // Group batches (scoped to the user) for this PO + warehouse.
        $batches = InventoryBatch::forUser($request->user())
            ->where('location_id', $locationId)
            ->whereNotNull('receiving_id')
            ->whereHas('receiving', fn ($q) => $q->where('po_id', $poId))
            ->with(['product', 'location', 'receiving.purchaseOrder.supplier'])
            ->orderBy('received_at', 'asc')
            ->get();

        if ($batches->isEmpty()) {
            return $this->notFound('No inventory found for this PO at this warehouse');
        }

        $po0        = $batches->first()->receiving?->purchaseOrder;
        $warehouse  = $batches->first()->location;
        $zoneLabels = $batches->pluck('zone_label')->filter()->unique()->values()->all();

        // Overview: per-product totals (FIFO order preserved by batch order).
        $products = [];
        foreach ($batches as $b) {
            $pid = $b->product_id;
            if (! isset($products[$pid])) {
                $products[$pid] = [
                    'product_id'         => $pid,
                    'product_name'       => $b->product?->product_name,
                    'quantity_received'  => 0,
                    'quantity_remaining' => 0,
                ];
            }
            $products[$pid]['quantity_received']  += (int) $b->quantity_received;
            $products[$pid]['quantity_remaining'] += (int) $b->quantity_remaining;
        }
        $products = array_values($products);

        $overview = [
            'po_number'      => $po0?->po_number,
            'supplier_name'  => $po0?->supplier?->supplier_name,
            'order_date'     => $po0?->order_date?->toDateString(),
            'warehouse_name' => $warehouse?->location_name,
            'location_label' => empty($zoneLabels) ? null : implode(', ', $zoneLabels),
            'totals'         => [
                'item_count'      => count($products),
                'total_received'  => array_sum(array_column($products, 'quantity_received')),
                'total_remaining' => array_sum(array_column($products, 'quantity_remaining')),
            ],
            'products'       => $products,
        ];

        // Receiving events for this PO at this warehouse.
        $receivingHistory = DB::table('receiving_details')
            ->join('receivings', 'receivings.receiving_id', '=', 'receiving_details.receiving_id')
            ->join('products', 'products.product_id', '=', 'receiving_details.product_id')
            ->leftJoin('users', 'users.user_id', '=', 'receivings.received_by')
            ->where('receivings.po_id', $poId)
            ->where('receivings.location_id', $locationId)
            ->orderBy('receiving_details.created_at', 'asc')
            ->get([
                'receivings.receiving_number',
                'receivings.receiving_date as received_at',
                DB::raw("CONCAT(users.first_name, ' ', users.last_name) AS received_by_name"),
                'products.product_name',
                'receiving_details.quantity_amount',
                'receiving_details.scanned_barcode',
            ]);

        // Serials scoped to this PO + warehouse, grouped by product, FIFO order.
        $receivingIds = DB::table('receivings')
            ->where('po_id', $poId)->where('location_id', $locationId)
            ->pluck('receiving_id')->all();

        $serialsByProduct = [];
        if (! empty($receivingIds)) {
            ItemSerial::query()
                ->whereIn('item_serial.receiving_id', $receivingIds)
                ->where('item_serial.location_id', $locationId)
                ->where('item_serial.serial_type', 'received')
                ->join('products', 'products.product_id', '=', 'item_serial.product_id')
                ->orderBy('item_serial.created_at', 'asc')
                ->get(['item_serial.serial_number', 'item_serial.product_id', 'products.product_name'])
                ->each(function ($s) use (&$serialsByProduct) {
                    $serialsByProduct[$s->product_id]['product_id']   = $s->product_id;
                    $serialsByProduct[$s->product_id]['product_name'] = $s->product_name;
                    $serialsByProduct[$s->product_id]['serials'][]     = $s->serial_number;
                });

            DB::table('receiving_details')
                ->join('products', 'products.product_id', '=', 'receiving_details.product_id')
                ->whereIn('receiving_details.receiving_id', $receivingIds)
                ->whereNotNull('receiving_details.scanned_barcode')
                ->orderBy('receiving_details.created_at', 'asc')
                ->get(['receiving_details.scanned_barcode', 'receiving_details.product_id', 'products.product_name'])
                ->each(function ($r) use (&$serialsByProduct) {
                    // Only consumables land here (appliances already covered above); avoid
                    // double-listing a product that produced item_serial rows.
                    if (isset($serialsByProduct[$r->product_id])) {
                        return;
                    }
                    $serialsByProduct[$r->product_id]['product_id']   = $r->product_id;
                    $serialsByProduct[$r->product_id]['product_name'] = $r->product_name;
                    $serialsByProduct[$r->product_id]['serials'][]     = $r->scanned_barcode;
                });
        }

        return $this->success([
            'overview'          => $overview,
            'receiving_history' => $receivingHistory,
            'serials'           => array_values($serialsByProduct),
        ], 'PO inventory group detail retrieved successfully');
    }
}
