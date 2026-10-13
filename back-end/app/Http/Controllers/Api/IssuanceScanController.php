<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\Customer;
use App\Models\DeliveryReceipt;
use App\Models\Inventory;
use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Models\WarrantyRecord;
use App\Services\InventoryBatchService;
use App\Support\IssuanceStatus;
use App\Support\PermissionResolver;
use App\Support\ScanResult;
use App\Traits\ApiResponse;
use App\Traits\NormalizesBarcode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IssuanceScanController extends Controller
{
    use ApiResponse;
    use \App\Traits\ResolvesUserLocation;
    use NormalizesBarcode;

    // Status ID constants — verified against status_lookup 2026-05-20
    private const STATUS_ISSUANCE_PENDING   = 30;
    private const STATUS_ISSUANCE_COMPLETED = 31;
    private const STATUS_WARRANTY_ACTIVE    = 33;
    private const STATUS_SERIAL_IN_STOCK    = 26;

    // =========================================================
    // METHOD 1: initSession()
    // POST /api/issuance-scan/init
    // Starts a pending issuance session before scanning begins.
    // =========================================================
    public function initSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id'      => 'required|integer|exists:customers,customer_id',
            'issuance_type'    => ['required', Rule::in(['business', 'individual'])],
            'discount_type'    => ['nullable', Rule::in(['none', 'business', 'senior', 'custom'])],
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'location_id'      => 'nullable|integer|exists:locations,location_id',
            'external_reference' => 'nullable|string|max:100',
            'notes'            => 'nullable|string|max:1000',
        ]);

        $resolvedLocationId = $this->resolveLocationId($request);
        if ($resolvedLocationId === null) {
            return $this->locationErrorResponse();
        }

        $userId = Auth::id();

        $discountType    = $validated['discount_type'] ?? 'none';
        $discountPercent = 0;

        if ($validated['issuance_type'] === 'business') {
            $discountPercent = (float) ($validated['discount_percent'] ?? 0);
            $discountType    = $validated['discount_type'] ?? 'business';
        }

        // Reuse the caller's own EMPTY open Draft (a started-but-abandoned
        // placeholder with no lines yet) so repeat starts don't stack empty
        // records — but refresh its header with the newly submitted setup so it
        // reflects the current customer/terms, not a stale abandoned start. A
        // draft that already has lines is a real in-progress order: leave it for
        // the Transactions "Continue"/"Cancel" flow and start a fresh one here.
        $emptyDraft = $this->openEmptyDraftFor($userId);

        if ($emptyDraft) {
            $emptyDraft->update([
                'location_id'      => $resolvedLocationId,
                'customer_id'      => $validated['customer_id'],
                'issuance_type'    => $validated['issuance_type'],
                'discount_type'    => $discountType,
                'discount_percent' => $discountPercent,
                'external_reference' => $validated['external_reference'] ?? null,
                'notes'            => $validated['notes'] ?? null,
            ]);

            return $this->initSessionResponse($emptyDraft->fresh(), 'Issuance session started.', 200);
        }

        $drNumber = 'DR-' . now()->format('Ymd') . '-'
                   . strtoupper(substr(uniqid(), -5));

        $issuance = Issuance::create([
            'location_id'      => $resolvedLocationId,
            'customer_id'      => $validated['customer_id'],
            'issuance_date'    => now()->toDateString(),
            'issuance_type'    => $validated['issuance_type'],
            'dr_number'        => $drNumber,
            'external_reference' => $validated['external_reference'] ?? null,
            'discount_type'    => $discountType,
            'discount_percent' => $discountPercent,
            'subtotal'         => 0,
            'discount_amount'  => 0,
            'total_amount'     => 0,
            'notes'            => $validated['notes'] ?? null,
            'issued_by'        => $userId,
            'status_id'        => \App\Support\IssuanceStatus::draft(),
        ]);

        return $this->initSessionResponse($issuance, 'Issuance session started.', 201);
    }

    // Builds the identical init-session response for both the reused Draft and a
    // freshly created one, so the wizard sees the same shape either way.
    private function initSessionResponse(Issuance $issuance, string $message, int $status): JsonResponse
    {
        $customer = Customer::find($issuance->customer_id);

        return response()->json([
            'success'          => true,
            'message'          => $message,
            'issuance_id'      => $issuance->issuance_id,
            'dr_number'        => $issuance->dr_number,
            'external_reference' => $issuance->external_reference,
            'issuance_type'    => $issuance->issuance_type,
            'discount_type'    => $issuance->discount_type,
            'discount_percent' => $issuance->discount_percent,
            'customer'         => [
                'customer_id'    => $customer->customer_id,
                'customer_name'  => $customer->customer_name,
                'customer_type'  => $customer->customer_type,
                'company_name'   => $customer->company_name,
                'contact_number' => $customer->contact_number,
                'email'          => $customer->email,
                'address'        => $customer->address,
            ],
        ], $status);
    }

    // =========================================================
    // GET /api/issuance-scan/active-draft
    // The caller's current open Draft id, or null.
    // =========================================================
    public function activeDraft(): JsonResponse
    {
        $draft = $this->openDraftFor(Auth::id());

        return response()->json([
            'success'     => true,
            'issuance_id' => $draft?->issuance_id !== null ? (int) $draft->issuance_id : null,
        ]);
    }

    /** The single home for the "one open Draft per user" rule: newest open Draft, or null. */
    private function openDraftFor($userId): ?Issuance
    {
        return Issuance::where('issued_by', $userId)
            ->where('status_id', \App\Support\IssuanceStatus::draft())
            ->orderByDesc('issuance_id')
            ->first();
    }

    /** Newest open Draft that has no lines yet — a reusable started-but-empty placeholder. */
    private function openEmptyDraftFor($userId): ?Issuance
    {
        return Issuance::where('issued_by', $userId)
            ->where('status_id', \App\Support\IssuanceStatus::draft())
            ->whereDoesntHave('details')
            ->orderByDesc('issuance_id')
            ->first();
    }

    // =========================================================
    // METHOD 2: scanItem()
    // POST /api/issuance-scan/scan
    // Scans a barcode to issue one item from inventory.
    // =========================================================
    public function scanItem(Request $request): JsonResponse
    {
        // Normalize quantity_unit: lowercase + spaces → underscores so that
        // unit_name values from the dropdown (e.g. "Quarter Roll", "3/8 Roll",
        // "Kilogram") all match the whitelist entries below.
        if ($request->has('quantity_unit') && is_string($request->quantity_unit)) {
            $normalized = strtolower(trim($request->quantity_unit));
            $normalized = str_replace(' ', '_', $normalized);
            $request->merge(['quantity_unit' => $normalized]);
        }

        $validated = $request->validate([
            'issuance_id'   => 'required|integer|exists:issuances,issuance_id',
            'barcode'       => 'required|string|max:191',
            'quantity'      => 'nullable|numeric|min:0.001',
            'quantity_unit' => ['nullable', Rule::in([
                // Standard units (match unit_name after lowercase + underscore)
                'unit', 'piece', 'meter', 'roll', 'kg', 'kilogram',
                'liter', 'set', 'box',
                // Roll fractions for copper tube
                'quarter_roll', 'half_roll', '3/8_roll',
            ])],
            'location_id'   => 'nullable|integer|exists:locations,location_id',
            'confirmed_zone' => 'nullable|string|max:100',
            // FIFO override: an explicit, permissioned acceptance of picking
            // out of order, carrying the reason that justifies it.
            'accept_fifo_override' => 'nullable|boolean',
            'override_reason'      => 'nullable|string|min:10|max:255',
        ]);

        $issuance = Issuance::with('details')->find($validated['issuance_id']);

        if (! $issuance) {
            return response()->json([
                'success' => false,
                'action'  => 'rejected',
                'reason'  => 'Issuance session not found.',
            ], 404);
        }

        if ((int) $issuance->status_id === \App\Support\IssuanceStatus::draft()) {
            return response()->json([
                'success' => false,
                'action'  => 'rejected',
                'reason'  => 'This order is still a draft. Confirm the quote with the customer before scanning.',
            ], 422);
        }

        if ((int) $issuance->status_id !== self::STATUS_ISSUANCE_PENDING) {
            return response()->json([
                'success' => false,
                'action'  => 'rejected',
                'reason'  => 'Issuance session not found or already completed.',
            ]);
        }

        // Use the location the session was started with — already validated in initSession().
        // Do NOT re-resolve from the user/request to avoid any mismatch.
        $locationId = (int) $issuance->location_id;

        $raw = $this->canonicalSerial((string) $validated['barcode']);

        $lines = $issuance->details
            ->groupBy('product_id')
            ->map(fn ($rows, $pid) => [
                'product_id' => (int) $pid,
                'quantity'   => (int) $rows->sum('quantity_requested'),
                'picked'     => (int) $rows->sum('quantity_issued'),
            ])->values()->all();

        $verdict = app(\App\Services\FifoPickingService::class)->validateScan(
            $locationId,
            $lines,
            $raw,
            $validated['confirmed_zone'] ?? null,
            null,
            app(\App\Services\IncidentService::class)->excludedBatchIds($locationId)
        );

        // Only a FIFO violation may be overridden. Guarding on the status
        // rather than on ! accepted() is deliberate: an override means "I know
        // this is not the oldest stock and here is why", never "let me scan the
        // wrong item" — UNKNOWN_BARCODE, WRONG_PRODUCT and OVER_PICK stay hard
        // rejections no matter what the client sends.
        $fifoOverrideReason = null;

        if (! $verdict->accepted()) {
            $wantsOverride = $verdict->status === ScanResult::FIFO_VIOLATION
                && $request->boolean('accept_fifo_override');

            $rejection = null;

            if (! $wantsOverride) {
                $rejection = $verdict->message;
            } else {
                $reason = trim((string) ($validated['override_reason'] ?? ''));

                if (mb_strlen($reason) < 10) {
                    $rejection = 'A FIFO override needs a written reason of at least 10 characters.';
                } elseif (! app(PermissionResolver::class)
                    ->canUser((int) auth()->id(), 'inventory_issuance', 'fifo_override')) {
                    $rejection = 'You do not have permission to override FIFO. Ask a supervisor, or file an Incident Report to pick from elsewhere.';
                } else {
                    $fifoOverrideReason = $reason;
                }
            }

            if ($rejection !== null) {
                return response()->json([
                    'success'     => false,
                    'action'      => 'rejected',
                    'reason'      => $rejection,
                    'scan_result' => $verdict->toArray(),
                ], 422);
            }
        }

        $resolved     = $this->resolveScan($raw, $locationId);
        $product      = $resolved['product'];
        $serialRecord = $resolved['serial'];

        if (! $product) {
            return response()->json([
                'success' => false,
                'action'  => 'rejected',
                'reason'  => 'Barcode not found. Ensure the item is stocked at this location.',
            ]);
        }

        if (! $product->relationLoaded('applianceDetail')) {
            $product->load('applianceDetail.acType', 'installationMaterialDetail');
        }

        $isSerialized     = $product->isSerialized();
        $piecesPerPackage = $product->getPiecesPerPackage();

        // ── APPLIANCE BRANCH ──────────────────────────────────
        if ($isSerialized) {
            if (! $serialRecord) {
                return response()->json([
                    'success' => false,
                    'action'  => 'rejected',
                    'reason'  => 'This is a serialized appliance. Scan the individual unit serial number.',
                ]);
            }
            $canonicalSerial = (string) $serialRecord->serial_number;

            $alreadyAdded = IssuanceDetail::where('issuance_id', $issuance->issuance_id)
                ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower(trim($canonicalSerial))])
                ->exists();
            if ($alreadyAdded) {
                return response()->json([
                    'success' => false,
                    'action'  => 'rejected',
                    'reason'  => "Serial {$raw} is already in this issuance.",
                ]);
            }

            $unitPrice    = $product->unit_price;
            $lineSubtotal = $unitPrice;

            return DB::transaction(function () use (
                $issuance, $product, $serialRecord, $canonicalSerial,
                $unitPrice, $lineSubtotal, $locationId, $fifoOverrideReason
            ) {
                $inv = Inventory::where('product_id', $product->product_id)
                    ->where('location_id', $locationId)
                    ->lockForUpdate()->first();

                if (! $inv || $inv->quantity_on_hand < 1) {
                    return response()->json([
                        'success' => false,
                        'action'  => 'rejected',
                        'reason'  => 'Insufficient stock for this item.',
                    ]);
                }

                $stockBefore = (int) $inv->quantity_on_hand;
                $inv->setPieces((int) $inv->quantity_on_hand - 1, 0);
                $inv->save();

                app(InventoryBatchService::class)->deductFromReceiving(
                    $serialRecord->receiving_id, $product->product_id, $locationId, 1
                );

                $serialReceivingId = $serialRecord->receiving_id;
                $serialBatch = \App\Models\InventoryBatch::where('product_id', $product->product_id)
                    ->where('location_id', $locationId)
                    ->where('receiving_id', $serialReceivingId)
                    ->first();
                $serialRecord->delete();

                $detail = IssuanceDetail::create([
                    'issuance_id'          => $issuance->issuance_id,
                    'product_id'           => $product->product_id,
                    'serial_number'        => $canonicalSerial,
                    'receiving_id'         => $serialReceivingId,
                    'batch_id'             => $serialBatch?->batch_id,
                    'zone_label'           => $serialBatch?->zone_label,
                    'fifo_override_reason' => $fifoOverrideReason,
                    'stock_before'         => $stockBefore,
                    'quantity_deducted'    => 1,
                    'stock_after'          => (int) $inv->quantity_on_hand,
                    'unit_price'           => $unitPrice,
                    'quantity_issued'      => 1,
                    'quantity_unit'        => 'unit',
                    'quantity_in_pieces'   => 1,
                    'unit_price_per_piece' => $unitPrice,
                    'subtotal'             => $unitPrice,
                    'condition_issued'     => 'Good',
                ]);

                if ($serialBatch) {
                    \App\Models\InventoryBatchMovement::create([
                        'batch_id' => $serialBatch->batch_id,
                        'movement_type' => 'stock_out',
                        'issuance_id' => $issuance->issuance_id,
                        'product_id' => $product->product_id,
                        'from_location_id' => $locationId,
                        'quantity' => 1,
                        'serial_number' => $canonicalSerial,
                        'performed_by' => auth()->id(),
                        'moved_at' => now(),
                    ]);
                }

                // A serialized unit leaves one at a time and the serial is the
                // evidence, so it keys the posting: re-scanning the same unit
                // collapses onto the same ledger row instead of issuing it twice.
                //
                // $serialBatch was resolved before $serialRecord->delete() above —
                // after the delete there is no row left to read the batch from, and
                // the cost would be lost with it.
                $serialCost = $serialBatch?->unit_cost;

                app(\App\Services\Ledger\LedgerPoster::class)->post([
                    'occurred_at'      => now(),
                    'direction'        => 'out',
                    'movement_type'    => 'issuance',
                    'product_id'       => (int) $product->product_id,
                    'batch_id'         => $serialBatch?->batch_id,
                    'serial_number'    => $canonicalSerial,
                    'quantity'         => 1,
                    'unit_cost'        => $serialCost !== null ? (float) $serialCost : null,
                    'cost_basis'       => $serialCost !== null ? ($serialBatch->cost_basis ?? 'actual') : 'unavailable',
                    'from_location_id' => (int) $locationId,
                    'source_type'      => 'issuances',
                    'source_id'        => (int) $issuance->issuance_id,
                    'source_line_id'   => (int) $detail->issuance_detail_id,
                    'correlation_id'   => 'issuances:' . $issuance->issuance_id,
                    'actor_user_id'    => auth()->id(),
                    'posting_key'      => 'issuance:' . $issuance->issuance_id
                                          . ':serial:' . $canonicalSerial . ':out',
                ]);

                $this->logScanEvent(
                    $issuance->issuance_id,
                    $product->product_id,
                    $locationId,
                    $canonicalSerial,
                    'applied',
                    1
                );

                $this->recalculateTotals($issuance->issuance_id);

                return response()->json([
                    'success'         => true,
                    'action'          => 'applied',
                    'product_type'    => 'appliance',
                    'product_id'      => $product->product_id,
                    'product_name'    => $product->product_name,
                    'product_code'    => $product->product_code,
                    'model_code'      => $product->applianceDetail?->model_code,
                    'brand_name'      => $product->applianceDetail?->brand?->brand_name,
                    'capacity_rating' => $product->applianceDetail?->capacity_rating,
                    // The picker has to see which half of a split aircon this
                    // is: indoor and outdoor share a brand, a capacity and a
                    // near-identical name, so the role is the only thing
                    // telling the two cartons apart on the scan screen.
                    'unit_role'       => $product->applianceDetail?->unit_role,
                    'ac_type_name'    => $product->applianceDetail?->acType?->ac_type_name,
                    'variant'         => $product->applianceDetail?->variant,
                    'serial_number'   => $canonicalSerial,
                    'unit_price'      => $unitPrice,
                    'quantity'        => 1,
                    'quantity_unit'   => 'unit',
                    'subtotal'        => $unitPrice,
                    'detail_id'       => $detail->issuance_detail_id,
                    'stock_remaining' => $inv->quantity_on_hand,
                    'has_warranty'    => ($product->applianceDetail?->warranty_period_months ?? 0) > 0,
                    'warranty_months' => $product->applianceDetail?->warranty_period_months ?? 0,
                ]);
            });
        }

        // ── INSTALLATION MATERIAL BRANCH ─────────────────────────────────

        // Ensure installationMaterialDetail.unit is loaded.
        if (! $product->relationLoaded('installationMaterialDetail')) {
            $product->load('installationMaterialDetail.unit');
        } else {
            if ($product->installationMaterialDetail && ! $product->installationMaterialDetail->relationLoaded('unit')) {
                $product->installationMaterialDetail->load('unit');
            }
        }

        $scannedCode = trim((string) $validated['barcode']);
        $requestedQty = (float) ($validated['quantity'] ?? 1);
        $quantityUnit = $validated['quantity_unit']
                       ?? ($product->installationMaterialDetail?->unit?->unit_abbreviation ?? 'piece');
        // round before ceil so float dust (e.g. 5.000001) can't bump a whole number up
        $quantityInPieces = (int) ceil(round($requestedQty, 4));

        $packagePrice  = $product->unit_price;
        $pricePerPiece = $piecesPerPackage > 0
            ? round($packagePrice / $piecesPerPackage, 4)
            : $packagePrice;
        $lineSubtotal  = round($pricePerPiece * $requestedQty, 2);

        $baseUnitName = $product->installationMaterialDetail?->unit?->unit_name ?? 'piece';
        $baseUnitAbbr = $product->installationMaterialDetail?->unit?->unit_abbreviation ?? 'pc';

        return DB::transaction(function () use (
            $issuance, $product, $requestedQty, $quantityUnit,
            $quantityInPieces, $packagePrice, $pricePerPiece,
            $lineSubtotal, $locationId, $piecesPerPackage,
            $baseUnitName, $baseUnitAbbr, $fifoOverrideReason, $verdict, $scannedCode
        ) {
            // Serialize scans for this order and re-check the unpicked quantity
            // inside the transaction. Two fast scanner/manual submissions must
            // never both pass the earlier FIFO snapshot and over-deduct stock.
            Issuance::whereKey($issuance->issuance_id)->lockForUpdate()->first();
            $remainingToPick = (int) IssuanceDetail::where('issuance_id', $issuance->issuance_id)
                ->where('product_id', $product->product_id)
                ->selectRaw('COALESCE(SUM(quantity_requested), 0) - COALESCE(SUM(quantity_issued), 0) AS remaining')
                ->value('remaining');

            if ($quantityInPieces > $remainingToPick) {
                return response()->json([
                    'success' => false,
                    'action'  => 'rejected',
                    'reason'  => "Only {$remainingToPick} piece(s) remain to be picked for this item.",
                ], 422);
            }

            $inv = Inventory::where('product_id', $product->product_id)
                ->where('location_id', $locationId)
                ->lockForUpdate()->first();

            if (! $inv || $inv->quantity_on_hand < $quantityInPieces) {
                $available = $inv?->quantity_on_hand ?? 0;
                return response()->json([
                    'success' => false,
                    'action'  => 'rejected',
                    'reason'  => "Insufficient stock. Requested: {$quantityInPieces}, Available: {$available}.",
                ]);
            }

            $stockBefore = (int) $inv->quantity_on_hand;
            $inv->setPieces((int) $inv->quantity_on_hand - $quantityInPieces, $piecesPerPackage);
            $inv->save();

            // An authorised override means the picker physically took the batch
            // they scanned, so the ledger must follow the goods. Deducting FIFO
            // here would drain the oldest batch on paper while its stock sits
            // untouched on the shelf, and every later FIFO decision for this
            // product would be computed from wrong remaining quantities.
            $touched = ($fifoOverrideReason !== null && $verdict->batchId !== null)
                ? app(InventoryBatchService::class)->deductFromBatch(
                    (int) $verdict->batchId, $quantityInPieces, (int) $product->product_id, $locationId
                )
                : app(InventoryBatchService::class)
                    ->deductFifo($product->product_id, $locationId, $quantityInPieces);
            $batchId = array_key_first($touched);
            $batch = $batchId
                ? \App\Models\InventoryBatch::find($batchId)
                : null;

            $detail = IssuanceDetail::create([
                'issuance_id'          => $issuance->issuance_id,
                'product_id'           => $product->product_id,
                'serial_number'        => $scannedCode,
                'receiving_id'         => $batch?->receiving_id,
                'batch_id'             => $batchId,
                'zone_label'           => $batch?->zone_label,
                'fifo_override_reason' => $fifoOverrideReason,
                'stock_before'         => $stockBefore,
                'quantity_deducted'    => $quantityInPieces,
                'stock_after'          => (int) $inv->quantity_on_hand,
                'unit_price'           => $packagePrice,
                'quantity_issued'      => (int) $requestedQty,
                'quantity_unit'        => $quantityUnit,
                'quantity_in_pieces'   => $quantityInPieces,
                'unit_price_per_piece' => $pricePerPiece,
                'subtotal'             => $lineSubtotal,
                'condition_issued'     => 'Good',
            ]);

            foreach ($touched as $touchedBatchId => $deductedQuantity) {
                if ($deductedQuantity <= 0) continue;
                \App\Models\InventoryBatchMovement::create([
                    'batch_id' => $touchedBatchId,
                    'movement_type' => 'stock_out',
                    'issuance_id' => $issuance->issuance_id,
                    'product_id' => $product->product_id,
                    'from_location_id' => $locationId,
                    'quantity' => $deductedQuantity,
                    'performed_by' => auth()->id(),
                    'moved_at' => now(),
                ]);

                // One ledger row per batch slice, each at the cost that slice came
                // in at. A pick spanning two batches at different prices is two
                // rows, not one averaged row — which is the whole point of costing
                // stock-out from the batch rather than the product's list price.
                //
                // Keyed on the detail row rather than on a counter: it is the only
                // identity a scan has that stays stable across a retry of the same
                // request, so a repeated scan cannot double-count the value.
                $sliceBatch = \App\Models\InventoryBatch::find($touchedBatchId);
                $sliceCost  = $sliceBatch?->unit_cost;

                app(\App\Services\Ledger\LedgerPoster::class)->post([
                    'occurred_at'      => now(),
                    'direction'        => 'out',
                    'movement_type'    => 'issuance',
                    'product_id'       => (int) $product->product_id,
                    'serial_number'    => $scannedCode,
                    'batch_id'         => (int) $touchedBatchId,
                    'quantity'         => (int) $deductedQuantity,
                    'unit_cost'        => $sliceCost !== null ? (float) $sliceCost : null,
                    'cost_basis'       => $sliceCost !== null ? ($sliceBatch->cost_basis ?? 'actual') : 'unavailable',
                    'from_location_id' => (int) $locationId,
                    'source_type'      => 'issuances',
                    'source_id'        => (int) $issuance->issuance_id,
                    'source_line_id'   => (int) $detail->issuance_detail_id,
                    'correlation_id'   => 'issuances:' . $issuance->issuance_id,
                    'actor_user_id'    => auth()->id(),
                    'posting_key'      => 'issuance:' . $issuance->issuance_id
                                          . ':line:' . $detail->issuance_detail_id
                                          . ':batch:' . $touchedBatchId . ':out',
                ]);
            }

            $this->logScanEvent(
                $issuance->issuance_id,
                $product->product_id,
                $locationId,
                $scannedCode,
                'applied',
                $quantityInPieces
            );

            $this->recalculateTotals($issuance->issuance_id);

            return response()->json([
                'success'            => true,
                'action'             => 'applied',
                'product_type'       => 'installation_material',
                'serial_number'      => $scannedCode,
                'product_id'         => $product->product_id,
                'product_name'       => $product->product_name,
                'product_code'       => $product->product_code,
                'unit_name'          => $baseUnitName,
                'unit_abbreviation'  => $baseUnitAbbr,
                'base_unit_name'     => $baseUnitName,
                'base_unit_abbr'     => $baseUnitAbbr,
                'pieces_per_package' => $piecesPerPackage,
                'package_unit'       => $product->installationMaterialDetail?->package_unit
                                     ?? $baseUnitName,
                'package_price'      => $packagePrice,
                'price_per_piece'    => $pricePerPiece,
                'quantity_issued'    => $requestedQty,
                'quantity_unit'      => $quantityUnit,
                'quantity_in_pieces' => $quantityInPieces,
                'subtotal'           => $lineSubtotal,
                'detail_id'          => $detail->issuance_detail_id,
                'stock_remaining'    => $inv->quantity_on_hand,
                'pricing_note'       => "₱{$packagePrice} per "
                    . ($product->installationMaterialDetail?->package_unit ?? $baseUnitName)
                    . " ÷ {$piecesPerPackage} {$baseUnitName}s"
                    . " = ₱{$pricePerPiece} per {$baseUnitName}",
                'dropdown_units'     => $product->getIssuableDropdownUnits(),
            ]);
        });
    }

    // =========================================================
    // METHOD 3: removeItem()
    // DELETE /api/issuance-scan/item/{detail_id}
    // Undo a scan before completing the session.
    // =========================================================
    public function removeItem(int $detailId): JsonResponse
    {
        $detail = IssuanceDetail::find($detailId);
        if (! $detail) {
            return $this->notFound('Item not found.');
        }

        $issuance = Issuance::find($detail->issuance_id);
        if ($issuance?->status_id !== self::STATUS_ISSUANCE_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot remove item from a completed issuance.',
            ], 422);
        }

        return DB::transaction(function () use ($detail, $issuance) {
            $this->reverseIssuedDetail($detail, $issuance);
            $this->recalculateTotals($issuance->issuance_id);

            return response()->json([
                'success' => true,
                'message' => 'Item removed from issuance.',
            ]);
        });
    }

    // =========================================================
    // METHOD 4: getSessionStatus()
    // GET /api/issuance-scan/session/{issuance_id}
    // Returns the current state of an in-progress session.
    // =========================================================
    public function getSessionStatus(int $issuanceId): JsonResponse
    {
        $issuance = Issuance::with([
            'details.product.applianceDetail.brand',
            'details.product.installationMaterialDetail.unit',
            'customer', 'location', 'issuedByUser', 'status',
        ])->find($issuanceId);

        if (! $issuance) {
            return $this->notFound('Session not found.');
        }

        return response()->json([
            'success'  => true,
            'issuance' => $this->formatIssuance($issuance),
        ]);
    }

    // =========================================================
    // METHOD 5: completeIssuance()
    // POST /api/issuance-scan/complete/{issuance_id}
    // Finalizes the session, creates warranty records and DR.
    // =========================================================
    public function completeIssuance(int $issuanceId): JsonResponse
    {
        $issuance = Issuance::with([
            'details.product.applianceDetail',
            'customer', 'location',
        ])->find($issuanceId);

        if (! $issuance) {
            return $this->notFound('Issuance not found.');
        }
        if ($issuance->status_id !== self::STATUS_ISSUANCE_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Issuance already completed or voided.',
            ], 422);
        }
        if ($issuance->details->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot complete an empty issuance. Scan at least one item.',
            ], 422);
        }

        return DB::transaction(function () use ($issuance) {
            $saleDate = $issuance->issuance_date?->toDateString() ?? now()->toDateString();

            foreach ($issuance->details as $detail) {
                $product = $detail->product;
                if (! $product) continue;

                if (! $product->relationLoaded('applianceDetail')) {
                    $product->load('applianceDetail.acType');
                }

                if ($product->isSerialized() && $detail->serial_number) {
                    $appliance = $product->applianceDetail;
                    $warrantyMonths = (int) ($appliance?->warranty_period_months ?? 0);
                    if ($warrantyMonths <= 0 && (int) ($appliance?->warranty_value ?? 0) > 0) {
                        $warrantyMonths = $appliance->computeWarrantyMonths();
                    }

                    if ($warrantyMonths > 0) {
                        $warrantyEnd = \Illuminate\Support\Carbon::parse($saleDate)
                            ->addMonths($warrantyMonths)
                            ->toDateString();

                        WarrantyRecord::updateOrCreate([
                            'issuance_detail_id'      => $detail->issuance_detail_id,
                        ], [
                            'issuance_id'             => $issuance->issuance_id,
                            'product_id'              => $detail->product_id,
                            'serial_number'           => $detail->serial_number,
                            'customer_id'             => $issuance->customer_id,
                            'sale_date'               => $saleDate,
                            'warranty_start_date'     => $saleDate,
                            'warranty_end_date'       => $warrantyEnd,
                            'warranty_period_months'  => $warrantyMonths,
                            'warranty_duration_label' => $this->buildWarrantyLabel($warrantyMonths),
                            'status_id'               => self::STATUS_WARRANTY_ACTIVE,
                        ]);
                    }
                }
            }

            try {
                DeliveryReceipt::create([
                    'dr_number'    => $issuance->dr_number,
                    'issuance_id'  => $issuance->issuance_id,
                    'sales_id'     => null,
                    'customer_id'  => $issuance->customer_id,
                    'location_id'  => $issuance->location_id,
                    'dr_date'      => $saleDate,
                    'total_amount' => $issuance->total_amount,
                    'issued_by'    => $issuance->issued_by,
                    'status_id'    => self::STATUS_ISSUANCE_COMPLETED,
                ]);
            } catch (\Throwable $e) {
                report($e);
            }

            $issuance->update(['status_id' => self::STATUS_ISSUANCE_COMPLETED]);

            try {
                AuditTrail::create([
                    'user_id'    => Auth::id(),
                    'action'     => 'issuance_completed',
                    'table_name' => 'issuances',
                    'record_id'  => $issuance->issuance_id,
                    'old_values' => null,
                    'new_values' => [
                        'dr_number'     => $issuance->dr_number,
                        'customer'      => $issuance->customer?->customer_name,
                        'issuance_type' => $issuance->issuance_type,
                        'total_amount'  => $issuance->total_amount,
                        'items_issued'  => $issuance->details->count(),
                        'location'      => $issuance->location?->location_name,
                    ],
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }

            $fresh = Issuance::with([
                'details.product.applianceDetail.brand',
                'details.product.installationMaterialDetail.unit',
                'details.warrantyRecord.status',
                'customer', 'location', 'issuedByUser', 'status',
            ])->find($issuance->issuance_id);

            return response()->json([
                'success'  => true,
                'message'  => 'Issuance completed. Delivery receipt is ready.',
                'issuance' => $this->formatIssuance($fresh),
            ]);
        });
    }

    // =========================================================
    // POST /api/issuance-scan/{issuance_id}/revert-to-draft
    // Un-pick an abandoned picking-phase order back to a clean Draft:
    // reverse every scanned row, release the reservations, zero the
    // shortages, set Draft. Pending-only; idempotent on a Draft.
    // =========================================================
    public function revertToDraft(int $issuanceId): JsonResponse
    {
        return DB::transaction(function () use ($issuanceId) {
            $issuance = Issuance::lockForUpdate()->find($issuanceId);

            if (! $issuance) {
                return $this->notFound('Issuance not found.');
            }

            if ((int) $issuance->status_id === IssuanceStatus::draft()) {
                return response()->json(['success' => true, 'message' => 'Already a draft.', 'issuance_id' => (int) $issuance->issuance_id]);
            }

            if ((int) $issuance->status_id !== self::STATUS_ISSUANCE_PENDING) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only a picking-phase (pending) issue-out can be reverted to draft.',
                ], 422);
            }

            $issued = IssuanceDetail::where('issuance_id', $issuance->issuance_id)
                ->where('quantity_issued', '>', 0)
                ->get();
            foreach ($issued as $detail) {
                $this->reverseIssuedDetail($detail, $issuance);
            }

            app(\App\Services\ReservationService::class)
                ->releaseFor('issuance', (int) $issuance->issuance_id);

            IssuanceDetail::where('issuance_id', $issuance->issuance_id)
                ->update(['quantity_short' => 0]);

            $this->recalculateTotals($issuance->issuance_id);
            $issuance->update(['status_id' => IssuanceStatus::draft()]);

            return response()->json([
                'success'     => true,
                'message'     => 'Order reverted to draft.',
                'issuance_id' => (int) $issuance->issuance_id,
            ]);
        });
    }

    public function downloadDeliveryReceiptPdf(Request $request, int $issuanceId)
    {
        $issuance = Issuance::with([
            'details.product.applianceDetail.brand',
            'details.product.installationMaterialDetail.unit',
            'details.warrantyRecord.status',
            'customer', 'location', 'issuedByUser', 'status',
        ])->find($issuanceId);

        if (! $issuance) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $items = $issuance->details->where('quantity_issued', '>', 0)->values();
        $paperSize = strtolower((string) $request->query('paper_size', \App\Models\SystemSetting::pdfPaperSize()));
        if (! in_array($paperSize, ['a4', 'letter', 'legal', 'a5'], true)) {
            $paperSize = \App\Models\SystemSetting::pdfPaperSize();
        }
        $landscapeWidths = ['a4' => 841.89, 'letter' => 792, 'legal' => 1008, 'a5' => 595.28];
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('issuance.delivery-receipt', [
            'issuance' => $issuance,
            'items' => $items,
            'printable_width_pt' => $landscapeWidths[$paperSize] - 72,
            'company' => [
                'name' => \App\Models\SystemSetting::companyName(),
                'address' => \App\Models\SystemSetting::companyAddress(),
                'email' => \App\Models\SystemSetting::companyEmail(),
                'phone' => \App\Models\SystemSetting::companyPhone(),
            ],
        ])->setPaper($paperSize, 'landscape');

        $filename = "DR-{$issuance->dr_number}.pdf";
        return request()->boolean('preview')
            ? $pdf->stream($filename)
            : $pdf->download($filename);
    }

    // =========================================================
    // PRIVATE HELPERS
    // =========================================================

    private function buildWarrantyLabel(int $months): string
    {
        if ($months <= 0) return 'No Warranty';
        if ($months % 12 === 0 && $months >= 12) {
            $years = $months / 12;
            return $years === 1 ? '1 Year' : "{$years} Years";
        }
        return $months === 1 ? '1 Month' : "{$months} Months";
    }

    private function logScanEvent(
        int $issuanceId,
        int $productId,
        int $locationId,
        ?string $barcode,
        string $action,
        int $quantityDelta
    ): void {
        try {
            \App\Models\InventoryScanEvent::create([
                'user_id'          => Auth::id(),
                'transaction_type' => 'issuance',
                'action'           => $action,
                'barcode'          => $barcode ?? '',
                'product_id'       => $productId,
                'location_id'      => $locationId,
                'quantity_delta'   => -$quantityDelta,
                'serial_number'    => $barcode,
                'meta'             => ['issuance_id' => $issuanceId],
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Reverse one issued detail row: return its pieces to inventory + FIFO batch,
     * recreate the serial for a serialized appliance, then delete the row. Caller
     * owns the surrounding transaction and any status change. Shared by removeItem()
     * (undo one scan) and revertToDraft() (un-pick a whole order).
     */
    private function reverseIssuedDetail(IssuanceDetail $detail, Issuance $issuance): void
    {
        $product = Product::with(['applianceDetail.acType', 'installationMaterialDetail'])
                   ->find($detail->product_id);

        if ($product) {
            $inv = Inventory::where('product_id', $detail->product_id)
                ->where('location_id', $issuance->location_id)
                ->lockForUpdate()->first();

            if ($inv) {
                $pcs = $product->isSerialized() ? 0 : $product->getPiecesPerPackage();
                $inv->setPieces((int) $inv->quantity_on_hand + (int) $detail->quantity_in_pieces, $pcs);
                $inv->save();

                app(InventoryBatchService::class)->restoreFifo(
                    $detail->product_id,
                    $issuance->location_id,
                    $detail->quantity_in_pieces,
                    $detail->receiving_id
                );
            }

            if ($product->isSerialized() && $detail->serial_number) {
                ItemSerial::create([
                    'product_id'    => $detail->product_id,
                    'location_id'   => $issuance->location_id,
                    'receiving_id'  => $detail->receiving_id,
                    'serial_number' => $detail->serial_number,
                    'serial_type'   => 'received',
                    'status_id'     => self::STATUS_SERIAL_IN_STOCK,
                    'scanned_by'    => Auth::id(),
                ]);
            }
        }

        $detail->delete();
    }

    private function recalculateTotals(int $issuanceId): void
    {
        $issuance = Issuance::find($issuanceId);
        if (! $issuance) return;

        $subtotal = (float) IssuanceDetail::where('issuance_id', $issuanceId)
            // Draft/order rows describe demand and retain their quoted subtotal.
            // Only applied scan rows represent the POS sale; summing both would
            // double-charge the receipt after the first pick.
            ->where('quantity_issued', '>', 0)
            ->sum('subtotal');

        $discountAmount = round($subtotal * ($issuance->discount_percent / 100), 2);
        $totalAmount    = round($subtotal - $discountAmount, 2);

        $issuance->update([
            'subtotal'        => $subtotal,
            'discount_amount' => $discountAmount,
            'total_amount'    => $totalAmount,
        ]);
    }

    private function formatIssuance(Issuance $issuance): array
    {
        $issuedBy = $issuance->issuedByUser
            ? trim(($issuance->issuedByUser->first_name ?? '')
                  . ' ' . ($issuance->issuedByUser->last_name ?? ''))
            : null;

        return [
            'issuance_id'      => $issuance->issuance_id,
            'dr_number'        => $issuance->dr_number,
            'external_reference' => $issuance->external_reference,
            'issuance_date'    => $issuance->issuance_date,
            'issuance_type'    => $issuance->issuance_type,
            'status_name'      => $issuance->status?->status_name,
            'location_name'    => $issuance->location?->location_name,
            'issued_by_name'   => $issuedBy,
            'discount_type'    => $issuance->discount_type,
            'discount_percent' => $issuance->discount_percent,
            'subtotal'         => $issuance->subtotal,
            'discount_amount'  => $issuance->discount_amount,
            'total_amount'     => $issuance->total_amount,
            'notes'            => $issuance->notes,
            'customer' => $issuance->customer ? [
                'customer_id'    => $issuance->customer->customer_id,
                'customer_name'  => $issuance->customer->customer_name,
                'customer_type'  => $issuance->customer->customer_type,
                'company_name'   => $issuance->customer->company_name,
                'contact_number' => $issuance->customer->contact_number,
                'email'          => $issuance->customer->email,
                'address'        => $issuance->customer->address,
            ] : null,
            'items' => $issuance->details->map(function ($d) {
                $p  = $d->product;
                $ad = $p?->applianceDetail;
                $cd = $p?->installationMaterialDetail;
                $wr = $d->warrantyRecord ?? null;
                return [
                    'detail_id'          => $d->issuance_detail_id,
                    'picked_at'           => $d->created_at?->toISOString(),
                    'product_id'         => $d->product_id,
                    'product_name'       => $p?->product_name,
                    'product_code'       => $p?->product_code,
                    'model_code'         => $ad?->model_code,
                    'product_type'       => $p?->product_type,
                    'brand_name'         => $ad?->brand?->brand_name,
                    // See the pick payload above — the already-picked list has
                    // to name the half too, or a reviewer cannot tell whether
                    // the right carton was taken.
                    'unit_role'          => $ad?->unit_role,
                    'ac_type_name'       => $ad?->acType?->ac_type_name,
                    'capacity_rating'    => $ad?->capacity_rating,
                    'variant'            => $ad?->variant,
                    'unit_name'          => $cd?->unit?->unit_name,
                    'pieces_per_package' => $cd?->pieces_per_package,
                    'package_unit'       => $cd?->package_unit,
                    'serial_number'      => $d->serial_number,
                    'unit_price'         => $d->unit_price,
                    'quantity_issued'    => $d->quantity_issued,
                    'quantity_unit'      => $d->quantity_unit,
                    'quantity_in_pieces' => $d->quantity_in_pieces,
                    'stock_before'       => $d->stock_before,
                    'quantity_deducted'  => $d->quantity_deducted,
                    'stock_after'        => $d->stock_after,
                    'batch_id'           => $d->batch_id,
                    'zone_label'         => $d->zone_label,
                    'price_per_piece'    => $d->unit_price_per_piece,
                    'subtotal'           => $d->subtotal,
                    'warranty' => $wr ? [
                        'warranty_id'             => $wr->warranty_id,
                        'warranty_start_date'     => $wr->warranty_start_date?->format('Y-m-d'),
                        'warranty_end_date'       => $wr->warranty_end_date?->format('Y-m-d'),
                        'warranty_period_months'  => $wr->warranty_period_months,
                        'warranty_duration_label' => $wr->warranty_duration_label,
                        'days_remaining'          => $wr->days_remaining,
                        'status_name'             => $wr->status?->status_name,
                    ] : null,
                ];
            })->values()->all(),
        ];
    }
}
