<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ReceivingDetail;
use App\Models\ItemSerial;
use App\Services\InventoryBatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        // Only rows whose product still exists in `products` (same catalog as Item Master).
        $query = Inventory::query()
            ->forUser($request->user())
            ->whereHas('product')
            ->with(['location'])
            ->with(['product' => function ($q) {
                $q->with([
                        'category',
                        'status',
                        'applianceDetail' => fn($aq) => $aq->with(['brand']),
                        'consumableDetail' => fn($cq) => $cq->with('unit'),
                    ])
                    ->withSum(['inventories as stock_on_hand_total' => function ($sub) {
                        // all locations — same basis as Item Master QTY
                    }], 'quantity_on_hand');
            }]);

        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                  ->orWhere('product_code', 'like', "%{$search}%");
            });
        }

        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        if ($request->has('category')) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->whereHas('category', fn($c) => $c->where('category_name', $request->category));
            });
        }

        if ($request->has('low_stock') && $request->low_stock) {
            $query->whereRaw('quantity_on_hand <= (SELECT recommended_stocks FROM products WHERE products.product_id = inventory.product_id)');
        }

        $perPage = $request->input('per_page', 15);
        return $this->paginated($query->paginate($perPage), 'Inventory retrieved successfully');
    }

    /**
     * GET /api/inventory/{inventory}/batches
     *
     * Returns the FIFO batch breakdown for one inventory row (one product at
     * one location), oldest batch first, legacy (untracked) batch last.
     * Used by the drill-down UI on the Inventory page.
     */
    public function batches(Inventory $inventory)
    {
        $batches = app(InventoryBatchService::class)
            ->batchesForDisplay($inventory->product_id, $inventory->location_id);

        $data = $batches->map(function ($batch) {
            return [
                'batch_id'           => $batch->batch_id,
                'receiving_id'       => $batch->receiving_id,
                'po_number'          => $batch->receiving?->purchaseOrder?->po_number,
                'zone_label'         => $batch->zone_label,
                'received_at'        => $batch->received_at?->toDateString(),
                'quantity_received'  => $batch->quantity_received,
                'quantity_remaining' => $batch->quantity_remaining,
                'is_legacy'          => $batch->receiving_id === null,
            ];
        });

        return $this->success($data, 'Inventory batches retrieved successfully');
    }

    public function show($id)
    {
        $inventory = Inventory::with([
            'location',
            'product.category',
            'product.status',
            'product.applianceDetail.brand',            'product.consumableDetail.unit',
        ])->find($id);

        if (!$inventory) {
            return $this->notFound('Inventory not found');
        }

        return $this->success($inventory, 'Inventory retrieved successfully');
    }

    public function byLocation($locationId)
    {
        $inventory = Inventory::where('location_id', $locationId)
            ->with([
                'product.category',
                'product.status',
                'product.applianceDetail.brand',                'product.consumableDetail.unit',
            ])
            ->get();

        return $this->success($inventory, 'Location inventory retrieved successfully');
    }

    public function byItem($product)
    {
        $productId = is_object($product) ? $product->product_id : $product;
        $inventory = Inventory::where('product_id', $productId)
            ->with(['location.branch'])
            ->get();

        return $this->success($inventory, 'Product inventory retrieved successfully');
    }

    /**
     * GET /api/inventory/{inventory}/history
     *
     * Returns the full stock-in history for one inventory row
     * (one product at one location). Used by the View detail panel.
     */
    public function stockHistory($id)
    {
        $inventory = Inventory::with([
            'location',
            'status',
            'product.category',
            'product.status',
            'product.applianceDetail.brand',            'product.consumableDetail.unit',
        ])->find($id);

        if (! $inventory) {
            return $this->notFound('Inventory record not found');
        }

        $product    = $inventory->product;
        $productId  = $inventory->product_id;
        $locationId = $inventory->location_id;

        if ($product && ! $product->relationLoaded('applianceDetail')) {
            $product->load('applianceDetail', 'consumableDetail');
        }

        $isSerialized     = $product?->isSerialized()        ?? false;
        $piecesPerPackage = $product?->getPiecesPerPackage() ?? 1;

        // ── Receiving history ─────────────────────────────────────────────
        $receivingDetails = ReceivingDetail::query()
            ->where('receiving_details.product_id', $productId)
            ->join('receivings', 'receivings.receiving_id', '=', 'receiving_details.receiving_id')
            ->where('receivings.location_id', $locationId)
            ->leftJoin('purchase_orders', 'purchase_orders.po_id', '=', 'receivings.po_id')
            ->leftJoin('suppliers', 'suppliers.supplier_id', '=', 'purchase_orders.supplier_id')
            ->leftJoin('users', 'users.user_id', '=', 'receivings.received_by')
            ->select([
                'receiving_details.receiving_detail_id',
                'receiving_details.receiving_id',
                'receiving_details.scanned_barcode',
                'receiving_details.quantity_amount',
                'receiving_details.prod_price',
                'receiving_details.condition',
                'receiving_details.created_at as received_at',
                'receiving_details.updated_at',
                'receivings.receiving_number',
                'receivings.receiving_date',
                'receivings.po_id',
                DB::raw("CONCAT(users.first_name, ' ', users.last_name) AS received_by_name"),
                'purchase_orders.po_number',
                'purchase_orders.order_date as po_date',
                'suppliers.supplier_name',
                'suppliers.contact_person as supplier_contact',
                'suppliers.contact_number as supplier_phone',
            ])
            ->orderBy('receiving_details.created_at', 'desc')
            ->get();

        // ── Serial records ───────────────────────────────────────────────
        // Appliances (serialized): unit-level records from item_serial.
        // Consumables/bulk (not serialized): the barcode scanned per receiving
        // event is already captured on receiving_details — surface that instead.
        $serials = collect();
        if ($isSerialized) {
            $serials = ItemSerial::query()
                ->where('item_serial.product_id', $productId)
                ->where('item_serial.location_id', $locationId)
                ->leftJoin('users as scanner', 'scanner.user_id', '=', 'item_serial.scanned_by')
                ->leftJoin('receivings', 'receivings.receiving_id', '=', 'item_serial.receiving_id')
                ->leftJoin('purchase_orders', 'purchase_orders.po_id', '=', 'receivings.po_id')
                ->leftJoin('suppliers', 'suppliers.supplier_id', '=', 'purchase_orders.supplier_id')
                ->leftJoin('status_lookup', 'status_lookup.status_id', '=', 'item_serial.status_id')
                ->select([
                    'item_serial.serial_id',
                    'item_serial.serial_number',
                    'item_serial.serial_type',
                    'item_serial.created_at as scanned_at',
                    DB::raw("CONCAT(scanner.first_name, ' ', scanner.last_name) AS scanned_by_name"),
                    'status_lookup.status_name as serial_status',
                    'receivings.receiving_number',
                    'receivings.receiving_date',
                    'purchase_orders.po_number',
                    'purchase_orders.order_date as po_date',
                    'suppliers.supplier_name',
                ])
                ->orderBy('item_serial.created_at', 'desc')
                ->get();
        } else {
            $serials = $receivingDetails
                ->filter(fn($rd) => ! empty($rd->scanned_barcode))
                ->map(fn($rd) => (object) [
                    'serial_id'       => $rd->receiving_detail_id,
                    'serial_number'   => $rd->scanned_barcode,
                    'serial_type'     => 'received',
                    'scanned_at'      => $rd->received_at,
                    'scanned_by_name' => $rd->received_by_name,
                    'serial_status'   => 'Received',
                    'receiving_number'=> $rd->receiving_number,
                    'receiving_date'  => $rd->receiving_date,
                    'po_number'       => $rd->po_number,
                    'po_date'         => $rd->po_date,
                    'supplier_name'   => $rd->supplier_name,
                ])
                ->values();
        }

        // ── Summary stats ─────────────────────────────────────────────────
        $totalCostValue = $receivingDetails->sum(
            fn($rd) => (float) $rd->prod_price * (int) $rd->quantity_amount
        );
        $totalPieces  = $receivingDetails->sum('quantity_amount');
        $avgCostPrice = $totalPieces > 0
            ? round($totalCostValue / $totalPieces, 2)
            : ($product?->cost_price ?? 0);

        $latestReceiving = $receivingDetails->first();

        // ── Movement summary: distinguish customer sales from transfers ────
        // Sold and transferred pieces come from separate tables, so a transfer
        // can never be counted as a sale (and vice versa). Both work the same
        // for appliances and consumables — they are recorded as quantities.
        $soldPieces = (int) DB::table('issuance_details')
            ->join('issuances', 'issuances.issuance_id', '=', 'issuance_details.issuance_id')
            ->where('issuance_details.product_id', $productId)
            ->where('issuances.location_id', $locationId)
            ->whereNull('issuances.deleted_at')
            ->sum(DB::raw('COALESCE(issuance_details.quantity_in_pieces, issuance_details.quantity_issued, 0)'));

        $transferredPieces = (int) DB::table('transfer_details')
            ->join('transfers', 'transfers.transfer_id', '=', 'transfer_details.transfer_id')
            ->where('transfer_details.product_id', $productId)
            ->where('transfers.from_location_id', $locationId)
            ->whereNull('transfers.deleted_at')
            ->sum(DB::raw('COALESCE(transfer_details.quantity_transferred, 0)'));

        return $this->success([
            'inventory' => [
                'inventory_id'       => $inventory->inventory_id,
                'location_id'        => $inventory->location_id,
                'location_name'      => $inventory->location?->location_name,
                'location_type'      => $inventory->location?->location_type,
                'product_id'         => $inventory->product_id,
                'quantity_on_hand'   => $inventory->quantity_on_hand,
                'packages_on_hand'   => $inventory->packages_on_hand,
                'available_quantity' => $inventory->available_quantity,
                'reorder_level'      => $inventory->reorder_level,
                'last_updated'       => $inventory->last_updated ?? $inventory->updated_at,
                'status_name'        => $inventory->status?->status_name ?? 'Active',
                'created_at'         => $inventory->created_at,
                'updated_at'         => $inventory->updated_at,
            ],
            'product' => [
                'product_id'         => $product?->product_id,
                'product_code'       => $product?->product_code,
                'product_name'       => $product?->product_name,
                'product_type'       => $product?->product_type,
                'is_serialized'      => $isSerialized,
                'pieces_per_package' => $piecesPerPackage,
                'cost_price'         => $product?->cost_price,
                'unit_price'         => $product?->unit_price,
                'category_name'      => $product?->category?->category_name,
                'description'        => $product?->description,
                'brand_name'         => $product?->applianceDetail?->brand?->brand_name ?? null,
                'model_name'         => $product?->applianceDetail?->model_code ?? null,
                'capacity_rating'    => $product?->applianceDetail?->capacity_rating ?? null,
                'variant'            => $product?->applianceDetail?->variant ?? null,
                'warranty_months'    => $product?->applianceDetail?->warranty_period_months ?? null,
                'unit_name'          => $product?->consumableDetail?->unit?->unit_name ?? null,
                'unit_abbr'          => $product?->consumableDetail?->unit?->unit_abbreviation ?? null,
                'package_unit'       => $product?->consumableDetail?->package_unit ?? null,
            ],
            'receiving_history' => $receivingDetails,
            'serial_records'    => $serials,
            'summary' => [
                'total_receive_events'  => $receivingDetails->count(),
                'total_pieces_received' => $totalPieces,
                'total_cost_value'      => round($totalCostValue, 2),
                'avg_cost_price'        => $avgCostPrice,
                'last_stocked_at'       => $latestReceiving?->received_at ?? null,
                'latest_po_number'      => $latestReceiving?->po_number ?? null,
                'latest_supplier'       => $latestReceiving?->supplier_name ?? null,
                'serial_count'          => $serials->count(),
                'stock_in'              => (int) $totalPieces,
                'stock_out'             => $soldPieces,
                'transferred'           => $transferredPieces,
            ],
        ], 'Inventory history retrieved successfully');
    }

    public function scanBarcode(Request $request)
    {
        try {
            $validated = $request->validate([
                'barcode'     => 'required|string',
                'location_id' => 'nullable|exists:locations,location_id',
            ]);

            $raw     = trim($validated['barcode']);
            $lower   = strtolower($raw);
            $upper   = strtoupper($raw);
            $digits  = preg_replace('/\D/', '', $raw) ?? '';
            $noSpace = preg_replace('/\s+/', '', $raw) ?? '';

            $candidates = array_values(array_unique(array_filter(
                [$raw, $lower, $upper, $noSpace, $digits],
                fn($v) => $v !== ''
            )));

            // Pass 1 — search products.barcode and products.product_code
            $product = null;
            foreach ($candidates as $c) {
                $norm = strtolower(trim($c));
                $product = Product::query()
                    ->where(function ($q) use ($norm) {
                        $q->whereRaw("LOWER(TRIM(COALESCE(barcode,''))) = ?",       [$norm])
                          ->orWhereRaw("LOWER(TRIM(COALESCE(product_code,''))) = ?", [$norm]);
                    })
                    ->with([
                        'category', 'status',
                        'applianceDetail' => fn($aq) => $aq->with(['brand']),
                        'consumableDetail' => fn($cq) => $cq->with('unit'),
                    ])
                    ->first();
                if ($product) {
                    break;
                }
            }

            // Pass 2 — search item_serials.serial_number (appliance units)
            if (! $product) {
                foreach ($candidates as $c) {
                    $serial = \App\Models\ItemSerial::query()
                        ->whereRaw('LOWER(TRIM(serial_number)) = ?', [strtolower(trim($c))])
                        ->first();
                    if ($serial && $serial->product_id) {
                        $product = Product::query()
                            ->where('product_id', $serial->product_id)
                            ->with([
                                'category', 'status',
                                'applianceDetail' => fn($aq) => $aq->with(['brand']),
                                'consumableDetail' => fn($cq) => $cq->with('unit'),
                            ])
                            ->first();
                        if ($product) {
                            break;
                        }
                    }
                }
            }

            if (! $product) {
                return $this->notFound('Product not found with this barcode');
            }

            // Expose extension-table fields at the product level for backward-compat
            // (same shim pattern used by ProductController)
            if ($product->applianceDetail) {
                $ad = $product->applianceDetail;
                $product->setAttribute('brand_id',               $ad->brand_id);
                $product->setAttribute('capacity_rating',        $ad->capacity_rating);
                $product->setAttribute('variant',                $ad->variant);
                $product->setAttribute('warranty_period_months', $ad->warranty_period_months);
                $product->setAttribute('is_serialized',          $ad->is_serialized);
                $product->setAttribute('model_code',             $ad->model_code);
                $product->setAttribute('model_name',             $ad->model_code);
                $product->setRelation('brand', $ad->brand);
            }
            if ($product->consumableDetail) {
                $cd = $product->consumableDetail;
                $product->setAttribute('unit_id',            $cd->unit_id);
                $product->setAttribute('pieces_per_package', $cd->pieces_per_package);
                $product->setAttribute('package_unit',       $cd->package_unit);
                $product->setAttribute('is_serialized',      $cd->is_serialized);
                $product->setRelation('unit', $cd->unit);
            }

            $inventoryQuery = Inventory::where('product_id', $product->product_id)
                ->with(['location.branch']);

            if (! empty($validated['location_id'])) {
                $inventoryQuery->where('location_id', $validated['location_id']);
            }

            return $this->success([
                'product'            => $product,
                'inventory'          => $inventoryQuery->get(),
                // V5 helper fields for the frontend
                'product_type'       => $product->product_type,
                'is_serialized'      => $product->isSerialized(),
                'pieces_per_package' => $product->getPiecesPerPackage(),
                'brand_name'         => $product->applianceDetail?->brand?->brand_name   ?? null,
                'capacity'           => $product->applianceDetail?->capacity_rating       ?? null,
                'variant'            => $product->applianceDetail?->variant               ?? null,
                'unit_name'          => $product->consumableDetail?->unit?->unit_name     ?? null,
                'unit_abbr'          => $product->consumableDetail?->unit?->unit_abbreviation ?? null,
            ], 'Barcode scanned successfully');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Scan failed: ' . $e->getMessage(), 500);
        }
    }
}
