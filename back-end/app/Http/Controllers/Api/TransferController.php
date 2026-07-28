<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TblTransferTracking;
use App\Models\Inventory;
use App\Models\Product;
use App\Services\TransferService;
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

    private static function withRelations(): array
    {
        return ['fromLocation', 'toLocation', 'requestedBy', 'approvedBy', 'status', 'details.product'];
    }

    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $query = Transfer::with(self::withRelations())->forUser($user);

            if ($request->filled('status_id')) {
                $query->where('status_id', $request->integer('status_id'));
            }
            if ($request->filled('search')) {
                $query->where('transfer_number', 'like', '%' . $request->search . '%');
            }

            $perPage = $request->integer('per_page', 20);
            return $this->paginated(
                $query->orderBy('created_at', 'desc')->paginate($perPage),
                'Transfers retrieved'
            );
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve transfers: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request, TransferService $service)
    {
        $validated = $request->validate([
            'intent'                         => 'required|in:pull,offload,demand',
            'from_location_id'               => 'nullable|exists:locations,location_id',
            'to_location_id'                 => 'nullable|exists:locations,location_id',
            'transfer_date'                  => 'required|date',
            'notes'                          => 'nullable|string|max:500',
            'products'                       => 'required|array|min:1',
            'products.*.product_id'          => 'required|exists:products,product_id',
            'products.*.quantity_requested'  => 'required|integer|min:1',
        ]);

        $validated['from_location_id'] = $validated['from_location_id'] ?? null;
        $validated['to_location_id']   = $validated['to_location_id'] ?? null;

        // Per-intent source/destination rules:
        //  - pull:    requester names the destination; the analyst assigns the source later.
        //  - offload: requester names the source; the analyst assigns the destination later
        //             (only admin/IA can see every location's stock to decide where it goes).
        //  - demand:  the analyst names both up front (lands Approved immediately).
        if ($validated['intent'] === 'pull') {
            $validated['from_location_id'] = null;
            if (empty($validated['to_location_id'])) {
                return $this->error('A destination location is required for this request.', 422);
            }
        } elseif ($validated['intent'] === 'offload') {
            if (empty($validated['from_location_id'])) {
                return $this->error('A source location is required for this request.', 422);
            }
            // Destination is intentionally optional — the analyst assigns it at verification.
        } else { // demand
            if (empty($validated['from_location_id']) || empty($validated['to_location_id'])) {
                return $this->error('Both source and destination are required.', 422);
            }
        }

        if (!empty($validated['from_location_id']) && !empty($validated['to_location_id'])
            && (int) $validated['from_location_id'] === (int) $validated['to_location_id']) {
            return $this->error('Source and destination must be different.', 422);
        }

        $user = $request->user();

        return DB::transaction(function () use ($validated, $user, $service) {
            $status = $validated['intent'] === 'demand' ? 'Approved' : 'Pending';

            $transfer = Transfer::create([
                'transfer_request_id'        => null,
                'intent'                     => $validated['intent'],
                'from_location_id'           => $validated['from_location_id'],
                'to_location_id'             => $validated['to_location_id'],
                'transfer_number'            => 'TR-PENDING-' . uniqid('', true),
                'transfer_date'              => $validated['transfer_date'],
                'requested_by'               => $user->user_id,
                'approved_by'                => $validated['intent'] === 'demand' ? $user->user_id : null,
                'status_id'                  => $service->statusIdForTest($status),
                'notes'                      => $validated['notes'] ?? null,
                'total_quantity_transferred' => 0,
                'total_quantity_received'    => 0,
            ]);
            $transfer->transfer_number = 'TR-' . date('Ymd') . '-' . str_pad((string) $transfer->transfer_id, 4, '0', STR_PAD_LEFT);
            $transfer->save();

            foreach ($validated['products'] as $row) {
                $transfer->details()->create([
                    'product_id'           => $row['product_id'],
                    'quantity_requested'   => (int) $row['quantity_requested'],
                    'quantity_scanned'     => 0,
                    'quantity_transferred' => 0,
                    'quantity_received'    => 0,
                ]);
            }

            \App\Models\TblTransferTracking::create([
                'transfer_id' => $transfer->transfer_id,
                'location_id' => $validated['to_location_id'] ?? $validated['from_location_id'],
                'status_note' => ucfirst($validated['intent']) . ' transfer request submitted',
                'recorded_by' => $user->user_id,
                'recorded_at' => now(),
            ]);

            \App\Support\RealtimeBroadcaster::transferChanged(
                (int) ($transfer->from_location_id ?? 0),
                (int) $transfer->to_location_id,
                'request_changed'
            );

            return $this->success($transfer->load(self::withRelations()), 'Transfer request submitted', 201);
        });
    }

    public function show($id)
    {
        try {
            $transfer = Transfer::with(array_merge(self::withRelations(), ['transferTracking.recordedBy', 'details.product.consumableDetail']))
                ->findOrFail($id);

            $fromLocId = $transfer->from_location_id;
            $availability = $fromLocId
                ? \App\Models\Inventory::where('location_id', $fromLocId)
                    ->whereIn('product_id', $transfer->details->pluck('product_id'))
                    ->pluck('available_quantity', 'product_id')
                : collect();

            foreach ($transfer->details as $detail) {
                $available = (int) ($availability[$detail->product_id] ?? 0);
                $detail->available_at_source = $available;
                $detail->stock_at_source     = $available;
                $detail->shortfall           = max(0, (int) $detail->quantity_requested - $available);
            }

            return $this->success($transfer, 'Transfer retrieved');
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

            return $this->success($transfer->fresh()->load(['fromLocation', 'toLocation', 'details.product']), 'Transfer updated successfully');
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

            DB::beginTransaction();

            app(\App\Services\TransferService::class)->reverse($transfer);

            $transfer->details()->delete();
            $transfer->delete();

            DB::commit();

            return $this->success(null, 'Transfer deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to delete transfer: ' . $e->getMessage(), 500);
        }
    }

    public function archived(Request $request)
    {
        $query = Transfer::onlyTrashed()->with(['fromLocation', 'toLocation', 'status']);

        if ($request->filled('search')) {
            $query->where('transfer_number', 'like', '%' . $request->search . '%');
        }

        return $this->paginated(
            $query->orderBy('deleted_at', 'desc')->paginate($request->integer('per_page', 15)),
            'Archived transfers retrieved'
        );
    }

    public function restore($id)
    {
        $transfer = Transfer::onlyTrashed()->find($id);
        if (! $transfer) {
            return $this->notFound('Archived transfer not found');
        }
        $transfer->restore();
        return $this->success($transfer->fresh()->load(['fromLocation', 'toLocation', 'status']), 'Transfer restored successfully');
    }

    public function forceDestroy($id)
    {
        $transfer = Transfer::onlyTrashed()->find($id);
        if (! $transfer) {
            return $this->notFound('Archived transfer not found');
        }
        $transfer->forceDelete();
        return $this->success(null, 'Transfer permanently deleted');
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
                'quantity_unit'      => 'sometimes|string|in:package,piece',
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

            $transfer->refresh()->load(['fromLocation', 'toLocation', 'receivedBy', 'status', 'details.product']);

            return $this->success($transfer, 'Receipt recorded successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to record receipt: ' . $e->getMessage(), 500);
        }
    }

    public function verify(Request $request, $id, TransferService $service)
    {
        $validated = $request->validate([
            'from_location_id'           => 'nullable|exists:locations,location_id',
            'to_location_id'             => 'nullable|exists:locations,location_id',
            'lines'                      => 'sometimes|array',
            'lines.*.transfer_detail_id' => 'required|integer',
            'lines.*.quantity_approved'  => 'required|integer|min:0',
        ]);

        $transfer = Transfer::with('details')->findOrFail($id);
        if ((int) $transfer->status_id !== $service->statusIdForTest('Pending')) {
            return $this->error('Only submitted requests can be verified.', 422);
        }

        $newFrom = !empty($validated['from_location_id']) ? (int) $validated['from_location_id'] : $transfer->from_location_id;
        $newTo   = !empty($validated['to_location_id']) ? (int) $validated['to_location_id'] : $transfer->to_location_id;

        if ($newFrom === null) {
            return $this->error('A source location must be assigned.', 422);
        }
        if ($newTo === null) {
            return $this->error('A destination location must be assigned.', 422);
        }
        if ((int) $newFrom === (int) $newTo) {
            return $this->error('Source and destination must differ.', 422);
        }

        $zeroed = collect($validated['lines'] ?? [])
            ->filter(fn ($l) => (int) $l['quantity_approved'] === 0)
            ->pluck('transfer_detail_id')->all();
        $survivors = $transfer->details->reject(fn ($d) => in_array($d->transfer_detail_id, $zeroed))->count();
        if ($survivors < 1) {
            return $this->error('At least one line must remain.', 422);
        }

        return DB::transaction(function () use ($request, $validated, $transfer, $service, $newFrom, $newTo) {
            $user = $request->user();
            $transfer->from_location_id = $newFrom;
            $transfer->to_location_id   = $newTo;

            foreach ($validated['lines'] ?? [] as $line) {
                $detail = TransferDetail::where('transfer_detail_id', $line['transfer_detail_id'])
                    ->where('transfer_id', $transfer->transfer_id)->first();
                if (!$detail) continue;
                if ((int) $line['quantity_approved'] === 0) {
                    $detail->delete();
                } else {
                    $detail->quantity_requested = (int) $line['quantity_approved'];
                    $detail->save();
                }
            }

            $transfer->approved_by = $user->user_id;
            $transfer->status_id   = $service->statusIdForTest('Approved');
            $transfer->save();

            \App\Models\TblTransferTracking::create([
                'transfer_id' => $transfer->transfer_id,
                'location_id' => $transfer->from_location_id,
                'status_note' => "Verified & assigned to source by " . ($user->username ?? "user#{$user->user_id}"),
                'recorded_by' => $user->user_id,
                'recorded_at' => now(),
            ]);

            \App\Support\RealtimeBroadcaster::transferChanged(
                (int) $transfer->from_location_id, (int) $transfer->to_location_id, 'request_changed'
            );

            return $this->success($transfer->fresh()->load(self::withRelations()), 'Transfer verified and assigned');
        });
    }

    public function reject(Request $request, $id, TransferService $service)
    {
        $validated = $request->validate(['rejection_reason' => 'nullable|string|max:500']);
        $transfer = Transfer::findOrFail($id);
        if ((int) $transfer->status_id !== $service->statusIdForTest('Pending')) {
            return $this->error('Only submitted requests can be rejected.', 422);
        }
        $transfer->status_id = $service->statusIdForTest('Rejected');
        if (!empty($validated['rejection_reason'])) {
            $transfer->notes = trim("REJECTED: {$validated['rejection_reason']}\n\n" . ($transfer->notes ?? ''));
        }
        $transfer->save();
        \App\Support\RealtimeBroadcaster::transferChanged(
            (int) ($transfer->from_location_id ?? 0), (int) $transfer->to_location_id, 'request_changed'
        );
        return $this->success($transfer->fresh()->load(self::withRelations()), 'Transfer request rejected');
    }

    public function pickList($id, TransferService $service)
    {
        $transfer = Transfer::with('details.product')->findOrFail($id);
        return $this->success($service->pickList($transfer), 'Pick list generated');
    }

    /** The requester, or an admin/IA, may edit/cancel a request while it is still Pending. */
    private function canEditRequest($user, Transfer $transfer): bool
    {
        return (int) $transfer->requested_by === (int) $user->user_id
            || in_array((int) $user->role_id, [1, 2], true); // admin, inventory_analyst
    }

    /** Edit a still-Pending request's product lines + notes (requester or admin/IA). */
    public function updateRequest(Request $request, $id, TransferService $service)
    {
        $validated = $request->validate([
            'notes'                          => 'nullable|string|max:500',
            'products'                       => 'required|array|min:1',
            'products.*.product_id'          => 'required|exists:products,product_id',
            'products.*.quantity_requested'  => 'required|integer|min:1',
        ]);

        $transfer = Transfer::with('details')->findOrFail($id);
        if ((int) $transfer->status_id !== $service->statusIdForTest('Pending')) {
            return $this->error('Only a pending request can be edited.', 422);
        }
        $user = $request->user();
        if (!$this->canEditRequest($user, $transfer)) {
            return $this->error('You can only edit your own pending request.', 403);
        }

        return DB::transaction(function () use ($transfer, $validated, $user) {
            if (array_key_exists('notes', $validated)) {
                $transfer->notes = $validated['notes'];
            }
            $transfer->save();

            // Pending has moved no stock, so lines can be swapped wholesale.
            $transfer->details()->delete();
            foreach ($validated['products'] as $row) {
                $transfer->details()->create([
                    'product_id'           => $row['product_id'],
                    'quantity_requested'   => (int) $row['quantity_requested'],
                    'quantity_scanned'     => 0,
                    'quantity_transferred' => 0,
                    'quantity_received'    => 0,
                ]);
            }

            \App\Models\TblTransferTracking::create([
                'transfer_id' => $transfer->transfer_id,
                'location_id' => $transfer->to_location_id ?? $transfer->from_location_id,
                'status_note' => 'Request edited by ' . ($user->username ?? "user#{$user->user_id}"),
                'recorded_by' => $user->user_id,
                'recorded_at' => now(),
            ]);

            return $this->success($transfer->fresh()->load(self::withRelations()), 'Request updated');
        });
    }

    /** Withdraw a still-Pending request (requester or admin/IA). Soft-deletes it. */
    public function cancel(Request $request, $id, TransferService $service)
    {
        $transfer = Transfer::findOrFail($id);
        if ((int) $transfer->status_id !== $service->statusIdForTest('Pending')) {
            return $this->error('Only a pending request can be cancelled.', 422);
        }
        $user = $request->user();
        if (!$this->canEditRequest($user, $transfer)) {
            return $this->error('You can only cancel your own pending request.', 403);
        }

        \App\Models\TblTransferTracking::create([
            'transfer_id' => $transfer->transfer_id,
            'location_id' => $transfer->to_location_id ?? $transfer->from_location_id,
            'status_note' => 'Request cancelled by ' . ($user->username ?? "user#{$user->user_id}"),
            'recorded_by' => $user->user_id,
            'recorded_at' => now(),
        ]);
        $transfer->delete();

        return $this->success(null, 'Request cancelled');
    }

    public function sourceAvailability($id)
    {
        $transfer = Transfer::with('details')->findOrFail($id);
        $productIds = $transfer->details->pluck('product_id')->unique()->values();

        if ($productIds->isEmpty()) {
            return $this->success([], 'Source availability');
        }

        $rows = Inventory::query()
            ->join('locations', 'inventory.location_id', '=', 'locations.location_id')
            ->where('locations.location_type', 'warehouse')
            ->whereIn('inventory.product_id', $productIds)
            ->get([
                'inventory.location_id',
                'inventory.product_id',
                'inventory.available_quantity',
                'inventory.quantity_on_hand',
            ]);

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->location_id][(int) $r->product_id] = [
                'available' => (int) $r->available_quantity,
                'on_hand'   => (int) $r->quantity_on_hand,
            ];
        }

        return $this->success($map, 'Source availability');
    }

    private function statusId(string $name): ?int
    {
        $id = DB::table('status_lookup')
            ->where('status_category', 'transfer')
            ->where('status_name', $name)
            ->value('status_id');
        return $id !== null ? (int) $id : null;
    }

    /** Returns a 403 JsonResponse if the caller may not act on this IST's destination, else null. */
    private function ensureReceiveLocation(Request $request, Transfer $ist): ?\Illuminate\Http\JsonResponse
    {
        $user      = $request->user();
        $role      = $user->role?->role_name ?? '';
        $isAdmin   = in_array($role, ['admin', 'inventory_analyst'], true);
        $userLocId = $user->enforced_location_id ?? $user->location_id;

        if (!$isAdmin && $userLocId && (int) $userLocId !== (int) $ist->to_location_id) {
            return $this->error('You are not authorized to receive items at this destination.', 403);
        }
        return null;
    }

    /**
     * Record a barcode scan against an approved IST.
     */
    public function scan(Request $request, $id, TransferService $service)
    {
        try {
            $validated = $request->validate([
                'barcode'            => 'required|string|max:255',
                'transfer_detail_id' => 'sometimes|nullable|integer',
                'quantity'           => 'sometimes|integer|min:1',
                'confirmed_zone'     => 'nullable|string|max:100',
            ]);

            $approvedId = $this->statusId('Approved');
            $ist = Transfer::with(['details.product'])
                ->where('status_id', $approvedId)
                ->findOrFail($id);

            $barcode = trim($validated['barcode']);

            // FIFO gate, shared with issuance picking so the ordering rule cannot
            // drift between the two. Runs BEFORE any TransferService write; it only
            // judges the scan — recording/counting/serials remain the service's job.
            $lines = $ist->details->map(fn ($detail) => [
                'product_id' => (int) $detail->product_id,
                'quantity'   => (int) $detail->quantity_requested,
                'picked'     => (int) $detail->quantity_scanned,
            ])->values()->all();

            $locationId = (int) $ist->from_location_id;

            $verdict = app(\App\Services\FifoPickingService::class)->validateScan(
                $locationId,
                $lines,
                $barcode,
                $validated['confirmed_zone'] ?? null,
                null,
                app(\App\Services\IncidentService::class)->excludedBatchIds($locationId)
            );

            if (! $verdict->accepted()) {
                return response()->json([
                    'success'     => false,
                    'message'     => $verdict->message,
                    'scan_result' => $verdict->toArray(),
                ], 422);
            }

            $result = $service->recordDispatchScan(
                $ist,
                $barcode,
                $validated['transfer_detail_id'] ?? null,
                $request->user()->user_id,
                (int) ($validated['quantity'] ?? 1),
                ($validated['quantity_unit'] ?? 'package') === 'package',
            );

            $ist->load('details');
            $fullyScanned = $ist->details->every(
                fn ($d) => (int) $d->quantity_scanned >= (int) $d->quantity_requested
            );

            return $this->success(array_merge($result, [
                'ist_fully_scanned' => $fullyScanned,
            ]), 'Scan recorded');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('Scan failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove a single scan — decrements quantity_scanned and deletes the barcode_scans row.
     */
    public function removeScan(Request $request, $id, $scanId, TransferService $service)
    {
        try {
            $approvedId = $this->statusId('Approved');
            $ist = Transfer::where('status_id', $approvedId)
                ->findOrFail($id);

            $service->removeDispatchScan($ist, (int) $scanId);
            return $this->success(null, 'Scan removed');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('Failed to remove scan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Confirm dispatch: set IST to In Transit, deduct inventory, record tracking.
     */
    public function confirmDispatch(Request $request, $id, TransferService $service)
    {
        try {
            $ist = Transfer::with(['details.product'])
                ->findOrFail($id);

            $result = $service->dispatch($ist, $request->user()?->user_id);

            try {
                \App\Models\AuditTrail::create([
                    'user_id'    => $request->user()?->user_id,
                    'action'     => 'ist_dispatched',
                    'table_name' => 'transfers',
                    'record_id'  => $result->transfer_id,
                    'old_values' => ['status' => 'Approved'],
                    'new_values' => ['status' => 'In Transit', 'total_quantity_transferred' => (int) $result->total_quantity_transferred],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            } catch (\Throwable $e) { report($e); }

            return $this->success([
                'transfer_number'  => $result->transfer_number,
                'status'           => 'In Transit',
                'total_dispatched' => (int) $result->total_quantity_transferred,
            ], 'IST dispatched successfully');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('Failed to confirm dispatch: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Return current scan progress for an IST (used to restore UI state across refreshes).
     */
    public function scanStatus($id)
    {
        try {
            $ist = Transfer::with(['details.product'])
                ->findOrFail($id);

            $scanHistory = \App\Models\BarcodeScan::where('transfer_id', $id)
                ->with('product')
                ->orderBy('created_at', 'asc')
                ->get();

            return $this->success([
                'details'      => $ist->details,
                'scan_history' => $scanHistory,
            ], 'Scan status retrieved');
        } catch (\Exception $e) {
            return $this->error('IST not found', 404);
        }
    }

    /**
     * Record a receive-side barcode scan against an In Transit IST.
     */
    public function receiveScan(Request $request, $id, TransferService $service)
    {
        try {
            $validated = $request->validate([
                'barcode'            => 'required|string|max:255',
                'transfer_detail_id' => 'sometimes|nullable|integer',
                'quantity'           => 'sometimes|integer|min:1',
                'quantity_unit'      => 'sometimes|string|in:package,piece',
            ]);

            $ist = Transfer::findOrFail($id);

            if ($resp = $this->ensureReceiveLocation($request, $ist)) {
                return $resp;
            }

            $user   = $request->user();
            $result = $service->recordReceiveScan(
                $ist,
                $validated['barcode'],
                $validated['transfer_detail_id'] ?? null,
                $user->user_id,
                (int) ($validated['quantity'] ?? 1),
                ($validated['quantity_unit'] ?? 'package') === 'package'
            );

            return $this->success($result, 'Receive scan recorded');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('Receive scan failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove a receive-side scan (undo before confirmation).
     */
    public function removeReceiveScan(Request $request, $id, $scanId, TransferService $service)
    {
        try {
            $ist = Transfer::findOrFail($id);
            if ($resp = $this->ensureReceiveLocation($request, $ist)) {
                return $resp;
            }
            $service->removeReceiveScan($ist, (int) $scanId);
            return $this->success(null, 'Receive scan removed');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('Failed to remove receive scan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Confirm receipt: update inventory at destination, set status to Received.
     */
    public function receiveStore(Request $request, $id, TransferService $service)
    {
        try {
            $ist = Transfer::with(['details.product'])
                ->findOrFail($id);

            if ($resp = $this->ensureReceiveLocation($request, $ist)) {
                return $resp;
            }

            $user   = $request->user();
            $result = $service->receive($ist, $user->user_id);

            try {
                \App\Models\AuditTrail::create([
                    'user_id'    => $request->user()?->user_id,
                    'action'     => 'ist_received',
                    'table_name' => 'transfers',
                    'record_id'  => $result->transfer_id,
                    'old_values' => ['status' => 'In Transit'],
                    'new_values' => ['status' => 'Received', 'total_quantity_received' => (int) $result->total_quantity_received],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            } catch (\Throwable $e) { report($e); }

            return $this->success([
                'transfer_number'         => $result->transfer_number,
                'status'                  => 'Received',
                'total_quantity_received' => (int) $result->total_quantity_received,
            ], 'Transfer received successfully');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('Failed to confirm receipt: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Return IST data for the receive-confirmation form (only In Transit ISTs).
     */
    public function receiveForm($id)
    {
        try {
            $inTransitId = $this->statusId('In Transit');
            $ist = Transfer::with(array_merge(self::withRelations(), ['transferTracking.recordedBy']))
                ->where('status_id', $inTransitId)
                ->findOrFail($id);

            $serials = \App\Models\ItemSerial::where('transfer_id', $id)
                ->select('product_id', 'serial_number')
                ->get()
                ->groupBy('product_id');

            foreach ($ist->details as $detail) {
                $detail->item_serials = $serials->get($detail->product_id, collect())
                    ->pluck('serial_number')
                    ->values();
            }

            return $this->success($ist, 'IST receive form data retrieved');
        } catch (\Exception $e) {
            return $this->error('IST not found or not In Transit', 404);
        }
    }

    /**
     * Return receipt data (JSON) for a received IST.
     */
    public function receipt($id)
    {
        try {
            $receivedId = $this->statusId('Received');

            $ist = Transfer::with(array_merge(self::withRelations(), [
                'transferTracking.recordedBy',
                'receivedBy',
            ]))->where('status_id', $receivedId)
               ->findOrFail($id);

            $serials = \App\Models\ItemSerial::where('transfer_id', $id)
                ->select('product_id', 'serial_number')
                ->get()
                ->groupBy('product_id');

            foreach ($ist->details as $detail) {
                $detail->item_serials = $serials->get($detail->product_id, collect())
                    ->pluck('serial_number')
                    ->values();
            }

            return $this->success($ist, 'IST receipt data retrieved');
        } catch (\Exception $e) {
            return $this->error('IST not found or not yet received', 404);
        }
    }

    /**
     * Stream a PDF receipt for a received IST.
     */
    public function receiptPdf(\Illuminate\Http\Request $request, $id)
    {
        try {
            $receivedId = $this->statusId('Received');

            $ist = Transfer::with(array_merge(self::withRelations(), [
                'receivedBy',
                'transferTracking.recordedBy',
            ]))->where('status_id', $receivedId)
               ->findOrFail($id);

            $serials = \App\Models\ItemSerial::where('transfer_id', $id)
                ->select('product_id', 'serial_number')
                ->get()
                ->groupBy('product_id');

            foreach ($ist->details as $detail) {
                $detail->item_serials = $serials->get($detail->product_id, collect())
                    ->pluck('serial_number')
                    ->values();
            }

            $paperSize = strtolower((string) $request->query('paper_size', \App\Models\SystemSetting::pdfPaperSize()));
            if (! in_array($paperSize, ['a4', 'letter', 'legal', 'a5'], true)) {
                $paperSize = \App\Models\SystemSetting::pdfPaperSize();
            }
            $paperWidths = ['a4' => 595.28, 'letter' => 612, 'legal' => 612, 'a5' => 419.53];
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('ist.receipt', [
                'ist' => $ist,
                'printable_width_pt' => $paperWidths[$paperSize] - 72,
                'company' => [
                    'name' => \App\Models\SystemSetting::companyName(),
                    'address' => \App\Models\SystemSetting::companyAddress(),
                    'email' => \App\Models\SystemSetting::companyEmail(),
                    'phone' => \App\Models\SystemSetting::companyPhone(),
                ],
            ])->setPaper($paperSize, 'portrait');

            $filename = 'IST-' . $ist->transfer_number . '.pdf';
            return $request->boolean('preview') ? $pdf->stream($filename) : $pdf->download($filename);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to generate PDF: ' . $e->getMessage()], 500);
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
