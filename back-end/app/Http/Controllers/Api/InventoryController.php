<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        // Only rows whose product still exists in `products` (same catalog as Item Master).
        $query = Inventory::query()
            ->whereHas('product')
            ->with(['location'])
            ->with(['product' => function ($q) {
                $q->with([
                        'category',
                        'status',
                        'applianceDetail' => fn($aq) => $aq->with(['brand', 'model']),
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

    public function show($id)
    {
        $inventory = Inventory::with([
            'location',
            'product.category',
            'product.status',
            'product.applianceDetail.brand',
            'product.applianceDetail.model',
            'product.consumableDetail.unit',
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
                'product.applianceDetail.brand',
                'product.applianceDetail.model',
                'product.consumableDetail.unit',
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
                        'applianceDetail' => fn($aq) => $aq->with(['brand', 'model']),
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
                                'applianceDetail' => fn($aq) => $aq->with(['brand', 'model']),
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
                $product->setRelation('brand', $ad->brand);
                $product->setRelation('model', $ad->model);
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
