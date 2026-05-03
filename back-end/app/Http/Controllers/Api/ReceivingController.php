<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrderDetail;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;

class ReceivingController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $query = Receiving::with(['purchaseOrder.details', 'purchaseOrder.supplier', 'location', 'receivedBy', 'details.product']);

            if ($request->has('location_id')) {
                $query->where('location_id', $request->location_id);
            }
            if ($request->has('status_id')) {
                $query->where('status_id', $request->status_id);
            }
            if ($request->has('search')) {
                $query->where('receiving_number', 'like', '%' . $request->search . '%');
            }

            $perPage = $request->get('per_page', 20);
            return $this->paginated($query->orderBy('created_at', 'desc')->paginate($perPage), 'Receivings retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve receivings: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'pc_id'                   => 'required|exists:purchase_orders,po_id',
                'location_id'             => 'required|exists:locations,location_id',
                'receiving_number'        => 'required|string|max:50|unique:receivings,receiving_number',
                'receiving_date'          => 'required|date',
                'received_by'             => 'nullable|exists:users,user_id',
                'total_quantity_received' => 'nullable|integer|min:0',
                'total_quantity_damaged'  => 'nullable|integer|min:0',
                'profit_loss_id'          => 'nullable|exists:profit_loss,profit_loss_id',
                'status_id'               => 'nullable|exists:status_lookup,status_id',
                'details'                 => 'required|array|min:1',
                'details.*.po_detail_id'  => 'nullable|exists:purchase_order_details,po_detail_id',
                'details.*.product_id'    => 'required|exists:products,product_id',
                'details.*.prod_price'    => 'nullable|numeric|min:0',
                'details.*.quantity_amount' => 'required|integer|min:0',
                'details.*.condition'     => 'nullable|string|max:100',
            ]);

            DB::beginTransaction();

            $receiving = Receiving::create(Arr::except($validated, ['details']));

            $totalReceived = 0;
            foreach ($validated['details'] as $detail) {
                $receiving->details()->create($detail);
                $qty = (int) ($detail['quantity_amount'] ?? 0);
                $totalReceived += $qty;

                // Increase inventory at receiving location
                $inventory = Inventory::firstOrCreate(
                    [
                        'location_id' => $validated['location_id'],
                        'product_id' => $detail['product_id'],
                    ],
                    [
                        'quantity_on_hand' => 0,
                        'available_quantity' => 0,
                        'reorder_level' => 0,
                    ]
                );

                $inventory->quantity_on_hand = max(0, (int) $inventory->quantity_on_hand + $qty);
                $inventory->available_quantity = max(0, (int) $inventory->available_quantity + $qty);
                $inventory->save();
            }

            // Update totals if not explicitly provided
            if (!isset($validated['total_quantity_received'])) {
                $receiving->update(['total_quantity_received' => $totalReceived]);
            }

            DB::commit();

            $productIds = array_map(fn ($d) => (int) $d['product_id'], $validated['details']);
            Product::syncQuantityFromInventoryMany($productIds);

            return $this->success($receiving->load(['purchaseOrder', 'location', 'details.product']), 'Receiving created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create receiving: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $receiving = Receiving::with(['purchaseOrder.supplier', 'location', 'receivedBy', 'details.product'])->findOrFail($id);
            return $this->success($receiving, 'Receiving retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Receiving not found', 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $receiving = Receiving::findOrFail($id);

            $validated = $request->validate([
                'receiving_date'          => 'sometimes|date',
                'received_by'             => 'nullable|exists:users,user_id',
                'total_quantity_received' => 'nullable|integer|min:0',
                'total_quantity_damaged'  => 'nullable|integer|min:0',
                'profit_loss_id'          => 'nullable|exists:profit_loss,profit_loss_id',
                'status_id'               => 'nullable|exists:status_lookup,status_id',
            ]);

            $receiving->update($validated);

            return $this->success($receiving->load(['purchaseOrder', 'location', 'details.product']), 'Receiving updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update receiving: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $receiving = Receiving::findOrFail($id);
            $receiving->delete();
            return $this->success(null, 'Receiving deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete receiving: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Return per-line receiving status for a PO: already-received piece counts
     * and all scanned barcodes stored against each line. Used by the BSR frontend
     * to initialise progress bars from real DB values and perform pre-scan
     * duplicate checks without a full transaction round-trip.
     */
    public function poScanStatus(int $poId)
    {
        try {
            $details = PurchaseOrderDetail::with('product')
                ->where('po_id', $poId)
                ->get(['po_detail_id', 'product_id', 'quantity_ordered']);

            if ($details->isEmpty()) {
                return $this->success(['lines' => [], 'scan_history' => []], 'No line items found for this PO');
            }

            $detailIds = $details->pluck('po_detail_id')->all();

            // Sum received pieces per PO detail line
            $receivedSums = ReceivingDetail::whereIn('po_detail_id', $detailIds)
                ->groupBy('po_detail_id')
                ->selectRaw('po_detail_id, SUM(quantity_amount) as total_received')
                ->pluck('total_received', 'po_detail_id');

            // Collect all distinct non-null scanned_barcodes per line
            $scannedByLine = ReceivingDetail::whereIn('po_detail_id', $detailIds)
                ->whereNotNull('scanned_barcode')
                ->where('scanned_barcode', '!=', '')
                ->select('po_detail_id', 'scanned_barcode')
                ->get()
                ->groupBy('po_detail_id')
                ->map(fn ($rows) => $rows->pluck('scanned_barcode')->unique()->values()->all());

            $lines = $details->map(fn ($d) => [
                'po_detail_id'     => $d->po_detail_id,
                'product_id'       => $d->product_id,
                'already_received' => (int) ($receivedSums[$d->po_detail_id] ?? 0),
                'scanned_barcodes' => $scannedByLine[$d->po_detail_id] ?? [],
            ])->all();

            // Build a lookup: product_id → { name, type, pieces_per_package }
            $productInfo = $details->keyBy('product_id')->map(fn ($d) => [
                'name' => $d->product?->product_name ?? 'Unknown',
                'type' => $d->product?->product_type ?? 'consumable',
                'pcs'  => (int) ($d->product?->pieces_per_package ?? 1),
            ]);

            // Full scan history ordered oldest-first so the frontend can reverse for newest-first display.
            $scanHistory = ReceivingDetail::whereIn('po_detail_id', $detailIds)
                ->select(['receiving_detail_id', 'po_detail_id', 'product_id', 'quantity_amount', 'scanned_barcode', 'created_at'])
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($rd) use ($productInfo) {
                    $info = $productInfo[$rd->product_id] ?? ['name' => 'Unknown', 'type' => 'consumable', 'pcs' => 1];
                    return [
                        'receiving_detail_id' => $rd->receiving_detail_id,
                        'po_detail_id'        => $rd->po_detail_id,
                        'product_id'          => $rd->product_id,
                        'product_name'        => $info['name'],
                        'is_serialized'       => $info['type'] === 'appliance',
                        'scanned_barcode'     => $rd->scanned_barcode,
                        'quantity_amount'     => (int) $rd->quantity_amount,
                        'pieces_per_package'  => $info['pcs'],
                        'created_at'          => $rd->created_at?->toISOString(),
                    ];
                })
                ->all();

            return $this->success(['lines' => $lines, 'scan_history' => $scanHistory], 'PO scan status retrieved');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve PO scan status: ' . $e->getMessage(), 500);
        }
    }
}
