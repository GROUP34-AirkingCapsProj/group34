<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AttachesGeneratedTags;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PoInventoryController extends Controller
{
    use ApiResponse;
    use AttachesGeneratedTags;

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
                    'warehouse_type'    => $b->location?->location_type,
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
                'receiving_details.receiving_id',
                'receiving_details.product_id',
                'receiving_details.quantity_amount',
                'receiving_details.scanned_barcode',
            ]);

        // Same fix as the per-SKU detail modal: a counted line carries no
        // supplier barcode, so without its generated tags the identifier
        // column renders empty.
        $this->attachGeneratedTags($receivingHistory);

        // Serials scoped to this PO + warehouse, as a flat FIFO list with per-serial
        // metadata (mirrors the per-SKU detail modal's serial table).
        $receivingIds = DB::table('receivings')
            ->where('po_id', $poId)->where('location_id', $locationId)
            ->pluck('receiving_id')->all();

        $serials = collect();
        if (! empty($receivingIds)) {
            // Appliances: unit-level rows from item_serial.
            $appliance = ItemSerial::query()
                ->whereIn('item_serial.receiving_id', $receivingIds)
                ->where('item_serial.location_id', $locationId)
                ->where('item_serial.serial_type', 'received')
                ->join('products', 'products.product_id', '=', 'item_serial.product_id')
                ->leftJoin('users as scanner', 'scanner.user_id', '=', 'item_serial.scanned_by')
                ->leftJoin('status_lookup', 'status_lookup.status_id', '=', 'item_serial.status_id')
                ->orderBy('item_serial.created_at', 'asc')
                ->get([
                    'item_serial.serial_number',
                    'item_serial.product_id',
                    'products.product_name',
                    'item_serial.created_at as scanned_at',
                    DB::raw("CONCAT(scanner.first_name, ' ', scanner.last_name) AS scanned_by_name"),
                    'status_lookup.status_name as serial_status',
                ]);

            // Consumables: scanned barcodes from receiving_details, excluding products that
            // already produced item_serial rows (appliances also carry a scanned_barcode).
            $applianceProductIds = $appliance->pluck('product_id')->unique()->all();
            $consumable = DB::table('receiving_details')
                ->join('products', 'products.product_id', '=', 'receiving_details.product_id')
                ->join('receivings', 'receivings.receiving_id', '=', 'receiving_details.receiving_id')
                ->leftJoin('users as scanner', 'scanner.user_id', '=', 'receivings.received_by')
                ->whereIn('receiving_details.receiving_id', $receivingIds)
                ->whereNotNull('receiving_details.scanned_barcode')
                ->when(! empty($applianceProductIds), fn ($q) => $q->whereNotIn('receiving_details.product_id', $applianceProductIds))
                ->orderBy('receiving_details.created_at', 'asc')
                ->get([
                    'receiving_details.scanned_barcode as serial_number',
                    'products.product_name',
                    'receiving_details.created_at as scanned_at',
                    DB::raw("CONCAT(scanner.first_name, ' ', scanner.last_name) AS scanned_by_name"),
                    DB::raw("'Received' as serial_status"),
                ]);

            $serials = $appliance->concat($consumable)
                ->sortBy('scanned_at')
                ->map(fn ($s) => [
                    'serial_number'   => $s->serial_number,
                    'product_name'    => $s->product_name,
                    'serial_status'   => $s->serial_status ?? 'In Stock',
                    'scanned_by_name' => trim((string) ($s->scanned_by_name ?? '')) ?: null,
                    'scanned_at'      => $s->scanned_at,
                ])
                ->values();
        }

        $batchIds = $batches->pluck('batch_id')->all();
        $movements = DB::table('inventory_batch_movements as m')
            ->join('products as p', 'p.product_id', '=', 'm.product_id')
            ->leftJoin('transfers as t', 't.transfer_id', '=', 'm.transfer_id')
            ->leftJoin('issuances as i', 'i.issuance_id', '=', 'm.issuance_id')
            ->leftJoin('locations as destination', 'destination.location_id', '=', 'm.to_location_id')
            ->leftJoin('users as performer', 'performer.user_id', '=', 'm.performed_by')
            ->whereIn('m.batch_id', $batchIds)
            ->orderBy('m.moved_at', 'asc')
            ->get([
                'm.batch_movement_id', 'm.batch_id', 'm.movement_type',
                'm.quantity', 'm.serial_number', 'm.moved_at',
                'p.product_name', 't.transfer_number', 'i.dr_number',
                'destination.location_name as to_location',
                DB::raw("TRIM(CONCAT(COALESCE(performer.first_name,''), ' ', COALESCE(performer.last_name,''))) as performed_by"),
            ])
            ->map(fn ($movement) => [
                'movement_id' => $movement->batch_movement_id,
                'batch_id' => $movement->batch_id,
                'type' => $movement->movement_type,
                'product_name' => $movement->product_name,
                'quantity' => (int) $movement->quantity,
                'serial_number' => $movement->serial_number,
                'reference' => $movement->transfer_number ?: $movement->dr_number,
                'to_location' => $movement->to_location,
                'performed_by' => $movement->performed_by ?: null,
                'moved_at' => $movement->moved_at,
            ]);

        return $this->success([
            'overview'          => $overview,
            'receiving_history' => $receivingHistory,
            'stock_out_history' => $movements->where('type', 'stock_out')->values(),
            'transfer_history'  => $movements->where('type', 'transfer')->values(),
            'serials'           => $serials,
        ], 'PO inventory group detail retrieved successfully');
    }

    /**
     * PATCH /api/po-inventory-groups/{po}?location_id={warehouseId}
     * Rename the Location (zone_label) for every batch in one (PO, warehouse) group.
     */
    public function updateLocation(Request $request, $po)
    {
        $validated = $request->validate([
            'location_id' => 'required|integer',
            'zone_label'  => 'nullable|string|max:100',
        ]);

        $poId       = (int) $po;
        $locationId = (int) $validated['location_id'];
        $zoneLabel  = $validated['zone_label'] ?? null;

        $batchIds = InventoryBatch::forUser($request->user())
            ->where('location_id', $locationId)
            ->whereHas('receiving', fn ($q) => $q->where('po_id', $poId))
            ->pluck('batch_id');

        if ($batchIds->isEmpty()) {
            return $this->notFound('No inventory found for this PO at this warehouse');
        }

        InventoryBatch::whereIn('batch_id', $batchIds)->update(['zone_label' => $zoneLabel]);

        return $this->success(['zone_label' => $zoneLabel], 'Location updated successfully');
    }
}
