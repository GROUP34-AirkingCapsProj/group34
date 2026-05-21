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
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IssuanceScanController extends Controller
{
    use ApiResponse;

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
            'issuance_type'    => ['required', Rule::in(['business', 'individual', 'warranty'])],
            'discount_type'    => ['nullable', Rule::in(['none', 'business', 'senior', 'custom'])],
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'location_id'      => 'required|integer|exists:locations,location_id',
            'notes'            => 'nullable|string|max:1000',
        ]);

        $userId = Auth::id();

        $discountType    = $validated['discount_type'] ?? 'none';
        $discountPercent = 0;

        if ($validated['issuance_type'] === 'business') {
            $discountPercent = (float) ($validated['discount_percent'] ?? 0);
            $discountType    = $validated['discount_type'] ?? 'business';
        } elseif ($validated['issuance_type'] === 'warranty') {
            $discountPercent = 100;
            $discountType    = 'none';
        }

        $drNumber = 'DR-' . now()->format('Ymd') . '-'
                   . strtoupper(substr(uniqid(), -5));

        $issuance = Issuance::create([
            'location_id'      => $validated['location_id'],
            'customer_id'      => $validated['customer_id'],
            'issuance_date'    => now()->toDateString(),
            'issuance_type'    => $validated['issuance_type'],
            'dr_number'        => $drNumber,
            'discount_type'    => $discountType,
            'discount_percent' => $discountPercent,
            'subtotal'         => 0,
            'discount_amount'  => 0,
            'total_amount'     => 0,
            'notes'            => $validated['notes'] ?? null,
            'issued_by'        => $userId,
            'status_id'        => self::STATUS_ISSUANCE_PENDING,
        ]);

        $customer = Customer::find($validated['customer_id']);

        return response()->json([
            'success'          => true,
            'message'          => 'Issuance session started.',
            'issuance_id'      => $issuance->issuance_id,
            'dr_number'        => $drNumber,
            'issuance_type'    => $issuance->issuance_type,
            'discount_type'    => $discountType,
            'discount_percent' => $discountPercent,
            'customer'         => [
                'customer_id'    => $customer->customer_id,
                'customer_name'  => $customer->customer_name,
                'customer_type'  => $customer->customer_type,
                'company_name'   => $customer->company_name,
                'contact_number' => $customer->contact_number,
                'email'          => $customer->email,
                'address'        => $customer->address,
            ],
        ], 201);
    }

    // =========================================================
    // METHOD 2: scanItem()
    // POST /api/issuance-scan/scan
    // Scans a barcode to issue one item from inventory.
    // =========================================================
    public function scanItem(Request $request): JsonResponse
    {
        // Normalize quantity_unit to lowercase before validation so unit names
        // from the units table (e.g. "Meter", "Roll") pass the whitelist check.
        if ($request->has('quantity_unit') && is_string($request->quantity_unit)) {
            $request->merge(['quantity_unit' => strtolower($request->quantity_unit)]);
        }

        $validated = $request->validate([
            'issuance_id'   => 'required|integer|exists:issuances,issuance_id',
            'barcode'       => 'required|string|max:191',
            'quantity'      => 'nullable|numeric|min:0.001',
            'quantity_unit' => ['nullable', Rule::in([
                'unit', 'piece', 'meter', 'roll', 'kg', 'liter',
                'set', 'box', 'quarter_roll', 'half_roll',
            ])],
            'location_id'   => 'required|integer|exists:locations,location_id',
        ]);

        $issuance = Issuance::find($validated['issuance_id']);
        if (! $issuance || $issuance->status_id !== self::STATUS_ISSUANCE_PENDING) {
            return response()->json([
                'success' => false,
                'action'  => 'rejected',
                'reason'  => 'Issuance session not found or already completed.',
            ]);
        }

        $raw        = trim($validated['barcode']);
        $lower      = strtolower($raw);
        $locationId = (int) $validated['location_id'];

        // Pass 1: serial number lookup (appliances)
        $product      = null;
        $serialRecord = null;

        $serial = ItemSerial::where('location_id', $locationId)
            ->whereRaw('LOWER(TRIM(serial_number)) = ?', [$lower])
            ->where('status_id', self::STATUS_SERIAL_IN_STOCK)
            ->first();

        if ($serial) {
            $product = Product::with([
                'applianceDetail.brand',
                'applianceDetail.modelLookup',
            ])->find($serial->product_id);
            $serialRecord = $serial;
        }

        // Pass 2: product barcode lookup (consumables)
        if (! $product) {
            $product = Product::query()
                ->where(function ($q) use ($lower) {
                    $q->whereRaw("LOWER(TRIM(COALESCE(barcode,''))) = ?", [$lower])
                      ->orWhereRaw("LOWER(TRIM(COALESCE(product_code,''))) = ?", [$lower]);
                })
                ->with(['consumableDetail.unit'])
                ->first();
        }

        if (! $product) {
            return response()->json([
                'success' => false,
                'action'  => 'rejected',
                'reason'  => 'Barcode not found. Ensure the item is stocked at this location.',
            ]);
        }

        if (! $product->relationLoaded('applianceDetail')) {
            $product->load('applianceDetail', 'consumableDetail');
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

            $alreadyAdded = IssuanceDetail::where('issuance_id', $issuance->issuance_id)
                ->where('serial_number', $raw)
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
                $issuance, $product, $serialRecord, $raw,
                $unitPrice, $lineSubtotal, $locationId
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

                $inv->quantity_on_hand   = max(0, $inv->quantity_on_hand - 1);
                $inv->available_quantity = max(0, $inv->available_quantity - 1);
                $inv->save();

                $serialRecord->delete();

                $detail = IssuanceDetail::create([
                    'issuance_id'          => $issuance->issuance_id,
                    'product_id'           => $product->product_id,
                    'serial_number'        => $raw,
                    'unit_price'           => $unitPrice,
                    'quantity_issued'      => 1,
                    'quantity_unit'        => 'unit',
                    'quantity_in_pieces'   => 1,
                    'unit_price_per_piece' => $unitPrice,
                    'subtotal'             => $unitPrice,
                    'condition_issued'     => 'Good',
                ]);

                $this->logScanEvent(
                    $issuance->issuance_id,
                    $product->product_id,
                    $locationId,
                    $raw,
                    'accepted',
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
                    'brand_name'      => $product->applianceDetail?->brand?->brand_name,
                    'capacity_rating' => $product->applianceDetail?->capacity_rating,
                    'variant'         => $product->applianceDetail?->variant,
                    'serial_number'   => $raw,
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

        // ── CONSUMABLE BRANCH ─────────────────────────────────
        $requestedQty = (float) ($validated['quantity'] ?? 1);
        $quantityUnit = $validated['quantity_unit']
                       ?? ($product->consumableDetail?->unit?->unit_abbreviation ?? 'piece');
        $quantityInPieces = (int) ceil($requestedQty);

        $packagePrice  = $product->unit_price;
        $pricePerPiece = $piecesPerPackage > 0
            ? round($packagePrice / $piecesPerPackage, 4)
            : $packagePrice;
        $lineSubtotal  = round($pricePerPiece * $requestedQty, 2);

        return DB::transaction(function () use (
            $issuance, $product, $requestedQty, $quantityUnit,
            $quantityInPieces, $packagePrice, $pricePerPiece,
            $lineSubtotal, $locationId, $piecesPerPackage
        ) {
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

            $inv->quantity_on_hand   = max(0, $inv->quantity_on_hand - $quantityInPieces);
            $inv->available_quantity = max(0, $inv->available_quantity - $quantityInPieces);
            if ($piecesPerPackage > 0) {
                $inv->packages_on_hand = (int) floor($inv->quantity_on_hand / $piecesPerPackage);
            }
            $inv->save();

            $detail = IssuanceDetail::create([
                'issuance_id'          => $issuance->issuance_id,
                'product_id'           => $product->product_id,
                'serial_number'        => null,
                'unit_price'           => $packagePrice,
                'quantity_issued'      => (int) $requestedQty,
                'quantity_unit'        => $quantityUnit,
                'quantity_in_pieces'   => $quantityInPieces,
                'unit_price_per_piece' => $pricePerPiece,
                'subtotal'             => $lineSubtotal,
                'condition_issued'     => 'Good',
            ]);

            $this->logScanEvent(
                $issuance->issuance_id,
                $product->product_id,
                $locationId,
                null,
                'accepted',
                $quantityInPieces
            );

            $this->recalculateTotals($issuance->issuance_id);

            return response()->json([
                'success'            => true,
                'action'             => 'applied',
                'product_type'       => 'consumable',
                'product_id'         => $product->product_id,
                'product_name'       => $product->product_name,
                'product_code'       => $product->product_code,
                'unit_name'          => $product->consumableDetail?->unit?->unit_name,
                'unit_abbreviation'  => $product->consumableDetail?->unit?->unit_abbreviation,
                'pieces_per_package' => $piecesPerPackage,
                'package_unit'       => $product->consumableDetail?->package_unit,
                'package_price'      => $packagePrice,
                'price_per_piece'    => $pricePerPiece,
                'quantity_issued'    => $requestedQty,
                'quantity_unit'      => $quantityUnit,
                'quantity_in_pieces' => $quantityInPieces,
                'subtotal'           => $lineSubtotal,
                'detail_id'          => $detail->issuance_detail_id,
                'stock_remaining'    => $inv->quantity_on_hand,
                'pricing_note'       => "₱{$packagePrice} per "
                    . ($product->consumableDetail?->package_unit
                        ?? $product->consumableDetail?->unit?->unit_name
                        ?? 'package')
                    . " / {$piecesPerPackage} = ₱{$pricePerPiece} per {$quantityUnit}",
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
            $product = Product::with(['applianceDetail', 'consumableDetail'])
                       ->find($detail->product_id);

            if ($product) {
                $inv = Inventory::where('product_id', $detail->product_id)
                    ->where('location_id', $issuance->location_id)
                    ->lockForUpdate()->first();

                if ($inv) {
                    $inv->quantity_on_hand   += $detail->quantity_in_pieces;
                    $inv->available_quantity += $detail->quantity_in_pieces;
                    if (! $product->isSerialized()) {
                        $pcs = $product->getPiecesPerPackage();
                        if ($pcs > 0) {
                            $inv->packages_on_hand = (int) floor($inv->quantity_on_hand / $pcs);
                        }
                    }
                    $inv->save();
                }

                if ($product->isSerialized() && $detail->serial_number) {
                    ItemSerial::create([
                        'product_id'    => $detail->product_id,
                        'location_id'   => $issuance->location_id,
                        'serial_number' => $detail->serial_number,
                        'serial_type'   => 'received',
                        'status_id'     => self::STATUS_SERIAL_IN_STOCK,
                        'scanned_by'    => Auth::id(),
                    ]);
                }
            }

            $detail->delete();
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
            'details.product.consumableDetail.unit',
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
            $saleDate = now()->toDateString();

            foreach ($issuance->details as $detail) {
                $product = $detail->product;
                if (! $product) continue;

                if (! $product->relationLoaded('applianceDetail')) {
                    $product->load('applianceDetail');
                }

                if ($product->isSerialized() && $detail->serial_number) {
                    $warrantyMonths = (int) ($product->applianceDetail?->warranty_period_months ?? 0);

                    if ($warrantyMonths > 0) {
                        $warrantyEnd = now()->addMonths($warrantyMonths)->toDateString();

                        WarrantyRecord::create([
                            'issuance_id'             => $issuance->issuance_id,
                            'issuance_detail_id'      => $detail->issuance_detail_id,
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
                'details.product.consumableDetail.unit',
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
    // METHOD 6: downloadDeliveryReceipt() — CSV export
    // GET /api/issuance-scan/delivery-receipt/{issuance_id}/csv
    // =========================================================
    public function downloadDeliveryReceipt(int $issuanceId)
    {
        $issuance = Issuance::with([
            'details.product.applianceDetail.brand',
            'details.product.applianceDetail.modelLookup',
            'details.product.consumableDetail.unit',
            'details.warrantyRecord',
            'customer', 'location', 'issuedByUser', 'status',
        ])->find($issuanceId);

        if (! $issuance) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $filename = "DR-{$issuance->dr_number}.csv";
        $headers  = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($issuance) {
            $handle = fopen('php://output', 'w');

            $issuedBy = $issuance->issuedByUser
                ? trim(($issuance->issuedByUser->first_name ?? '')
                      . ' ' . ($issuance->issuedByUser->last_name ?? ''))
                : '—';

            fputcsv($handle, ['DELIVERY RECEIPT']);
            fputcsv($handle, ['DR Number',     $issuance->dr_number]);
            fputcsv($handle, ['Date',          $issuance->issuance_date]);
            fputcsv($handle, ['Issuance Type', ucfirst($issuance->issuance_type ?? '')]);
            fputcsv($handle, ['Issued By',     $issuedBy]);
            fputcsv($handle, ['Location',      $issuance->location?->location_name ?? '—']);

            fputcsv($handle, []);
            fputcsv($handle, ['CUSTOMER INFORMATION']);
            fputcsv($handle, ['Customer Name',  $issuance->customer?->customer_name ?? '—']);
            fputcsv($handle, ['Type',           ucfirst($issuance->customer?->customer_type ?? '—')]);
            fputcsv($handle, ['Company',        $issuance->customer?->company_name ?? '—']);
            fputcsv($handle, ['Contact Number', $issuance->customer?->contact_number ?? '—']);
            fputcsv($handle, ['Email',          $issuance->customer?->email ?? '—']);
            fputcsv($handle, ['Address',        $issuance->customer?->address ?? '—']);

            fputcsv($handle, []);
            fputcsv($handle, ['ITEMS ISSUED']);
            fputcsv($handle, [
                '#', 'Product Code', 'Product Name', 'Type',
                'Brand / Unit', 'Capacity / Package',
                'Serial Number', 'Quantity', 'Unit',
                'Unit Price', 'Price Per Unit', 'Subtotal',
                'Warranty Start', 'Warranty End', 'Warranty (Months)',
            ]);

            foreach ($issuance->details as $i => $d) {
                $p  = $d->product;
                $ad = $p?->applianceDetail;
                $cd = $p?->consumableDetail;
                $wr = $d->warrantyRecord;

                fputcsv($handle, [
                    $i + 1,
                    $p?->product_code ?? '',
                    $p?->product_name ?? '',
                    $p ? ucfirst($p->product_type) : '',
                    $ad?->brand?->brand_name ?? $cd?->unit?->unit_name ?? '',
                    $ad?->capacity_rating    ?? $cd?->package_unit     ?? '',
                    $d->serial_number        ?? '',
                    $d->quantity_issued,
                    $d->quantity_unit         ?? '',
                    number_format($d->unit_price, 2),
                    number_format($d->unit_price_per_piece, 4),
                    number_format($d->subtotal, 2),
                    $wr?->warranty_start_date ?? '',
                    $wr?->warranty_end_date   ?? '',
                    $wr?->warranty_period_months ?? '',
                ]);
            }

            fputcsv($handle, []);
            fputcsv($handle, ['TOTALS']);
            fputcsv($handle, ['Subtotal (before discount)', number_format($issuance->subtotal, 2)]);
            fputcsv($handle, ['Discount Type',   $issuance->discount_type  ?? 'None']);
            fputcsv($handle, ['Discount %',      $issuance->discount_percent . '%']);
            fputcsv($handle, ['Discount Amount', number_format($issuance->discount_amount, 2)]);
            fputcsv($handle, ['TOTAL AMOUNT',    number_format($issuance->total_amount, 2)]);

            if ($issuance->notes) {
                fputcsv($handle, []);
                fputcsv($handle, ['Notes', $issuance->notes]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
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

    private function recalculateTotals(int $issuanceId): void
    {
        $issuance = Issuance::find($issuanceId);
        if (! $issuance) return;

        $subtotal = (float) IssuanceDetail::where('issuance_id', $issuanceId)
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
                $cd = $p?->consumableDetail;
                $wr = $d->warrantyRecord ?? null;
                return [
                    'detail_id'          => $d->issuance_detail_id,
                    'product_id'         => $d->product_id,
                    'product_name'       => $p?->product_name,
                    'product_code'       => $p?->product_code,
                    'product_type'       => $p?->product_type,
                    'brand_name'         => $ad?->brand?->brand_name,
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
