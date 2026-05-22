<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BarcodeScan;
use App\Models\ItemSerial;
use App\Models\Product;
use App\Traits\ApiResponse;
use App\Traits\NormalizesBarcode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BarcodeScanController extends Controller
{
    use ApiResponse, NormalizesBarcode;

    /**
     * POST /barcode/scan — Receive PO smart lookup (FOUND | NOT_FOUND | PENDING_CONSUMABLE).
     */
    public function scanLookup(Request $request)
    {
        try {
            $validated = $request->validate([
                'barcode' => 'required|string|max:191',
            ]);
            $raw = trim($validated['barcode']);
            if ($raw === '') {
                return $this->success(['status' => 'NOT_FOUND'], 'No barcode');
            }

            $candidates = $this->barcodeLookupCandidates($raw);

            if (Schema::hasTable('pending_products')) {
                foreach ($candidates as $c) {
                    $norm = strtolower($c);
                    $pending = DB::table('pending_products')
                        ->where('status', 'pending')
                        ->where('registration_kind', 'consumable')
                        ->whereRaw('LOWER(TRIM(barcode)) = ?', [$norm])
                        ->first();
                    if ($pending) {
                        return $this->success([
                            'status'               => 'PENDING_CONSUMABLE',
                            'pending_product_id'   => $pending->pending_product_id ?? null,
                        ], 'Pending consumable registration');
                    }
                }
            }

            $product = $this->findProductByBarcodeCandidates($candidates);

            // Fallback: search item_serial.serial_number to identify the appliance product
            if (! $product) {
                foreach ($candidates as $c) {
                    $serial = ItemSerial::query()
                        ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower(trim($c))])
                        ->first();
                    if ($serial && $serial->product_id) {
                        $product = Product::find($serial->product_id);
                        if ($product) break;
                    }
                }
            }

            // Also search by receiving barcode (receiving_details.scanned_barcode) so
            // consumables whose products.barcode is null are still found by the code
            // that was scanned during PO receiving.
            if (! $product) {
                foreach ($candidates as $c) {
                    $norm = strtolower(trim($c));
                    if ($norm === '') continue;
                    $detail = DB::table('receiving_details')
                        ->whereRaw("LOWER(TRIM(COALESCE(scanned_barcode,''))) = ?", [$norm])
                        ->whereNotNull('product_id')
                        ->select('product_id')
                        ->first();
                    if ($detail) {
                        $product = Product::find($detail->product_id);
                        break;
                    }
                }
            }

            if ($product) {
                $product->load([
                    'category',
                    'status',
                    'applianceDetail.brand',
                    'applianceDetail.modelLookup',
                    'consumableDetail.unit',
                    'issuableUnits.unit',
                ]);

                $piecesPerPkg  = $product->getPiecesPerPackage();
                $packagePrice  = (float) $product->unit_price;
                $pricePerPiece = $piecesPerPkg > 0
                    ? round($packagePrice / $piecesPerPkg, 4)
                    : $packagePrice;

                return $this->success([
                    'status'             => 'FOUND',
                    'product'            => $product,
                    'product_type'       => $product->product_type,
                    'is_serialized'      => $product->isSerialized(),
                    'pieces_per_package' => $piecesPerPkg,
                    'brand_name'         => $product->applianceDetail?->brand?->brand_name       ?? null,
                    'capacity'           => $product->applianceDetail?->capacity_rating           ?? null,
                    'variant'            => $product->applianceDetail?->variant                   ?? null,
                    'unit_name'          => $product->consumableDetail?->unit?->unit_name         ?? null,
                    'unit_abbr'          => $product->consumableDetail?->unit?->unit_abbreviation ?? null,
                    // Fields for the issuance quantity panel
                    'base_unit_name'     => $product->consumableDetail?->unit?->unit_name         ?? null,
                    'base_unit_abbr'     => $product->consumableDetail?->unit?->unit_abbreviation ?? null,
                    'package_unit'       => $product->consumableDetail?->package_unit             ?? null,
                    'package_price'      => $packagePrice,
                    'price_per_piece'    => $pricePerPiece,
                    'quick_actions'      => $product->buildConsumableQuickActions(),
                    'dropdown_units'     => $product->getIssuableDropdownUnits(),
                    'has_issuable_units' => $product->issuableUnits->isNotEmpty(),
                ], 'Product found');
            }

            return $this->success(['status' => 'NOT_FOUND'], 'Barcode not registered');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Scan lookup failed: '.$e->getMessage(), 500);
        }
    }

    /**
     * @return list<string>
     */

    public function index(Request $request)
    {
        try {
            $query = BarcodeScan::with(['user', 'product']);

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }
            if ($request->filled('product_id')) {
                $query->where('product_id', $request->product_id);
            }
            if ($request->filled('scan_mode')) {
                $query->where('scan_mode', $request->scan_mode);
            }

            $perPage = (int) $request->get('per_page', 50);
            $perPage = $perPage > 0 ? min($perPage, 200) : 50;

            return $this->paginated(
                $query->orderByDesc('scanned_at')->orderByDesc('id')->paginate($perPage),
                'Barcode scans retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve barcode scans: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'barcode'     => 'required|string|max:191',
                'product_id'  => 'required|integer|exists:products,product_id',
                'scan_mode'   => 'required|string|max:64',
                'scanned_at'  => 'nullable|date',
            ]);

            $foundProduct = $this->findProductByBarcodeCandidates(
                $this->barcodeLookupCandidates(trim($validated['barcode']))
            );
            if (! $foundProduct || (int) $foundProduct->product_id !== (int) $validated['product_id']) {
                throw ValidationException::withMessages([
                    'barcode' => ['Barcode does not match the selected product.'],
                ]);
            }

            $scan = BarcodeScan::create([
                'user_id'     => $request->user()->user_id,
                'barcode'     => $validated['barcode'],
                'product_id'  => $validated['product_id'],
                'scan_mode'   => $validated['scan_mode'],
                'scanned_at'  => $validated['scanned_at'] ?? now(),
            ]);

            return $this->success(
                $scan->load([
                    'user',
                    'product.category',
                    'product.status',
                    'product.applianceDetail.brand',
                    'product.consumableDetail.unit',
                ]),
                'Barcode scan recorded successfully',
                201
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to record barcode scan: ' . $e->getMessage(), 500);
        }
    }
}
