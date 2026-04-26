<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TblTransferTracking;
use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class TransferController extends Controller
{
    use ApiResponse;

    private function transferStatusId(string $statusName): ?int
    {
        $id = DB::table('status_lookup')
            ->where('status_category', 'transfer')
            ->where('status_name', $statusName)
            ->value('status_id');
        if ($id !== null) {
            return (int) $id;
        }
        return DB::table('status_lookup')->where('status_name', $statusName)->value('status_id');
    }

    public function index(Request $request)
    {
        try {
            $query = Transfer::with(['fromLocation', 'toLocation', 'requestedBy', 'approvedBy', 'receivedBy', 'status', 'details.product']);

            if ($request->has('from_location_id')) {
                $query->where('from_location_id', $request->from_location_id);
            }
            if ($request->has('to_location_id')) {
                $query->where('to_location_id', $request->to_location_id);
            }
            if ($request->has('status_id')) {
                $query->where('status_id', $request->status_id);
            }
            if ($request->has('search')) {
                $query->where('transfer_number', 'like', '%' . $request->search . '%');
            }

            $perPage = $request->get('per_page', 20);
            return $this->paginated($query->orderBy('created_at', 'desc')->paginate($perPage), 'Transfers retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve transfers: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'from_location_id'            => 'required|exists:locations,location_id',
                'to_location_id'              => 'required|different:from_location_id|exists:locations,location_id',
                'transfer_number'             => 'required|string|max:50|unique:transfers,transfer_number',
                'transfer_date'               => 'required|date',
                'requested_by'                => 'nullable|exists:users,user_id',
                'approved_by'                 => 'nullable|exists:users,user_id',
                'received_by'                 => 'nullable|exists:users,user_id',
                'status_id'                   => 'nullable|exists:status_lookup,status_id',
                'ship_only'                   => 'sometimes|boolean',
                'details'                     => 'required|array|min:1',
                'details.*.product_id'        => 'required|exists:products,product_id',
                'details.*.quantity_transferred' => 'required|integer|min:1',
            ]);

            $shipOnly = (bool) ($validated['ship_only'] ?? false);
            unset($validated['ship_only']);

            DB::beginTransaction();

            if ($shipOnly) {
                $inTransitId = $this->transferStatusId('In Transit');
                if ($inTransitId && empty($validated['status_id'])) {
                    $validated['status_id'] = $inTransitId;
                }
                $validated['received_by'] = null;
            }

            $transfer = Transfer::create(Arr::except($validated, ['details']));

            $total = 0;
            $totalReceived = 0;
            foreach ($validated['details'] as $detail) {
                $qty = (int) ($detail['quantity_transferred'] ?? 0);
                $total += $qty;

                $detailPayload = array_merge($detail, [
                    'quantity_received' => $shipOnly ? 0 : $qty,
                ]);
                $transfer->details()->create($detailPayload);

                // Deduct from source location
                $fromInventory = Inventory::where('location_id', $validated['from_location_id'])
                    ->where('product_id', $detail['product_id'])
                    ->first();
                if (!$fromInventory) {
                    throw new \RuntimeException('Source inventory record not found for selected location/product.');
                }
                $available = (int) ($fromInventory->available_quantity ?? $fromInventory->quantity_on_hand ?? 0);
                if ($available < $qty) {
                    throw new \RuntimeException("Insufficient stock for transfer product_id {$detail['product_id']}.");
                }
                $fromInventory->quantity_on_hand = max(0, (int) $fromInventory->quantity_on_hand - $qty);
                $fromInventory->available_quantity = max(0, (int) $fromInventory->available_quantity - $qty);
                $fromInventory->save();

                if (!$shipOnly) {
                    // Add to destination location (immediate transfer)
                    $toInventory = Inventory::firstOrCreate(
                        [
                            'location_id' => $validated['to_location_id'],
                            'product_id' => $detail['product_id'],
                        ],
                        [
                            'quantity_on_hand' => 0,
                            'available_quantity' => 0,
                            'reorder_level' => 0,
                        ]
                    );
                    $toInventory->quantity_on_hand = max(0, (int) $toInventory->quantity_on_hand + $qty);
                    $toInventory->available_quantity = max(0, (int) $toInventory->available_quantity + $qty);
                    $toInventory->save();
                    $totalReceived += $qty;
                }
            }

            if (Schema::hasColumn('transfers', 'total_quantity_transferred')) {
                $transfer->update(['total_quantity_transferred' => $total]);
            }
            if (Schema::hasColumn('transfers', 'total_quantity_received')) {
                $transfer->update(['total_quantity_received' => $totalReceived]);
            }

            DB::commit();

            $productIds = array_map(fn ($d) => (int) $d['product_id'], $validated['details']);
            Product::syncQuantityFromInventoryMany($productIds);

            return $this->success($transfer->load(['fromLocation', 'toLocation', 'details.product']), 'Transfer created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create transfer: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $transfer = Transfer::with(['fromLocation', 'toLocation', 'requestedBy', 'approvedBy', 'receivedBy', 'status', 'details.product', 'transferTracking'])->findOrFail($id);
            return $this->success($transfer, 'Transfer retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Transfer not found', 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $transfer = Transfer::with(['details'])->findOrFail($id);

            $validated = $request->validate([
                'from_location_id' => 'sometimes|exists:locations,location_id',
                'to_location_id'   => 'sometimes|different:from_location_id|exists:locations,location_id',
                'transfer_date'    => 'sometimes|date',
                'transfer_number'  => 'sometimes|string|max:50|unique:transfers,transfer_number,' . $transfer->transfer_id . ',transfer_id',
                'transfer_date'   => 'sometimes|date',
                'requested_by'    => 'nullable|exists:users,user_id',
                'approved_by'     => 'nullable|exists:users,user_id',
                'received_by'     => 'nullable|exists:users,user_id',
                'status_id'       => 'nullable|exists:status_lookup,status_id',
                'details'                     => 'sometimes|array|min:1',
                'details.*.product_id'        => 'required_with:details|exists:products,product_id',
                'details.*.quantity_transferred' => 'required_with:details|integer|min:1',
            ]);

            if (array_key_exists('details', $validated)) {
                foreach ($transfer->details as $oldDetail) {
                    if ((int) ($oldDetail->quantity_received ?? 0) > 0) {
                        return $this->error(
                            'Cannot change line items after stock has been received at the destination.',
                            422
                        );
                    }
                }
            }

            DB::beginTransaction();

            $fromId = (int) ($validated['from_location_id'] ?? $transfer->from_location_id);
            $toId   = (int) ($validated['to_location_id']   ?? $transfer->to_location_id);

            // If details are being changed, reverse previous inventory movement first,
            // then apply the new details. This keeps inventory consistent.
            if (array_key_exists('details', $validated)) {
                foreach ($transfer->details as $oldDetail) {
                    $qtyTrans = (int) ($oldDetail->quantity_transferred ?? 0);
                    $qtyRecv = (int) ($oldDetail->quantity_received ?? 0);
                    if ($qtyTrans <= 0) {
                        continue;
                    }

                    $srcInv = Inventory::firstOrCreate(
                        ['location_id' => $transfer->from_location_id, 'product_id' => $oldDetail->product_id],
                        ['quantity_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0]
                    );
                    $srcInv->quantity_on_hand = (int) $srcInv->quantity_on_hand + $qtyTrans;
                    $srcInv->available_quantity = (int) $srcInv->available_quantity + $qtyTrans;
                    $srcInv->save();

                    if ($qtyRecv > 0) {
                        $dstInv = Inventory::where('location_id', $transfer->to_location_id)
                            ->where('product_id', $oldDetail->product_id)
                            ->first();
                        if ($dstInv) {
                            $dstInv->quantity_on_hand = max(0, (int) $dstInv->quantity_on_hand - $qtyRecv);
                            $dstInv->available_quantity = max(0, (int) $dstInv->available_quantity - $qtyRecv);
                            $dstInv->save();
                        }
                    }
                }

                $transfer->details()->delete();

                $mergedStatusId = isset($validated['status_id']) ? (int) $validated['status_id'] : (int) $transfer->status_id;
                $inTransitId = $this->transferStatusId('In Transit');
                $awaiting = $inTransitId && $mergedStatusId === (int) $inTransitId;

                $total = 0;
                $totalReceived = 0;
                foreach ($validated['details'] as $detail) {
                    $qty = (int) ($detail['quantity_transferred'] ?? 0);
                    $total += $qty;

                    $detailRow = array_merge($detail, [
                        'quantity_received' => $awaiting ? 0 : $qty,
                    ]);
                    $transfer->details()->create($detailRow);

                    $fromInventory = Inventory::where('location_id', $fromId)
                        ->where('product_id', $detail['product_id'])
                        ->first();
                    if (!$fromInventory) {
                        throw new \RuntimeException('Source inventory record not found for selected location/product.');
                    }
                    $available = (int) ($fromInventory->available_quantity ?? $fromInventory->quantity_on_hand ?? 0);
                    if ($available < $qty) {
                        throw new \RuntimeException("Insufficient stock for transfer product_id {$detail['product_id']}.");
                    }
                    $fromInventory->quantity_on_hand = max(0, (int) $fromInventory->quantity_on_hand - $qty);
                    $fromInventory->available_quantity = max(0, (int) $fromInventory->available_quantity - $qty);
                    $fromInventory->save();

                    if (!$awaiting) {
                        $toInventory = Inventory::firstOrCreate(
                            ['location_id' => $toId, 'product_id' => $detail['product_id']],
                            ['quantity_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0]
                        );
                        $toInventory->quantity_on_hand = (int) $toInventory->quantity_on_hand + $qty;
                        $toInventory->available_quantity = (int) $toInventory->available_quantity + $qty;
                        $toInventory->save();
                        $totalReceived += $qty;
                    }
                }

                if (Schema::hasColumn('transfers', 'total_quantity_transferred')) {
                    $transfer->total_quantity_transferred = $total;
                }
                if (Schema::hasColumn('transfers', 'total_quantity_received')) {
                    $transfer->total_quantity_received = $totalReceived;
                }
            }

            // Update header fields last
            $transfer->update(Arr::except($validated, ['details']));

            DB::commit();

            $transfer->refresh()->load('details');
            $pids = $transfer->details->pluck('product_id')->map(fn ($x) => (int) $x)->unique()->values()->all();
            Product::syncQuantityFromInventoryMany($pids);

            return $this->success($transfer->load(['fromLocation', 'toLocation', 'details.product']), 'Transfer updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to update transfer: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $transfer = Transfer::with(['details'])->findOrFail($id);

            $productIds = $transfer->details->pluck('product_id')->map(fn ($x) => (int) $x)->unique()->values()->all();

            DB::beginTransaction();

            // Reverse inventory movement on delete (restore source by shipped qty; remove only what was received at dest)
            foreach ($transfer->details as $detail) {
                $qtyTrans = (int) ($detail->quantity_transferred ?? 0);
                $qtyRecv = (int) ($detail->quantity_received ?? 0);
                if ($qtyTrans <= 0) {
                    continue;
                }

                $srcInv = Inventory::firstOrCreate(
                    ['location_id' => $transfer->from_location_id, 'product_id' => $detail->product_id],
                    ['quantity_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0]
                );
                $srcInv->quantity_on_hand = (int) $srcInv->quantity_on_hand + $qtyTrans;
                $srcInv->available_quantity = (int) $srcInv->available_quantity + $qtyTrans;
                $srcInv->save();

                if ($qtyRecv > 0) {
                    $dstInv = Inventory::where('location_id', $transfer->to_location_id)
                        ->where('product_id', $detail->product_id)
                        ->first();
                    if ($dstInv) {
                        $dstInv->quantity_on_hand = max(0, (int) $dstInv->quantity_on_hand - $qtyRecv);
                        $dstInv->available_quantity = max(0, (int) $dstInv->available_quantity - $qtyRecv);
                        $dstInv->save();
                    }
                }
            }

            $transfer->details()->delete();
            $transfer->delete();

            DB::commit();

            Product::syncQuantityFromInventoryMany($productIds);

            return $this->success(null, 'Transfer deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to delete transfer: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Record receipt at destination (showroom) for a ship-first transfer. Increments destination inventory.
     */
    public function receive(Request $request, $id)
    {
        try {
            $transfer = Transfer::with(['details.product'])->findOrFail($id);

            $validated = $request->validate([
                'transfer_detail_id' => 'nullable|exists:transfer_details,transfer_detail_id',
                'barcode'            => 'nullable|string|max:100',
                'quantity'           => 'sometimes|integer|min:1',
                'received_by'        => 'nullable|exists:users,user_id',
            ]);

            if (empty($validated['transfer_detail_id']) && empty($validated['barcode'])) {
                return $this->error('Provide transfer_detail_id or barcode to receive stock.', 422);
            }

            $qtyReq = (int) ($validated['quantity'] ?? 1);
            if ($qtyReq < 1) {
                $qtyReq = 1;
            }

            DB::beginTransaction();

            $detail = null;
            if (!empty($validated['transfer_detail_id'])) {
                $detail = TransferDetail::where('transfer_detail_id', $validated['transfer_detail_id'])
                    ->where('transfer_id', $transfer->transfer_id)
                    ->first();
                if (!$detail) {
                    DB::rollBack();
                    return $this->error('Line item does not belong to this transfer.', 422);
                }
            } else {
                $bc = trim((string) $validated['barcode']);
                $product = Product::where(function ($q) use ($bc) {
                    $q->where('barcode', $bc)->orWhere('product_code', $bc);
                })->first();
                if (!$product) {
                    DB::rollBack();
                    return $this->error('No product matches this barcode or model code.', 404);
                }
                $detail = TransferDetail::where('transfer_id', $transfer->transfer_id)
                    ->where('product_id', $product->product_id)
                    ->whereRaw('quantity_transferred > COALESCE(quantity_received, 0)')
                    ->orderBy('transfer_detail_id')
                    ->first();
                if (!$detail) {
                    DB::rollBack();
                    return $this->error('This product is not on this transfer.', 422);
                }
            }

            $transferred = (int) $detail->quantity_transferred;
            $already = (int) ($detail->quantity_received ?? 0);
            $remaining = $transferred - $already;
            if ($remaining <= 0) {
                DB::rollBack();
                return $this->error('This line is already fully received.', 422);
            }

            $add = min($qtyReq, $remaining);
            $toInventory = Inventory::firstOrCreate(
                [
                    'location_id' => $transfer->to_location_id,
                    'product_id' => $detail->product_id,
                ],
                [
                    'quantity_on_hand' => 0,
                    'available_quantity' => 0,
                    'reorder_level' => 0,
                ]
            );
            $toInventory->quantity_on_hand = (int) $toInventory->quantity_on_hand + $add;
            $toInventory->available_quantity = (int) $toInventory->available_quantity + $add;
            $toInventory->save();

            $detail->quantity_received = $already + $add;
            $detail->save();

            $sumReceived = (int) $transfer->details()->sum('quantity_received');
            if (Schema::hasColumn('transfers', 'total_quantity_received')) {
                $transfer->total_quantity_received = $sumReceived;
            }

            $receiverId = $validated['received_by'] ?? $request->user()?->user_id;
            if ($receiverId && Schema::hasColumn('transfers', 'received_by')) {
                $transfer->received_by = $receiverId;
            }

            $totalShipped = (int) $transfer->details()->sum('quantity_transferred');
            if ($sumReceived >= $totalShipped && $totalShipped > 0) {
                $receivedStatusId = $this->transferStatusId('Received');
                if ($receivedStatusId) {
                    $transfer->status_id = $receivedStatusId;
                }
            }

            $transfer->save();

            DB::commit();

            Product::syncQuantityFromInventoryMany([(int) $detail->product_id]);

            $transfer->refresh()->load(['fromLocation', 'toLocation', 'receivedBy', 'status', 'details.product']);

            return $this->success($transfer, 'Receipt recorded successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to record receipt: ' . $e->getMessage(), 500);
        }
    }

    public function addTracking(Request $request, $id)
    {
        try {
            $transfer = Transfer::findOrFail($id);

            $validated = $request->validate([
                'location_id' => 'nullable|exists:locations,location_id',
                'status_note' => 'required|string|max:255',
                'recorded_at' => 'nullable|date',
                'recorded_by' => 'nullable|exists:users,user_id',
            ]);

            $validated['transfer_id'] = $transfer->transfer_id;
            if (!isset($validated['recorded_at'])) {
                $validated['recorded_at'] = now();
            }

            $tracking = TblTransferTracking::create($validated);

            return $this->success($tracking, 'Transfer tracking added successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to add tracking: ' . $e->getMessage(), 500);
        }
    }
}
