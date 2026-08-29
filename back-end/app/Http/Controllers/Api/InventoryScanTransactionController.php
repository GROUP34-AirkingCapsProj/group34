<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\InventoryScanEvent;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\PurchaseRequest;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use App\Models\Transfer;
use App\Services\InventoryBatchService;
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
    use ApiResponse, \App\Traits\NormalizesBarcode;
    use \App\Traits\ResolvesUserLocation;

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
                'zone_label'         => 'nullable|string|max:100',
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
                'audit'     => $this->processAudit($request, $product, $barcode),
                'issuance'  => $this->processIssuance($request, $product, $validated, $quantity),
                default     => $this->error('Unsupported transaction type', 400),
            };
        } catch (\Throwable $e) {
            return $this->error('Scan transaction failed: '.$e->getMessage(), 500);
        }
    }

    private function resolveProductByBarcode(string $raw): ?Product
    {
        $raw     = trim($raw);
        $lower   = strtolower($raw);
        $upper   = strtoupper($raw);
        $noSpace = preg_replace('/\s+/', '', $raw) ?? '';
        $digits  = preg_replace('/\D/', '', $raw)   ?? '';

        $candidates = array_values(array_unique(array_filter(
            [$raw, $lower, $upper, $noSpace, $digits],
            fn($v) => $v !== ''
        )));

        // 1. Search products.barcode and products.product_code
        foreach ($candidates as $c) {
            $norm = strtolower(trim($c));
            $p = Product::query()
                ->where(function ($q) use ($norm) {
                    $q->whereRaw("LOWER(TRIM(COALESCE(barcode,''))) = ?",       [$norm])
                      ->orWhereRaw("LOWER(TRIM(COALESCE(product_code,''))) = ?", [$norm]);
                })
                ->with(['applianceDetail.brand',
                        'consumableDetail.unit'])
                ->first();
            if ($p) {
                return $p;
            }
        }

        // 2. Search item_serials.serial_number (for appliance units already received)
        foreach ($candidates as $c) {
            $serial = ItemSerial::query()
                ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower(trim($c))])
                ->first();
            if ($serial && $serial->product_id) {
                $p = Product::query()
                    ->where('product_id', $serial->product_id)
                    ->with(['applianceDetail.brand',
                            'consumableDetail.unit'])
                    ->first();
                if ($p) {
                    return $p;
                }
            }
        }

        // 3. EAN-13 check digit fallback
        if (strlen($digits) === 12 && ctype_digit($digits)) {
            $ean = $this->ean13From12($digits);
            if ($ean) {
                $p = Product::query()
                    ->where(function ($q) use ($ean) {
                        $q->where('barcode', $ean)->orWhere('product_code', $ean);
                    })
                    ->with(['applianceDetail.brand',
                            'consumableDetail.unit'])
                    ->first();
                if ($p) {
                    return $p;
                }
            }
        }

        // 4. Receiving history fallback — consumables whose products.barcode is null
        //    but whose barcode was scanned during PO receiving
        foreach ($candidates as $c) {
            $norm = strtolower(trim($c));
            if ($norm === '') continue;
            $detail = DB::table('receiving_details')
                ->whereRaw("LOWER(TRIM(COALESCE(scanned_barcode,''))) = ?", [$norm])
                ->whereNotNull('product_id')
                ->select('product_id')
                ->first();
            if ($detail) {
                $p = Product::query()
                    ->where('product_id', $detail->product_id)
                    ->with(['applianceDetail.brand',
                            'consumableDetail.unit'])
                    ->first();
                if ($p) {
                    return $p;
                }
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
        $locationId = $this->resolveLocationId($request);
        if ($locationId === null) {
            return $this->locationErrorResponse();
        }

        // One scan always equals exactly one physical unit:
        //   Serialized (appliance) → 1 scan = 1 unit (unique serial number).
        //   Consumable             → 1 scan = 1 package = pieces_per_package individual pieces.
        // The request quantity field is intentionally ignored here so the frontend
        // can never accidentally submit more than one package per scan event.

        // Ensure V5 extension tables are loaded before calling isSerialized / getPiecesPerPackage.
        if (! $product->relationLoaded('applianceDetail')) {
            $product->load('applianceDetail');
        }
        if (! $product->relationLoaded('consumableDetail')) {
            $product->load('consumableDetail');
        }

        $isSerialized     = $product->isSerialized();
        $piecesPerPackage = $product->getPiecesPerPackage();
        $packagesReceived = 1;
        $quantity         = $isSerialized ? 1 : $piecesPerPackage;

        $poId             = ! empty($validated['po_id']) ? (int) $validated['po_id'] : 0;
        $poDetailIdDirect = ! empty($validated['po_detail_id']) ? (int) $validated['po_detail_id'] : null;
        $poDetail         = null;
        $remaining        = null;

        // Validate PO status before allowing receiving.
        // V5 status IDs: 8 = Approved, 10 = Authorized, 12 = Partially Received.
        // 12 must be allowed because syncPoReceivingStatus() sets it after the very
        // first scan — blocking status 12 would prevent all scans after the first one.
        if ($poId) {
            $po = PurchaseOrder::with(['status', 'purchaseRequest.budget'])->find($poId);
            if (! $po) {
                return $this->success([
                    'action' => 'rejected',
                    'reason' => 'Purchase order not found.',
                ], 'Rejected');
            }

            // Mirrors the ReceivingController::store() gate: a PO must be verified by an
            // auditor before any stock can be received against it via barcode scan.
            if ($po->verification_status !== 'verified') {
                return $this->success([
                    'action' => 'rejected',
                    'reason' => 'This purchase order must be verified by an auditor before receiving.',
                ], 'Rejected');
            }

            $receivableStatusIds = [8, 10, 12]; // Approved, Authorized, Partially Received
            if (! in_array((int) $po->status_id, $receivableStatusIds, true)) {
                $statusName = $po->status?->status_name ?? 'Unknown';
                return $this->success([
                    'action' => 'rejected',
                    'reason' => "This PO cannot be received. Current status: {$statusName}. "
                              . 'PO must be Approved, Authorized, or Partially Received.',
                ], 'Rejected');
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

        $scannedBarcode = $this->canonicalSerial((string) $validated['barcode']);
        $scannedBarcodeLookup = strtolower($scannedBarcode);

        // Global barcode uniqueness: every scanned barcode is a one-time-use identifier.
        // Once received anywhere (any PO, any line, any session), the barcode is locked
        // and cannot be assigned to a different inventory slot.
        if ($scannedBarcode !== '') {
            if (ReceivingDetail::query()
                ->whereNotNull('scanned_barcode')
                ->whereRaw("LOWER(REPLACE(REPLACE(REPLACE(TRIM(scanned_barcode), ' ', ''), CHAR(9), ''), CHAR(13), '')) = ?", [$scannedBarcodeLookup])
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
                $inv->setPieces((int) $inv->quantity_on_hand + 1, 0);
            } else {
                // Consumable: 1 scan = 1 physical package. Pieces are authoritative;
                // packages_on_hand is derived by setPieces().
                $inv->setPieces(
                    (int) $inv->quantity_on_hand + ($packagesReceived * $piecesPerPackage),
                    $piecesPerPackage
                );
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
                $receiving->receiving_number        = 'PENDING-' . str_replace('.', '', uniqid('', true));
                $receiving->receiving_date          = now()->toDateString();
                $receiving->received_by             = $request->user()?->user_id;
                $receiving->total_quantity_received = 0;
                $receiving->total_quantity_damaged  = 0;
                $receiving->location_id             = $locationId;
                if ($poId) {
                    $receiving->po_id = $poId;
                }
                $receiving->save();
                $receiving->assignReadableNumber();
            }

            $receiving->increment('total_quantity_received', $quantity);

            // Create receiving_details row, storing po_detail_id
            $receiving->details()->create([
                'product_id'      => $product->product_id,
                'po_detail_id'    => $poDetail?->po_detail_id,
                'quantity_amount' => $quantity,
                'prod_price'      => $product->cost_price ?? 0,
                'condition'       => 'Good',
                'scanned_barcode' => $scannedBarcode ?: null,
            ]);

            // Batch/zone tracking: accumulate repeated scans against the same receiving
            // header into one batch row (rather than one tiny batch per scan), so the
            // Warehouse Map/drill-down show one coherent lot per receiving event.
            $zoneLabel = $validated['zone_label'] ?? null;
            $existingBatch = InventoryBatch::where('receiving_id', $receiving->receiving_id)
                ->where('product_id', $product->product_id)
                ->where('location_id', $locationId)
                ->first();
            if ($existingBatch) {
                $existingBatch->quantity_received  = (int) $existingBatch->quantity_received + $quantity;
                $existingBatch->quantity_remaining = (int) $existingBatch->quantity_remaining + $quantity;
                if ($zoneLabel !== null) {
                    $existingBatch->zone_label = $zoneLabel;
                }
                $existingBatch->save();
            } else {
                app(InventoryBatchService::class)->createBatch(
                    $product->product_id,
                    $locationId,
                    $receiving->receiving_id,
                    $quantity,
                    $receiving->receiving_date ?? now(),
                    $zoneLabel
                );
            }

            // Register serial number for all product types that have a scanned barcode
            if ($scannedBarcode !== '') {
                if ($isSerialized) {
                    ItemSerial::create([
                        'product_id'    => $product->product_id,
                        'location_id'   => $locationId,
                        'receiving_id'  => $receiving->receiving_id,
                        'scanned_by'    => $request->user()?->user_id,
                        'serial_number' => $scannedBarcode,
                        'serial_type'   => 'received',
                        'status_id'     => 26,
                    ]);
                } else {
                    // Consumables: one row per barcode+location (idempotent on re-receive)
                    ItemSerial::firstOrCreate(
                        [
                            'product_id'    => $product->product_id,
                            'location_id'   => $locationId,
                            'serial_number' => $scannedBarcode,
                        ],
                        [
                            'receiving_id' => $receiving->receiving_id,
                            'scanned_by'   => $request->user()?->user_id,
                            'serial_type'  => 'received',
                            'status_id'    => 26,
                        ]
                    );
                }
            }

            // Sync PO status (Partially Received / Completed) and received_by
            // inside the transaction so both writes are atomic.
            if ($poId) {
                $this->syncPoReceivingStatus($poId, $request->user()?->user_id);
            }

            DB::commit();

            // Summary audit entry — ties together who received, which PO, which product, which serial
            try {
                \App\Models\AuditTrail::create([
                    'user_id'    => $request->user()?->user_id,
                    'action'     => 'barcode_received',
                    'table_name' => 'receiving_details',
                    'record_id'  => $receiving->receiving_id,
                    'old_values' => null,
                    'new_values' => [
                        'receiving_number'  => $receiving->receiving_number,
                        'product_id'        => $product->product_id,
                        'product_name'      => $product->product_name,
                        'product_type'      => $product->product_type,
                        'scanned_barcode'   => $scannedBarcode ?: null,
                        'quantity_received' => $quantity,
                        'po_id'             => $poId ?: null,
                        'location_id'       => $locationId,
                        'is_serialized'     => $isSerialized,
                    ],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }

            $this->logScanEvent($request, 'receiving', 'applied', $product, $validated, $quantity, null, [
                'receiving_number' => $receiving->receiving_number,
                'is_serialized'    => $isSerialized,
            ]);

            $fresh = $product->fresh()->load([
                'category',
                'status',
                'applianceDetail.brand',                'consumableDetail.unit',
            ]);

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
                'serial_number'      => $scannedBarcode ?: null,
            ];

            if ($poId && $remaining !== null) {
                $remainingPieces = $remaining - $quantity;

                $payload['remaining_after']       = $remainingPieces;
                $payload['remaining_packages']    = $isSerialized
                    ? null
                    : ($piecesPerPackage > 0 ? (int) ceil($remainingPieces / $piecesPerPackage) : null);
                $payload['packages_unit_label']   = $isSerialized
                    ? null
                    : ($product->consumableDetail?->package_unit ?? 'package');
                $payload['ordered_packages']      = $isSerialized
                    ? null
                    : (int) ($poDetail?->quantity_ordered ?? 0);
                $payload['ordered_pieces']        = $orderedPieces;
                $payload['received_pieces_total'] = $alreadyReceived + $quantity;
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
        // FROM location must be the user's assigned location
        $fromLocationId = $this->resolveLocationId($request);
        if ($fromLocationId === null) {
            return $this->locationErrorResponse();
        }

        // TO location is freely chosen but must be a valid active location
        $toLocationId = (int) ($validated['to_location_id'] ?? 0);
        if ($toLocationId <= 0) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Select a destination location for the transfer.',
            ], 'Rejected');
        }
        if ($fromLocationId === $toLocationId) {
            return $this->success([
                'action' => 'rejected',
                'reason' => 'Transfer destination must be different from your assigned location.',
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

            // Keep item_serial in sync with the inventory move
            $isSerialized = $product->isSerialized();
            $scannedBarcode = $this->canonicalSerial((string) ($validated['barcode'] ?? ''));
            if ($scannedBarcode !== '') {
                if ($isSerialized) {
                    // Move the unit's serial record to the destination location
                    ItemSerial::where('product_id', $product->product_id)
                        ->where('location_id', $fromLocationId)
                        ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($scannedBarcode)])
                        ->update(['location_id' => $toLocationId]);
                } else {
                    // Ensure the barcode is registered at the destination
                    ItemSerial::firstOrCreate(
                        [
                            'product_id'    => $product->product_id,
                            'location_id'   => $toLocationId,
                            'serial_number' => $scannedBarcode,
                        ],
                        [
                            'scanned_by'  => $uid,
                            'serial_type' => 'transferred',
                            'status_id'   => 26,
                        ]
                    );
                }
            }

            DB::commit();

            try {
                \App\Models\AuditTrail::create([
                    'user_id'    => $request->user()?->user_id,
                    'action'     => 'stock_transferred',
                    'table_name' => 'transfers',
                    'record_id'  => $transfer->transfer_id,
                    'old_values' => null,
                    'new_values' => [
                        'transfer_number'  => $transferNumber,
                        'from_location_id' => $fromLocationId,
                        'to_location_id'   => $toLocationId,
                        'product_name'     => $product->product_name,
                        'total_quantity'   => $quantity,
                    ],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }

            $this->logScanEvent($request, 'transfer', 'applied', $product, $validated, $quantity, null, [
                'transfer_number' => $transferNumber,
            ]);

            $fresh = $product->fresh()->load([
                'category',
                'status',
                'applianceDetail.brand',                'consumableDetail.unit',
            ]);

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

    private function processAudit(Request $request, Product $product, string $barcode): \Illuminate\Http\JsonResponse
    {
        $locationId = $this->resolveLocationId($request);
        if ($locationId === null) {
            return $this->locationErrorResponse();
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

        $this->logScanEvent($request, 'audit', 'applied', $product,
            ['barcode' => $barcode, 'location_id' => $locationId],
            0, null, ['stock_on_hand' => $stock]);

        $serial = ItemSerial::query()
            ->with(['location', 'status', 'receiving', 'scannedBy'])
            ->where('product_id', $product->product_id)
            ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower(trim($barcode))])
            ->first();

        $history = InventoryScanEvent::query()
            ->with(['user', 'location', 'toLocation', 'purchaseOrder'])
            ->where('product_id', $product->product_id)
            ->where('action', 'applied')
            ->forUser($request->user())
            ->when($serial, function ($query) use ($barcode) {
                $normalized = strtolower(trim($barcode));
                $query->where(function ($q) use ($normalized) {
                    $q->whereRaw('LOWER(TRIM(barcode)) = ?', [$normalized])
                      ->orWhereRaw("LOWER(TRIM(COALESCE(serial_number, ''))) = ?", [$normalized]);
                });
            })
            ->latest('created_at')
            ->limit(50)
            ->get();

        $inventoryRows = collect($this->inventoryRowsForProduct((int) $product->product_id));
        $totalOnHand = $inventoryRows->sum(
            fn ($row) => (int) ($row->quantity_on_hand ?? 0)
        );
        $totalAvailable = $inventoryRows->sum(
            fn ($row) => (int) ($row->available_quantity ?? $row->quantity_on_hand ?? 0)
        );

        $fresh = $product->fresh()->load([
            'category',
            'status',
            'applianceDetail.brand',
            'consumableDetail.unit',
        ]);

        return $this->success([
            'action' => 'applied',
            'product' => $fresh,
            'inventory' => $inventoryRows->values()->all(),
            'warnings' => [],
            'audit' => [
                'location_id'   => $locationId ?: null,
                'location_name' => $locationName,
                'stock_on_hand' => $stock,
                'current_stock' => $stock,
                'total_on_hand' => $totalOnHand,
                'total_available' => $totalAvailable,
                'inventory_locations' => $inventoryRows->count(),
                'matched_by' => $serial ? 'serial_number' : 'product_barcode',
                'serial' => $serial,
                'history_scope' => $serial ? 'serial' : 'product',
                'history' => $history,
            ],
        ], 'Audit logged');
    }

    private function processIssuance(Request $request, Product $product, array $validated, int $quantity): \Illuminate\Http\JsonResponse
    {
        $locationId = $this->resolveLocationId($request);
        if ($locationId === null) {
            return $this->locationErrorResponse();
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

            // Update item_serial to reflect the issuance
            $issuanceBarcode = trim($validated['barcode'] ?? '');
            if ($issuanceBarcode !== '') {
                if ($product->isSerialized()) {
                    // Remove the appliance serial from stock (it left the warehouse)
                    ItemSerial::where('product_id', $product->product_id)
                        ->where('location_id', $locationId)
                        ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower($issuanceBarcode)])
                        ->delete();
                }
                // Consumables are quantity-managed via inventory; no per-row serial removal needed
            }

            DB::commit();

            $this->logScanEvent($request, 'issuance', 'applied', $product, $validated, $quantity);

            $fresh = $product->fresh()->load([
                'category',
                'status',
                'applianceDetail.brand',                'consumableDetail.unit',
            ]);

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

    private function logScanEvent(Request $request, string $type, string $action, Product $product, array $validated, int $quantityDelta = 0, ?string $reason = null, array $meta = []): void
    {
        try {
            InventoryScanEvent::create([
                'user_id'          => $request->user()?->user_id,
                'transaction_type' => $type,
                'action'           => $action,
                'reason'           => $reason,
                'barcode'          => $validated['barcode'] ?? null,
                'product_id'       => $product->product_id,
                'po_id'            => !empty($validated['po_id']) ? (int) $validated['po_id'] : null,
                'location_id'      => !empty($validated['location_id']) ? (int) $validated['location_id']
                                    : (!empty($validated['from_location_id']) ? (int) $validated['from_location_id'] : null),
                'to_location_id'   => !empty($validated['to_location_id']) ? (int) $validated['to_location_id'] : null,
                'quantity_delta'   => $quantityDelta,
                'serial_number'    => !empty($validated['barcode']) && $type === 'receiving' ? $validated['barcode'] : null,
                'meta'             => empty($meta) ? null : $meta,
            ]);
        } catch (\Throwable) {
            // Non-critical — never let logging break the main transaction
        }
    }

    private function syncPoReceivingStatus(int $poId, $userId = null): void
    {
        $details = PurchaseOrderDetail::with('product.consumableDetail')
            ->where('po_id', $poId)
            ->get(['po_detail_id', 'product_id', 'quantity_ordered']);

        if ($details->isEmpty()) {
            return;
        }

        $totalOrdered  = 0;
        $totalReceived = 0;

        foreach ($details as $d) {
            $product = $d->product;
            if (! $product) {
                continue;
            }

            // Ensure extension tables are loaded before calling V5 helpers.
            if (! $product->relationLoaded('applianceDetail')) {
                $product->load('applianceDetail');
            }
            if (! $product->relationLoaded('consumableDetail')) {
                $product->load('consumableDetail');
            }

            $isSerialized  = $product->isSerialized();
            $pcsPerPackage = $product->getPiecesPerPackage();

            // quantity_ordered is in units for appliances, packages for consumables.
            // quantity_amount is always stored in pieces — convert ordered to pieces.
            $orderedPieces = $isSerialized
                ? (int) $d->quantity_ordered
                : (int) $d->quantity_ordered * $pcsPerPackage;

            $receivedPieces = (int) ReceivingDetail::where('po_detail_id', $d->po_detail_id)
                ->sum('quantity_amount');

            $totalOrdered  += $orderedPieces;
            $totalReceived += $receivedPieces;
        }

        $updateData = [];

        if ($totalReceived > 0) {
            // V5 status IDs — hardcoded; no DB string lookup needed.
            // 11 = Completed, 12 = Partially Received
            $newStatusId             = ($totalReceived >= $totalOrdered) ? 11 : 12;
            $updateData['status_id'] = $newStatusId;

            if ($userId !== null) {
                $updateData['received_by'] = $userId;
            }

            PurchaseOrder::where('po_id', $poId)->update($updateData);

            // When PO is fully completed: move budget from committed → spent.
            // Only applies to POs sourced from a PR that has a budget_id.
            if ($newStatusId === 11) {
                $po = PurchaseOrder::with('purchaseRequest')->find($poId);
                if ($po) {
                    app(\App\Services\BudgetService::class)->spend($po, $userId);
                }
            }
        } elseif ($userId !== null) {
            // No items received yet — still stamp received_by on the PO.
            PurchaseOrder::where('po_id', $poId)->update(['received_by' => $userId]);
        }
    }
}
