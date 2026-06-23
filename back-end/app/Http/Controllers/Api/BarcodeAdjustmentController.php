<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Adjustment;
use App\Models\AdjustmentDetail;
use App\Models\AuditTrail;
use App\Models\Inventory;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use App\Models\ScanCorrection;
use App\Support\RealtimeBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BarcodeAdjustmentController extends Controller
{
    use \App\Traits\ResolvesUserLocation;
    // Adjustment status IDs — verified in Phase 2 (status_lookup)
    private const STATUS_PENDING  = 19;
    private const STATUS_APPROVED = 20;
    private const STATUS_REJECTED = 29;

    // Inventory status for a serial record that is In Stock
    private const SERIAL_STATUS_IN_STOCK = 26;

    // PO status IDs
    private const PO_STATUS_COMPLETED         = 11;
    private const PO_STATUS_PARTIALLY_RECEIVED = 12;

    // =========================================================================
    // METHOD 1: getReceivingHistory
    // Lists all scanned items for a given PO so the user can identify which
    // barcode was scanned incorrectly.
    // GET /api/adjustments/receiving-history/{po_id}
    // =========================================================================
    public function getReceivingHistory(int $poId): JsonResponse
    {
        $po = PurchaseOrder::with([
            'details.product.applianceDetail.brand',
            'details.product.consumableDetail.unit',
        ])->find($poId);

        if (! $po) {
            return response()->json(['success' => false, 'message' => 'Purchase order not found.'], 404);
        }

        $receivingDetails = ReceivingDetail::with([
            'product.applianceDetail.brand',
            'product.consumableDetail.unit',
            'receiving',
        ])
        ->whereHas('receiving', fn ($q) => $q->where('po_id', $poId))
        ->get()
        ->map(function (ReceivingDetail $rd) {
            $product = $rd->product;
            if ($product && ! $product->relationLoaded('applianceDetail')) {
                $product->load('applianceDetail', 'consumableDetail');
            }
            return [
                'receiving_detail_id' => $rd->receiving_detail_id,
                'receiving_id'        => $rd->receiving_id,
                'po_detail_id'        => $rd->po_detail_id,
                'product_id'          => $rd->product_id,
                'product_name'        => $product?->product_name,
                'product_type'        => $product?->product_type,
                'is_serialized'       => $product?->isSerialized() ?? false,
                'scanned_barcode'     => $rd->scanned_barcode,
                'quantity_amount'     => $rd->quantity_amount,
                'brand_name'          => $product?->applianceDetail?->brand?->brand_name,
                'capacity'            => $product?->applianceDetail?->capacity_rating ?? null,
                'unit_name'           => $product?->consumableDetail?->unit?->unit_name ?? null,
                'created_at'          => $rd->created_at,
            ];
        });

        // serial records for this PO (appliances)
        $serials = ItemSerial::with('product')
            ->whereHas('receiving', fn ($q) => $q->where('po_id', $poId))
            ->get()
            ->map(fn (ItemSerial $s) => [
                'serial_id'     => $s->serial_id,
                'serial_number' => $s->serial_number,
                'product_id'    => $s->product_id,
                'product_name'  => $s->product?->product_name,
                'status_id'     => $s->status_id,
            ]);

        return response()->json([
            'success'           => true,
            'po_id'             => $poId,
            'po_number'         => $po->po_number,
            'receiving_details' => $receivingDetails,
            'serial_records'    => $serials,
        ]);
    }

    // =========================================================================
    // METHOD 2: previewCorrection
    // Shows what WILL change before the user commits — no DB writes.
    // POST /api/adjustments/preview
    // =========================================================================
    public function previewCorrection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'po_id'                => 'required|integer|exists:purchase_orders,po_id',
            'receiving_detail_id'  => 'required|integer|exists:receiving_details,receiving_detail_id',
            'corrected_product_id' => 'required|integer|exists:products,product_id',
            'corrected_barcode'    => 'nullable|string|max:100',
            'correction_type'      => ['required', Rule::in(['wrong_product', 'wrong_quantity', 'duplicate_scan', 'wrong_serial', 'void_scan'])],
            'reason'               => 'required|string|max:1000',
        ]);

        $rd = ReceivingDetail::with([
            'product.applianceDetail.brand',
            'product.consumableDetail.unit',
        ])->find($validated['receiving_detail_id']);

        if (! $rd) {
            return response()->json(['success' => false, 'message' => 'Receiving detail not found.'], 404);
        }

        $originalProduct = $rd->product;
        if ($originalProduct && ! $originalProduct->relationLoaded('applianceDetail')) {
            $originalProduct->load('applianceDetail', 'consumableDetail');
        }

        $correctedProduct = Product::with(['applianceDetail.brand', 'consumableDetail.unit'])
            ->find($validated['corrected_product_id']);

        if (! $correctedProduct) {
            return response()->json(['success' => false, 'message' => 'Corrected product not found.'], 404);
        }

        $origQty      = (int) $rd->quantity_amount;
        $correctedQty = $correctedProduct->isSerialized()
            ? 1
            : $correctedProduct->getPiecesPerPackage();

        $changes = [];

        if ($originalProduct?->product_id !== $correctedProduct->product_id) {
            $changes[] = "Product will change from \"{$originalProduct?->product_name}\" to \"{$correctedProduct->product_name}\".";
        }

        if ($originalProduct?->isSerialized() && ! $correctedProduct->isSerialized()) {
            $changes[] = 'Type change: Appliance (serialized) → Consumable (bulk). Serial number record will be removed.';
        } elseif (! $originalProduct?->isSerialized() && $correctedProduct->isSerialized()) {
            $changes[] = 'Type change: Consumable (bulk) → Appliance (serialized). A serial number record will be created.';
        }

        $changes[] = "Inventory for \"{$originalProduct?->product_name}\" will decrease by {$origQty} unit(s).";
        $changes[] = "Inventory for \"{$correctedProduct->product_name}\" will increase by {$correctedQty} unit(s).";
        $changes[] = 'This correction will be permanently logged in the audit trail.';

        return response()->json([
            'success' => true,
            'preview' => [
                'original' => [
                    'receiving_detail_id' => $rd->receiving_detail_id,
                    'product_id'          => $originalProduct?->product_id,
                    'product_name'        => $originalProduct?->product_name,
                    'product_type'        => $originalProduct?->product_type,
                    'is_serialized'       => $originalProduct?->isSerialized() ?? false,
                    'quantity'            => $origQty,
                    'barcode'             => $rd->scanned_barcode,
                ],
                'corrected' => [
                    'product_id'         => $correctedProduct->product_id,
                    'product_name'       => $correctedProduct->product_name,
                    'product_type'       => $correctedProduct->product_type,
                    'is_serialized'      => $correctedProduct->isSerialized(),
                    'pieces_per_package' => $correctedProduct->getPiecesPerPackage(),
                    'quantity_after'     => $correctedQty,
                ],
                'changes_that_will_occur' => $changes,
            ],
        ]);
    }

    // =========================================================================
    // METHOD 3: submitCorrection
    // Commits the correction inside a DB transaction.
    // POST /api/adjustments/submit
    // =========================================================================
    public function submitCorrection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'po_id'                => 'required|integer|exists:purchase_orders,po_id',
            'receiving_detail_id'  => 'required|integer|exists:receiving_details,receiving_detail_id',
            'corrected_product_id' => 'required|integer|exists:products,product_id',
            'corrected_barcode'    => 'nullable|string|max:100',
            'correction_type'      => ['required', Rule::in(['wrong_product', 'wrong_quantity', 'duplicate_scan', 'wrong_serial', 'void_scan'])],
            'reason'               => 'required|string|max:1000',
            'location_id'          => 'nullable|integer|exists:locations,location_id',
        ]);

        $resolvedLocationId = $this->resolveLocationId($request);
        if ($resolvedLocationId === null) {
            return $this->locationErrorResponse();
        }

        $userId    = Auth::id();
        $ipAddress = $request->ip();
        $userAgent = $request->userAgent();

        return DB::transaction(function () use ($validated, $resolvedLocationId, $userId, $ipAddress, $userAgent) {

            // ── Load rows with locks ─────────────────────────────────────────
            $rd = ReceivingDetail::with(['product.applianceDetail', 'product.consumableDetail', 'receiving'])
                ->lockForUpdate()
                ->find($validated['receiving_detail_id']);

            if (! $rd) {
                return response()->json(['success' => false, 'message' => 'Receiving detail not found.'], 404);
            }

            $originalProduct = $rd->product;
            if ($originalProduct && ! $originalProduct->relationLoaded('applianceDetail')) {
                $originalProduct->load('applianceDetail', 'consumableDetail');
            }

            /** @var Product|null $correctedProduct */
            $correctedProduct = Product::with(['applianceDetail', 'consumableDetail'])
                ->lockForUpdate()
                ->find($validated['corrected_product_id']);

            if (! $correctedProduct) {
                return response()->json(['success' => false, 'message' => 'Corrected product not found.'], 404);
            }

            // Capture original state BEFORE any updates
            $originalBarcode = $rd->scanned_barcode;
            $originalQty     = (int) $rd->quantity_amount;
            $correctedQty    = $correctedProduct->isSerialized()
                ? 1
                : $correctedProduct->getPiecesPerPackage();

            // ── 1. Build adjustment header ───────────────────────────────────
            $adjustmentNumber = 'ADJ-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

            $adjustment = Adjustment::create([
                'location_id'             => $resolvedLocationId,
                'po_id'                   => $validated['po_id'],
                'receiving_id'            => $rd->receiving_id,
                'created_by'              => $userId,
                'adjusted_by'             => $userId,
                'adjustment_number'       => $adjustmentNumber,
                'adjustment_date'         => now()->toDateString(),
                'adjustment_type'         => 'barcode_correction',
                'total_variance_positive' => $correctedQty,
                'total_variance_negative' => $originalQty,
                'notes'                   => $validated['reason'],
                'status_id'               => self::STATUS_PENDING,
            ]);

            // ── 2. Detail — deduct original product ──────────────────────────
            AdjustmentDetail::create([
                'adjustment_id'   => $adjustment->adjustment_id,
                'product_id'      => $originalProduct?->product_id,
                'add_quantity'    => 0,
                'deduct_quantity' => $originalQty,
                'reason'          => 'Correction: original erroneous scan removed',
            ]);

            // ── 3. Detail — add corrected product ────────────────────────────
            AdjustmentDetail::create([
                'adjustment_id'   => $adjustment->adjustment_id,
                'product_id'      => $correctedProduct->product_id,
                'add_quantity'    => $correctedQty,
                'deduct_quantity' => 0,
                'reason'          => 'Correction: replaced with correct product',
            ]);

            // ── 4. Find serial record for original (if appliance) ────────────
            $serialRecord = null;
            if ($originalProduct?->isSerialized() && $originalBarcode) {
                $serialRecord = ItemSerial::where('serial_number', $originalBarcode)
                    ->where('product_id', $originalProduct->product_id)
                    ->first();
            }

            // ── 5. Create scan_correction record ─────────────────────────────
            $correctedBarcode = $validated['corrected_barcode'] ?? $originalBarcode;

            $correction = ScanCorrection::create([
                'adjustment_id'        => $adjustment->adjustment_id,
                'po_id'                => $validated['po_id'],
                'receiving_id'         => $rd->receiving_id,
                'original_barcode'     => $originalBarcode,
                'original_product_id'  => $originalProduct?->product_id,
                'corrected_barcode'    => $correctedBarcode,
                'corrected_product_id' => $correctedProduct->product_id,
                'correction_type'      => $validated['correction_type'],
                'quantity_before'      => $originalQty,
                'quantity_after'       => $correctedQty,
                'serial_number_before' => $serialRecord?->serial_number,
                'serial_number_after'  => $correctedProduct->isSerialized() ? $correctedBarcode : null,
                'receiving_detail_id'  => $rd->receiving_detail_id,
                'serial_id'            => $serialRecord?->serial_id,
                'reason'               => $validated['reason'],
                'corrected_by'         => $userId,
            ]);

            // ── 6. Decrement inventory for original product ──────────────────
            if ($originalProduct) {
                $invOrig = Inventory::where('product_id', $originalProduct->product_id)
                    ->where('location_id', $resolvedLocationId)
                    ->first();

                if ($invOrig) {
                    $invOrig->quantity_on_hand    = max(0, $invOrig->quantity_on_hand - $originalQty);
                    $invOrig->available_quantity  = max(0, $invOrig->available_quantity - $originalQty);
                    if (! $originalProduct->isSerialized()) {
                        $invOrig->packages_on_hand = max(0, ($invOrig->packages_on_hand ?? 0) - 1);
                    }
                    $invOrig->save();
                }
            }

            // ── 7. Remove serial record if original was appliance ────────────
            $serialRecord?->delete();

            // ── 8. Update receiving_detail to the corrected product ──────────
            if ($rd instanceof ReceivingDetail) {
                $rd->update([
                    'product_id'      => $correctedProduct->product_id,
                    'scanned_barcode' => $correctedBarcode,
                    'quantity_amount' => $correctedQty,
                ]);
            } else {
                ReceivingDetail::where('receiving_detail_id', $validated['receiving_detail_id'])
                    ->update([
                        'product_id'      => $correctedProduct->product_id,
                        'scanned_barcode' => $correctedBarcode,
                        'quantity_amount' => $correctedQty,
                    ]);
            }

            // ── 9. Increment inventory for corrected product ─────────────────
            $invCorr = Inventory::firstOrCreate(
                [
                    'product_id'  => $correctedProduct->product_id,
                    'location_id' => $resolvedLocationId,
                ],
                [
                    'quantity_on_hand'   => 0,
                    'packages_on_hand'   => 0,
                    'available_quantity' => 0,
                    'reorder_level'      => 0,
                ]
            );
            $invCorr->quantity_on_hand   += $correctedQty;
            $invCorr->available_quantity += $correctedQty;
            if (! $correctedProduct->isSerialized()) {
                $invCorr->packages_on_hand = ($invCorr->packages_on_hand ?? 0) + 1;
            }
            $invCorr->save();

            // ── 10. Create serial record if corrected product is appliance ───
            if ($correctedProduct->isSerialized() && $correctedBarcode) {
                ItemSerial::create([
                    'product_id'    => $correctedProduct->product_id,
                    'location_id'   => $resolvedLocationId,
                    'receiving_id'  => $rd->receiving_id,
                    'scanned_by'    => $userId,
                    'serial_number' => $correctedBarcode,
                    'serial_type'   => 'received',
                    'status_id'     => self::SERIAL_STATUS_IN_STOCK,
                ]);
            }

            // ── 11. Write to audit_trail (pass arrays — model casts as array) ─
            AuditTrail::create([
                'user_id'    => $userId,
                'action'     => 'barcode_correction',
                'table_name' => 'scan_corrections',
                'record_id'  => $correction->correction_id,
                'old_values' => [
                    'barcode'      => $originalBarcode,
                    'product_id'   => $originalProduct?->product_id,
                    'product_name' => $originalProduct?->product_name,
                    'quantity'     => $originalQty,
                ],
                'new_values' => [
                    'barcode'      => $correctedBarcode,
                    'product_id'   => $correctedProduct->product_id,
                    'product_name' => $correctedProduct->product_name,
                    'quantity'     => $correctedQty,
                    'reason'       => $validated['reason'],
                ],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

            // ── 12. Re-sync PO receiving status ──────────────────────────────
            $this->syncPoReceivingStatus($validated['po_id']);

            RealtimeBroadcaster::inventoryChanged((int) $resolvedLocationId, 'stock_corrected');

            return response()->json([
                'success'            => true,
                'message'            => 'Barcode correction applied successfully.',
                'adjustment_id'      => $adjustment->adjustment_id,
                'adjustment_number'  => $adjustmentNumber,
                'correction_id'      => $correction->correction_id,
            ], 201);
        });
    }

    // =========================================================================
    // METHOD 4: getAdjustmentHistory
    // Returns adjustments with nested corrections for compliance view.
    // GET /api/adjustments/correction-history
    // =========================================================================
    public function getAdjustmentHistory(Request $request): JsonResponse
    {
        $query = Adjustment::with([
            'corrections.originalProduct.applianceDetail.brand',
            'corrections.correctedProduct.applianceDetail.brand',
            'corrections.correctedByUser',
            'location',
            'status',
            'createdByUser',
            'purchaseOrder',
        ])->orderBy('created_at', 'desc');

        if ($request->filled('po_id')) {
            $query->where('po_id', $request->integer('po_id'));
        }
        if ($request->filled('location_id')) {
            $query->where('location_id', $request->integer('location_id'));
        }
        if ($request->filled('adjustment_type')) {
            $query->where('adjustment_type', $request->string('adjustment_type'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('adjustment_date', '>=', $request->string('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('adjustment_date', '<=', $request->string('date_to'));
        }

        $results = $query->paginate($request->integer('per_page', 20));

        return response()->json(['success' => true, 'data' => $results]);
    }

    // =========================================================================
    // METHOD 5: getComplianceLogs
    // Returns audit_trail filtered to inventory/scan compliance actions.
    // GET /api/compliance/logs
    // =========================================================================
    public function getComplianceLogs(Request $request): JsonResponse
    {
        $relevantActions = [
            'barcode_correction',
            'receiving',
            'inventory_adjustment',
            'stock_transfer',
            'stock_issuance',
        ];

        $query = AuditTrail::with('user')
            ->whereIn('action', $relevantActions)
            ->orderBy('created_at', 'desc');

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->string('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->string('date_to'));
        }

        $logs = $query->paginate($request->integer('per_page', 25));

        return response()->json(['success' => true, 'data' => $logs]);
    }

    // =========================================================================
    // METHOD 6: getScanCorrectionDetail
    // Returns full detail of one correction for the log/review view.
    // GET /api/adjustments/corrections/{correction_id}
    // =========================================================================
    public function getScanCorrectionDetail(int $correctionId): JsonResponse
    {
        $correction = ScanCorrection::with([
            'adjustment.location',
            'adjustment.status',
            'originalProduct.applianceDetail.brand',
            'originalProduct.consumableDetail.unit',
            'correctedProduct.applianceDetail.brand',
            'correctedProduct.consumableDetail.unit',
            'correctedByUser',
            'approvedByUser',
            'receivingDetail',
            'purchaseOrder',
        ])->find($correctionId);

        if (! $correction) {
            return response()->json(['success' => false, 'message' => 'Correction record not found.'], 404);
        }

        return response()->json(['success' => true, 'data' => $correction]);
    }

    // =========================================================================
    // PRIVATE: syncPoReceivingStatus
    // Re-evaluates PO completion after a correction.
    // =========================================================================
    // METHOD 7: lookupBarcode
    // Finds which PO received a given scanned barcode so the user can skip
    // manual PO lookup — just scan the wrong item to find everything.
    // GET /api/adjustments/lookup-barcode?barcode=XXX
    // =========================================================================
    public function lookupBarcode(Request $request): JsonResponse
    {
        $barcode = trim($request->string('barcode'));

        if ($barcode === '') {
            return response()->json(['success' => false, 'message' => 'Barcode is required.'], 422);
        }

        // Search receiving_details by scanned_barcode
        $rd = ReceivingDetail::with([
            'product.applianceDetail.brand',
            'product.consumableDetail.unit',
            'receiving',
        ])
        ->where('scanned_barcode', $barcode)
        ->latest()
        ->first();

        // Fallback: search item_serial by serial_number
        if (! $rd) {
            $serial = ItemSerial::with(['receiving.details.product.applianceDetail.brand'])->where('serial_number', $barcode)->latest()->first();
            if ($serial?->receiving) {
                $rd = $serial->receiving->details->first();
            }
        }

        if (! $rd || ! $rd->receiving) {
            return response()->json(['success' => false, 'message' => "No received item found for barcode \"{$barcode}\"."], 404);
        }

        $poId = $rd->receiving->po_id;
        $po   = PurchaseOrder::find($poId);

        if (! $po) {
            return response()->json(['success' => false, 'message' => 'Associated PO not found.'], 404);
        }

        $product = $rd->product;

        return response()->json([
            'success'             => true,
            'po_id'               => $poId,
            'po_number'           => $po->po_number,
            'receiving_detail_id' => $rd->receiving_detail_id,
            'product_name'        => $product?->product_name,
            'product_type'        => $product?->product_type,
            'scanned_barcode'     => $rd->scanned_barcode,
            'quantity_amount'     => $rd->quantity_amount,
        ]);
    }

    // =========================================================================
    private function syncPoReceivingStatus(int $poId): void
    {
        $details = PurchaseOrderDetail::with(['product.applianceDetail', 'product.consumableDetail'])
            ->where('po_id', $poId)
            ->get();

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

            $pcsPerPackage = $product->getPiecesPerPackage();
            $orderedPieces = $product->isSerialized()
                ? (int) $d->quantity_ordered
                : (int) $d->quantity_ordered * $pcsPerPackage;

            $receivedPieces = (int) ReceivingDetail::where('po_detail_id', $d->po_detail_id)
                ->sum('quantity_amount');

            $totalOrdered  += $orderedPieces;
            $totalReceived += $receivedPieces;
        }

        if ($totalReceived > 0) {
            $newStatusId = ($totalReceived >= $totalOrdered)
                ? self::PO_STATUS_COMPLETED
                : self::PO_STATUS_PARTIALLY_RECEIVED;

            PurchaseOrder::where('po_id', $poId)->update(['status_id' => $newStatusId]);
        }
    }
}
