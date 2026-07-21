<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryBatch;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class InventoryBatchController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/inventory-batches
     *
     * All batches with remaining stock, scoped to the authenticated user's
     * location (centralized users see every location). Powers the
     * Warehouse Map tab on the Inventory Management page.
     */
    public function index(Request $request)
    {
        $batches = InventoryBatch::forUser($request->user())
            ->where('quantity_remaining', '>', 0)
            ->with(['product', 'receiving.purchaseOrder'])
            ->orderBy('received_at', 'asc')
            ->get();

        $serialMap = app(\App\Services\BatchSerialResolver::class)->resolve($batches);

        $data = $batches->map(function ($batch) use ($serialMap) {
            return [
                'batch_id'           => $batch->batch_id,
                'product_id'         => $batch->product_id,
                'product_name'       => $batch->product?->product_name,
                'location_id'        => $batch->location_id,
                'receiving_id'       => $batch->receiving_id,
                'po_number'          => $batch->receiving?->purchaseOrder?->po_number,
                'zone_label'         => $batch->zone_label,
                'received_at'        => $batch->received_at?->toDateString(),
                'quantity_received'  => $batch->quantity_received,
                'quantity_remaining' => $batch->quantity_remaining,
                'is_legacy'          => $batch->receiving_id === null,
                'serials'            => $serialMap[$batch->batch_id] ?? [],
            ];
        });

        return $this->success($data, 'Warehouse batches retrieved successfully');
    }

    /**
     * PATCH /api/inventory-batches/{batch}
     *
     * Edits a batch's physical zone label only. Never touches quantity
     * fields — zone_label is descriptive metadata, not part of FIFO
     * deduction/restoration.
     */
    public function update(Request $request, InventoryBatch $batch)
    {
        $validated = $request->validate([
            'zone_label' => 'nullable|string|max:100',
        ]);

        $batch->zone_label = $validated['zone_label'] ?? null;
        $batch->save();

        return $this->success([
            'batch_id'   => $batch->batch_id,
            'zone_label' => $batch->zone_label,
        ], 'Zone label updated successfully');
    }
}
