<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Inventory;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use App\Models\Transfer;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Single endpoint for Inventory Operation (BarcodeScan) engine: receiving, transfer, audit, issuance.
 */
class InventoryScanTransactionController extends Controller
{
    use ApiResponse;

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'barcode'            => 'required|string|max:191',
                'transaction_type'   => 'required|string|in:receiving,transfer,audit,issuance',
                'location_id'        => 'nullable|exists:locations,location_id',
                'from_location_id'   => 'nullable|exists:locations,location_id',
                'to_location_id'     => 'nullable|exists:locations,location_id',
                'quantity'           => 'nullable|integer|min:1',
                'po_id'              => 'nullable|exists:purchase_orders,po_id',
                'po_detail_id'       => 'nullable|exists:purchase_order_details,po_detail_id',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $barcode = trim($validated['barcode']);
        $type = $validated['transaction_type'];
        $quantity = max(1, (int) ($validated['quantity'] ?? 1));

        // For PO receiving with a known line item, the scanned value is a serial number —
        // resolve the product from the PO detail instead of treating the scan as a product lookup.
        $product = null;
        if ($type === 'receiving' && ! empty($validated['po_detail_id'])) {
            $poDetailForLookup = PurchaseOrderDetail::with('product')->find((int) $validated['po_detail_id']);
            if ($poDetailForLookup?->product) {
                $product = $poDetailForLookup->product;
            }
        }
        if (! $product) {
            $product = $this->resolveProductByBarcode($barcode);
        }
        if (! $product) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Product not found for this barcode.',
            ], 'Rejected');
        }

        try {
            return match ($type) {
                'receiving' => $this->processReceiving($request, $product, $validated, $quantity),
                'transfer'  => $this->processTransfer($request, $product, $validated, $quantity),
                'audit'     => $this->processAudit($request, $product, (int) ($validated['location_id'] ?? 0), $barcode),
                'issuance'  => $this->processIssuance($request, $product, $validated, $quantity),
                default     => $this->error('Unsupported transaction type', 400),
            };
        } catch (\Throwable $e) {
            return $this->error('Scan transaction failed: '.$e->getMessage(), 500);
        }
    }

    private function resolveProductByBarcode(string $raw): ?Product
    {
        $digitsOnly = preg_replace('/\D/', '', $raw);
        $variants = array_values(array_unique(array_filter([
            $raw,
            strtoupper($raw),
            strtolower($raw),
            preg_replace('/\s+/', '', $raw),
            $digitsOnly !== '' ? $digitsOnly : null,
        ])));

        foreach ($variants as $v) {
            $norm = strtolower(trim($v));
            if ($norm === '') {
                continue;
            }
            $p = Product::query()
                ->where(function ($q) use ($norm) {
                    $q->whereRaw('LOWER(TRIM(COALESCE(barcode, \'\'))) = ?', [$norm])
                        ->orWhereRaw('LOWER(TRIM(COALESCE(product_code, \'\'))) = ?', [$norm]);
                })
                ->first();
            if ($p) {
                return $p;
            }
        }

        foreach ($variants as $v) {
            $serial = ItemSerial::query()
                ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower(trim($v))])
                ->first();
            if ($serial && $serial->product_id) {
                return Product::query()->where('product_id', $serial->product_id)->first();
            }
        }

        if (strlen($digitsOnly) === 12 && ctype_digit($digitsOnly)) {
            $ean = $this->ean13From12($digitsOnly);
            if ($ean) {
                return Product::query()
                    ->where(function ($q) use ($ean) {
                        $q->where('barcode', $ean)->orWhere('product_code', $ean);
                    })
                    ->first();
            }
        }

        return null;
    }

    private function ean13From12(string $twelve): ?string
    {
        if (! preg_match('/^\d{12}$/', $twelve)) {
            return null;
        }
        $sum = 0;
        foreach (str_split($twelve) as $i => $ch) {
            $d = (int) $ch;
            $sum += ($i % 2 === 0) ? $d : $d * 3;
        }
        $check = (10 - ($sum % 10)) % 10;

        return $twelve.$check;
    }

    private function processReceiving(Request $request, Product $product, array $validated, int $quantity): \Illuminate\Http\JsonResponse
    {
        $locationId = (int) ($validated['location_id'] ?? 0);
        if ($locationId <= 0) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Select a receiving location.',
            ], 'Rejected');
        }

        // One scan always equals exactly one physical unit:
        //   Serialized (appliance) → 1 scan = 1 unit (unique serial number).
        //   Consumable             → 1 scan = 1 package = pieces_per_package individual pieces.
        // The request quantity field is intentionally ignored here so the frontend
        // can never accidentally submit more than one package per scan event.
        $isSerialized     = $product->isAppliance();
        $piecesPerPackage = max(1, (int) ($product->pieces_per_package ?? 1));
        $packagesReceived = 1;
        $quantity         = $isSerialized ? 1 : $piecesPerPackage;

        $poId             = ! empty($validated['po_id']) ? (int) $validated['po_id'] : 0;
        $poDetailIdDirect = ! empty($validated['po_detail_id']) ? (int) $validated['po_detail_id'] : null;
        $poDetail         = null;
        $remaining        = null;

        // BUG 2: Validate PO status before allowing receiving
        if ($poId) {
            $po         = PurchaseOrder::with('status')->find($poId);
            $statusName = strtolower($po->status?->status_name ?? '');
            $goodStatuses = ['approved', 'authorized', 'active', 'confirmed', 'complete', 'received'];
            $badStatuses  = ['pending', 'draft', 'awaiting', 'submitted', 'rejected', 'cancelled', 'void'];

            $isGood = false;
            foreach ($goodStatuses as $s) {
                if (str_contains($statusName, $s)) {
                    $isGood = true;
                    break;
                }
            }

            if (! $isGood) {
                $isBad = false;
                foreach ($badStatuses as $s) {
                    if (str_contains($statusName, $s)) {
                        $isBad = true;
                        break;
                    }
                }
                if ($isBad) {
                    return $this->success([
                        'action' => 'rejected',
                        'reason' => 'This PO is not approved. Status: ' . ($po->status?->status_name ?? 'Unknown'),
                    ], 'Rejected');
                }
            }
        }

        // PO line-item resolution and over-receiving protection
        if ($poId) {
            if ($poDetailIdDirect) {
                // Serial-number assignment mode: the frontend supplied the exact line-item ID.
                $poDetail = PurchaseOrderDetail::find($poDetailIdDirect);
                if (! $poDetail || (int) $poDetail->po_id !== $poId) {
                    return $this->success([
                        'action' => 'rejected',
                        'reason' => 'Line item does not belong to the selected purchase order.',
                    ], 'Rejected');
                }
                // Count received units for this specific line item only.
                $alreadyReceived = (int) ReceivingDetail::query()
                    ->where('po_detail_id', $poDetailIdDirect)
                    ->sum('quantity_amount');
            } else {
                // Legacy barcode-lookup path.
                $onPo = PurchaseOrderDetail::query()
                    ->where('po_id', $poId)
                    ->where('product_id', $product->product_id)
                    ->exists();
                if (! $onPo) {
                    return $this->success([
                        'action' => 'rejected',
                        'reason' => 'This product is not on the selected purchase order.',
                    ], 'Rejected');
                }
                $poDetail = PurchaseOrderDetail::query()
                    ->where('po_id', $poId)
                    ->where('product_id', $product->product_id)
                    ->first();
                $alreadyReceived = (int) ReceivingDetail::query()
                    ->where('product_id', $product->product_id)
                    ->whereIn('receiving_id', function ($sub) use ($poId) {
                        $sub->select('receiving_id')->from('receivings')->where('po_id', $poId);
                    })
                    ->sum('quantity_amount');
            }

            // quantity_ordered is in packages for consumables, individual units for serialized.
            // alreadyReceived and $quantity are both in pieces, so convert ordered to pieces.
            $orderedPieces = $isSerialized
                ? (int) $poDetail->quantity_ordered
                : (int) $poDetail->quantity_ordered * $piecesPerPackage;
            $remaining = $orderedPieces - $alreadyReceived;

            if ($remaining <= 0) {
                $label = $isSerialized
                    ? "{$poDetail->quantity_ordered} unit(s)"
                    : "{$poDetail->quantity_ordered} package(s) ({$orderedPieces} pcs)";
                return $this->success([
                    'action' => 'rejected',
                    'reason' => "All {$label} already received for this line item.",
                ], 'Rejected');
            }

            $quantity = min($quantity, $remaining);
        }

        $scannedBarcode = strtolower(trim($validated['barcode']));

        // Global barcode uniqueness: every scanned barcode is a one-time-use identifier.
        // Once received anywhere (any PO, any line, any session), the barcode is locked
        // and cannot be assigned to a different inventory slot.
        if ($scannedBarcode !== '') {
            if (ReceivingDetail::query()
                ->whereNotNull('scanned_barcode')
                ->whereRaw('LOWER(TRIM(scanned_barcode)) = ?', [$scannedBarcode])
                ->exists()
            ) {
                return $this->success([
                    'action' => 'rejected',
                    'reason' => 'This barcode has already been received and is locked to its assigned inventory slot. Each barcode is a one-time-use unique identifier.',
                ], 'Rejected');
            }
        }

        DB::beginTransaction();
        try {
            $inv = Inventory::firstOrCreate(
                [
                    'location_id' => $locationId,
                    'product_id'  => $product->product_id,
                ],
                [
                    'quantity_on_hand'   => 0,
                    'packages_on_hand'   => 0,
                    'available_quantity' => 0,
                    'reorder_level'      => 0,
                ]
            );

            if ($isSerialized) {
                // Serialized: 1 scan = 1 unit; package concept does not apply.
                $inv->quantity_on_hand   = (int) $inv->quantity_on_hand + 1;
                $inv->available_quantity = (int) $inv->available_quantity + 1;
            } else {
                // Consumable: 1 scan = 1 physical package.
                // packages_on_hand is the authoritative count; quantity_on_hand is derived.
                $inv->packages_on_hand   = (int) $inv->packages_on_hand + $packagesReceived;
                $inv->quantity_on_hand   = $inv->packages_on_hand * $piecesPerPackage;
                $inv->available_quantity = $inv->quantity_on_hand;
            }
            $inv->save();

            // BUG 4: Find or create the receivings header row
            if ($poId) {
                $receiving = Receiving::where('po_id', $poId)
                    ->where('location_id', $locationId)
                    ->first();
            } else {
                $receiving = Receiving::whereNull('po_id')
                    ->where('location_id', $locationId)
                    ->whereDate('created_at', now()->toDateString())
                    ->first();
            }

            if (! $receiving) {
                $receiving = new Receiving();
                $receiving->receiving_number        = 'RCV-SCAN-' . str_replace('.', '', uniqid('', true));
                $receiving->receiving_date          = now()->toDateString();
                $receiving->received_by             = $request->user()?->user_id;
                $receiving->total_quantity_received = 0;
                $receiving->total_quantity_damaged  = 0;
                $receiving->location_id             = $locationId;
                if ($poId) {
                    $receiving->po_id = $poId;
                }
                $receiving->save();
            }

            $receiving->increment('total_quantity_received', $quantity);

            // BUG 3 + BUG 4: Create receiving_details row, storing po_detail_id
            $receiving->details()->create([
                'product_id'      => $product->product_id,
                'po_detail_id'    => $poDetail?->po_detail_id,
                'quantity_amount' => $quantity,
                'prod_price'      => $product->cost_price ?? 0,
                'condition'       => 'Good',
                'scanned_barcode' => $scannedBarcode ?: null,
            ]);

            // Sync PO status (Partially Received / Completed) and received_by
            // inside the transaction so both writes are atomic.
            if ($poId) {
                $this->syncPoReceivingStatus($poId, $request->user()?->user_id);
            }

            DB::commit();
            Product::syncQuantityFromInventory((int) $product->product_id);

            $fresh = $product->fresh()->load(['category', 'brand', 'model', 'unit', 'status']);

            $payload = [
                'action'             => 'applied',
                'product'            => $fresh,
                'inventory'          => $this->inventoryRowsForProduct((int) $product->product_id),
                'warnings'           => [],
                'receiving_number'   => $receiving->receiving_number,
                'quantity_received'  => $quantity,          // pieces added this scan
                'packages_received'  => $isSerialized ? null : $packagesReceived,
                'packages_on_hand'   => $isSerialized ? null : $inv->packages_on_hand,
                'pieces_per_package' => $piecesPerPackage,
                'is_serialized'      => $isSerialized,
            ];

            if ($poId && $remaining !== null) {
                $payload['remaining_after'] = $remaining - $quantity;
            }

            if ($poId) {
                $freshPo = PurchaseOrder::with('status')->find($poId);
                if ($freshPo) {
                    $payload['po_status']    = $freshPo->status?->status_name;
                    $payload['po_status_id'] = $freshPo->status_id;
                }
            }

            return $this->success($payload, 'Receiving posted');
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function processTransfer(Request $request, Product $product, array $validated, int $quantity): \Illuminate\Http\JsonResponse
    {
        $fromLocationId = (int) ($validated['from_location_id'] ?? 0);
        $toLocationId = (int) ($validated['to_location_id'] ?? 0);
        if ($fromLocationId <= 0 || $toLocationId <= 0) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Select source and destination locations.',
            ], 'Rejected');
        }
        if ($fromLocationId === $toLocationId) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'From and To locations must be different.',
            ], 'Rejected');
        }

        DB::beginTransaction();
        try {
            $fromInventory = Inventory::where('location_id', $fromLocationId)
                ->where('product_id', $product->product_id)
                ->lockForUpdate()
                ->first();

            if (! $fromInventory) {
                DB::rollBack();

                return $this->success([
                    'action' => 'rejected',
                    'reason' => 'No inventory at the source location for this product.',
                ], 'Rejected');
            }

            $available = (int) ($fromInventory->available_quantity ?? $fromInventory->quantity_on_hand ?? 0);
            if ($available < $quantity) {
                DB::rollBack();

                return $this->success([
                    'action' => 'rejected',
                    'reason' => "Insufficient stock at source. Available: {$available}, requested: {$quantity}.",
                ], 'Rejected');
            }

            $fromInventory->quantity_on_hand = max(0, (int) $fromInventory->quantity_on_hand - $quantity);
            $fromInventory->available_quantity = max(0, (int) $fromInventory->available_quantity - $quantity);
            $fromInventory->save();

            $toInventory = Inventory::firstOrCreate(
                [
                    'location_id' => $toLocationId,
                    'product_id'  => $product->product_id,
                ],
                [
                    'quantity_on_hand'   => 0,
                    'available_quantity' => 0,
                    'reorder_level'      => 0,
                ]
            );
            $toInventory->quantity_on_hand = (int) $toInventory->quantity_on_hand + $quantity;
            $toInventory->available_quantity = (int) $toInventory->available_quantity + $quantity;
            $toInventory->save();

            $transferNumber = 'TR-SCAN-'.str_replace('.', '', uniqid('', true));
            $uid = $request->user()?->user_id;

            $transferPayload = [
                'from_location_id' => $fromLocationId,
                'to_location_id'   => $toLocationId,
                'transfer_number'  => $transferNumber,
                'transfer_date'    => now(),
                'requested_by'     => $uid,
                'received_by'      => $uid,
                'status_id'        => $this->transferCompletedStatusId(),
            ];
            if (Schema::hasColumn('transfers', 'date')) {
                $transferPayload['date'] = now();
            }

            $transfer = Transfer::create($transferPayload);

            $detailPayload = [
                'product_id'           => $product->product_id,
                'quantity_transferred' => $quantity,
            ];
            if (Schema::hasColumn('transfer_details', 'quantity_received')) {
                $detailPayload['quantity_received'] = $quantity;
            }
            $transfer->details()->create($detailPayload);

            if (Schema::hasColumn('transfers', 'total_quantity_transferred')) {
                $transfer->update(['total_quantity_transferred' => $quantity]);
            }
            if (Schema::hasColumn('transfers', 'total_quantity_received')) {
                $transfer->update(['total_quantity_received' => $quantity]);
            }

            DB::commit();
            Product::syncQuantityFromInventory((int) $product->product_id);

            $fresh = $product->fresh()->load(['category', 'brand', 'model', 'unit', 'status']);

            return $this->success([
                'action'    => 'applied',
                'product'   => $fresh,
                'inventory' => $this->inventoryRowsForProduct((int) $product->product_id),
                'warnings'  => [],
                'reference' => $transferNumber,
            ], 'Transfer posted');
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function processAudit(Request $request, Product $product, int $locationId, string $barcode): \Illuminate\Http\JsonResponse
    {
        if ($locationId <= 0) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Select a location before audit scan.',
            ], 'Rejected');
        }

        $inv = Inventory::where('location_id', $locationId)
            ->where('product_id', $product->product_id)
            ->with(['location'])
            ->first();

        $stock = $inv ? (int) ($inv->available_quantity ?? $inv->quantity_on_hand ?? 0) : 0;
        $locationName = $inv?->location?->location_name
            ?? \App\Models\Location::query()->where('location_id', $locationId)->value('location_name')
            ?? '—';

        $uid = $request->user()?->user_id;
        if ($uid) {
            ActivityLog::create([
                'user_id'       => $uid,
                'activity_type' => 'audit_scan',
                'module'        => 'inventory_operation',
                'description'   => sprintf('Audit scan barcode=%s product_id=%s location_id=%s stock=%s', $barcode, $product->product_id, $locationId ?: '—', $stock),
            ]);
        }

        $fresh = $product->fresh()->load(['category', 'brand', 'model', 'unit', 'status']);

        return $this->success([
            'action' => 'applied',
            'product' => $fresh,
            'inventory' => $this->inventoryRowsForProduct((int) $product->product_id),
            'warnings' => [],
            'audit' => [
                'location_id'   => $locationId ?: null,
                'location_name' => $locationName,
                'stock_on_hand' => $stock,
            ],
        ], 'Audit logged');
    }

    private function processIssuance(Request $request, Product $product, array $validated, int $quantity): \Illuminate\Http\JsonResponse
    {
        $locationId = (int) ($validated['location_id'] ?? 0);
        if ($locationId <= 0) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Select a location for issuance.',
            ], 'Rejected');
        }

        DB::beginTransaction();
        try {
            $fromInventory = Inventory::where('location_id', $locationId)
                ->where('product_id', $product->product_id)
                ->lockForUpdate()
                ->first();

            if (! $fromInventory) {
                DB::rollBack();

                return $this->success([
                    'action' => 'rejected',
                    'reason' => 'No inventory record at this location for this product.',
                ], 'Rejected');
            }

            $available = (int) ($fromInventory->available_quantity ?? $fromInventory->quantity_on_hand ?? 0);
            if ($available < $quantity) {
                DB::rollBack();

                return $this->success([
                    'action' => 'rejected',
                    'reason' => "Insufficient stock. Available: {$available}, requested: {$quantity}.",
                ], 'Rejected');
            }

            $fromInventory->quantity_on_hand = max(0, (int) $fromInventory->quantity_on_hand - $quantity);
            $fromInventory->available_quantity = max(0, (int) $fromInventory->available_quantity - $quantity);
            $fromInventory->save();

            DB::commit();
            Product::syncQuantityFromInventory((int) $product->product_id);

            $fresh = $product->fresh()->load(['category', 'brand', 'model', 'unit', 'status']);

            return $this->success([
                'action'    => 'applied',
                'product'   => $fresh,
                'inventory' => $this->inventoryRowsForProduct((int) $product->product_id),
                'warnings'  => [],
            ], 'Issuance posted');
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /** @return array<int, mixed> */
    private function inventoryRowsForProduct(int $productId): array
    {
        return Inventory::where('product_id', $productId)
            ->with(['location'])
            ->get()
            ->all();
    }

    private function transferCompletedStatusId(): ?int
    {
        $id = DB::table('status_lookup')
            ->where('status_category', 'transfer')
            ->where(function ($q) {
                $q->whereRaw('LOWER(status_name) LIKE ?', ['%complete%'])
                    ->orWhereRaw('LOWER(status_name) LIKE ?', ['%received%']);
            })
            ->value('status_id');

        if ($id !== null) {
            return (int) $id;
        }

        return DB::table('status_lookup')
            ->where('status_category', 'transfer')
            ->value('status_id');
    }

    private function syncPoReceivingStatus(int $poId, $userId = null): void
    {
        $details = PurchaseOrderDetail::with('product')
            ->where('po_id', $poId)
            ->get(['po_detail_id', 'product_id', 'quantity_ordered']);

        if ($details->isEmpty()) {
            return;
        }

        $totalOrdered  = 0;
        $totalReceived = 0;

        foreach ($details as $d) {
            // quantity_ordered is in packages for consumables, units for serialized.
            // quantity_amount is always stored in pieces — normalise both to pieces.
            $isSerialized  = $d->product?->isAppliance() ?? false;
            $pcsPerPackage = max(1, (int) ($d->product?->pieces_per_package ?? 1));
            $orderedPieces = $isSerialized
                ? (int) $d->quantity_ordered
                : (int) $d->quantity_ordered * $pcsPerPackage;

            $totalOrdered  += $orderedPieces;
            $totalReceived += (int) ReceivingDetail::where('po_detail_id', $d->po_detail_id)
                ->sum('quantity_amount');
        }

        $updateData = [];

        if ($totalReceived > 0) {
            $targetName = $totalReceived >= $totalOrdered ? 'Completed' : 'Partially Received';

            $statusId = DB::table('status_lookup')
                ->where('status_category', 'purchase_order')
                ->whereRaw('LOWER(TRIM(status_name)) = ?', [strtolower($targetName)])
                ->value('status_id');

            if ($statusId) {
                $updateData['status_id'] = (int) $statusId;
            }
        }

        if ($userId !== null) {
            $updateData['received_by'] = $userId;
        }

        if (! empty($updateData)) {
            PurchaseOrder::where('po_id', $poId)->update($updateData);
        }
    }
}
