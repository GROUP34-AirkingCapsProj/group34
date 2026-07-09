<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApplianceDetail;
use App\Models\BrandLookup;
use App\Models\CategoryLookup;
use App\Models\ConsumableDetail;
use App\Models\Product;
use App\Models\ProductIssuableUnit;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    use ApiResponse;

    private const CAPACITY_RATINGS = ['1HP', '1.5HP', '2HP', '2.5HP', '3HP', '5HP'];

    // -----------------------------------------------------------------------
    // LIST
    // -----------------------------------------------------------------------

    public function index(Request $request)
    {
        $query = Product::with(['category', 'status'])
            ->with(['applianceDetail' => fn($q) => $q->with(['brand'])])
            ->with(['consumableDetail' => fn($q) => $q->with('unit')])
            ->withSum(['inventories as stock_on_hand_total'], 'quantity_on_hand');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                  ->orWhere('product_code', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('applianceDetail', fn($aq) =>
                      $aq->where('capacity_rating', 'like', "%{$search}%")
                  );
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('brand_id')) {
            $query->whereHas('applianceDetail', fn($q) => $q->where('brand_id', $request->brand_id));
        }

        if ($request->filled('product_type') && in_array($request->product_type, ['appliance', 'installation_material'])) {
            $query->where('product_type', $request->product_type);
        }

        $perPage = $request->get('per_page', 50);
        $products = $query->orderByDesc('created_at')->orderByDesc('product_id')->paginate($perPage);

        $products->getCollection()->transform(function (Product $p) {
            $p->setAttribute('quantity', (int) ($p->stock_on_hand_total ?? 0));
            $this->flattenExtension($p);
            return $p;
        });

        return $this->paginated($products, 'Products retrieved successfully');
    }

    // -----------------------------------------------------------------------
    // SHOW
    // -----------------------------------------------------------------------

    public function show(Product $product)
    {
        $product->load(['category', 'status'])
                ->load(['applianceDetail' => fn($q) => $q->with(['brand'])])
                ->load(['consumableDetail' => fn($q) => $q->with('unit')])
                ->loadSum(['inventories as stock_on_hand_total'], 'quantity_on_hand');

        $product->setAttribute('quantity', (int) ($product->stock_on_hand_total ?? 0));
        $this->flattenExtension($product);

        return $this->success($product, 'Product retrieved successfully');
    }

    // -----------------------------------------------------------------------
    // STORE (appliance or consumable in one transaction)
    // -----------------------------------------------------------------------

    public function store(Request $request)
    {
        try {
            $input = $request->all();

            // Normalize naming aliases (PORecommendation caller uses code/name/category/brand)
            if (!isset($input['product_code']) && isset($input['code'])) {
                $input['product_code'] = $input['code'];
            }
            if (!isset($input['product_name']) && isset($input['name'])) {
                $input['product_name'] = $input['name'];
            }
            if (empty($input['product_code'])) {
                $input['product_code'] = 'PROD-' . strtoupper(substr(md5(uniqid()), 0, 8));
            }

            // Resolve category string → ID
            if (empty($input['category_id']) && !empty($input['category'])) {
                $cat = CategoryLookup::firstOrCreate(
                    ['category_name' => trim($input['category'])],
                    ['category_type' => 'product']
                );
                $input['category_id'] = $cat->category_id;
            }

            // Resolve brand string → ID (used by PORecommendation)
            if (empty($input['brand_id']) && !empty($input['brand'])) {
                $brand = BrandLookup::firstOrCreate(['brand_name' => trim($input['brand'])]);
                $input['brand_id'] = $brand->brand_id;
            }

            $request->merge($input);

            $type = $request->input('product_type', 'appliance');

            $sharedValidated = $request->validate([
                'product_code'       => 'required|string|unique:products,product_code|max:50',
                'product_name'       => 'required|string|max:255',
                'product_type'       => 'nullable|string|in:appliance,installation_material',
                'description'        => 'nullable|string',
                'barcode'            => 'nullable|string|max:100',
                'category_id'        => 'nullable|exists:category_lookup,category_id',
                'unit_price'         => 'nullable|numeric|min:0',
                'cost_price'         => 'nullable|numeric|min:0',
                'recommended_stocks' => 'nullable|integer|min:0',
                'safety_stock'       => 'nullable|integer|min:0',
                'max_stock_level'    => 'nullable|integer|min:0',
                'status_id'          => 'nullable|exists:status_lookup,status_id',
                'warranty_value'     => 'nullable|integer|min:0|max:99',
                'warranty_unit'      => ['nullable', \Illuminate\Validation\Rule::in(['year', 'month'])],
            ]);

            $sharedValidated['product_type'] = $type;

            $product = DB::transaction(function () use ($request, $sharedValidated, $type) {
                $product = Product::create($sharedValidated);

                if ($type === 'appliance') {
                    $wValue = (int) ($request->input('warranty_value') ?? 0);
                    $wUnit  = in_array($request->input('warranty_unit'), ['year', 'month'])
                        ? $request->input('warranty_unit') : 'month';
                    $wMonths = $wUnit === 'year' ? $wValue * 12 : $wValue;

                    ApplianceDetail::create([
                        'product_id'             => $product->product_id,
                        'brand_id'               => $request->input('brand_id') ?: null,
                        'model_code'             => $request->input('model_code') ?: null,
                        'capacity_rating'        => $request->input('capacity_rating') ?: null,
                        'variant'                => $request->input('variant') ?: null,
                        'color'                  => $request->input('color') ?: null,
                        'warranty_value'         => $wValue,
                        'warranty_unit'          => $wUnit,
                        'warranty_period_months' => $wMonths,
                        'is_serialized'          => 1,
                    ]);
                } else {
                    $wValue = (int) ($request->input('warranty_value') ?? 0);
                    $wUnit  = in_array($request->input('warranty_unit'), ['year', 'month'])
                        ? $request->input('warranty_unit') : 'month';

                    ConsumableDetail::create([
                        'product_id'         => $product->product_id,
                        'unit_id'            => $request->input('unit_id') ?: null,
                        'pieces_per_package' => $request->input('pieces_per_package') ? (int) $request->input('pieces_per_package') : null,
                        'package_unit'       => $request->input('package_unit') ?: null,
                        'is_serialized'      => 0,
                        'warranty_value'     => $wValue,
                        'warranty_unit'      => $wUnit,
                    ]);
                }

                return $product;
            });

            $product->load(['category', 'status'])
                    ->load(['applianceDetail' => fn($q) => $q->with(['brand'])])
                    ->load(['consumableDetail' => fn($q) => $q->with('unit')]);

            $product->setAttribute('quantity', 0);
            $this->flattenExtension($product);

            return $this->success(array_merge($product->toArray(), [
                'id'   => $product->product_id,
                'name' => $product->product_name,
                'code' => $product->product_code,
            ]), 'Product created successfully', 201);

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    // -----------------------------------------------------------------------
    // UPDATE (saves to both tables in one transaction)
    // -----------------------------------------------------------------------

    public function update(Request $request, Product $product)
    {
        try {
            $sharedValidated = $request->validate([
                'product_name'       => 'sometimes|string|max:255',
                'description'        => 'nullable|string',
                'barcode'            => 'nullable|string|max:100',
                'category_id'        => 'nullable|exists:category_lookup,category_id',
                'unit_price'         => 'nullable|numeric|min:0',
                'cost_price'         => 'nullable|numeric|min:0',
                'recommended_stocks' => 'nullable|integer|min:0',
                'safety_stock'       => 'nullable|integer|min:0',
                'max_stock_level'    => 'nullable|integer|min:0',
                'status_id'          => 'nullable|exists:status_lookup,status_id',
                'warranty_value'     => 'nullable|integer|min:0|max:99',
                'warranty_unit'      => ['nullable', \Illuminate\Validation\Rule::in(['year', 'month'])],
            ]);

            DB::transaction(function () use ($request, $product, $sharedValidated) {
                $product->update($sharedValidated);

                if ($product->product_type === 'appliance') {
                    $wValue = (int) ($request->input('warranty_value') ?? 0);
                    $wUnit  = in_array($request->input('warranty_unit'), ['year', 'month'])
                        ? $request->input('warranty_unit') : 'month';
                    $wMonths = $wUnit === 'year' ? $wValue * 12 : $wValue;

                    $product->applianceDetail()->updateOrCreate(
                        ['product_id' => $product->product_id],
                        [
                            'brand_id'               => $request->input('brand_id') ?: null,
                            'model_code'             => $request->input('model_code') ?: null,
                            'capacity_rating'        => $request->input('capacity_rating') ?: null,
                            'variant'                => $request->input('variant') ?: null,
                            'color'                  => $request->input('color') ?: null,
                            'warranty_value'         => $wValue,
                            'warranty_unit'          => $wUnit,
                            'warranty_period_months' => $wMonths,
                            'is_serialized'          => 1,
                        ]
                    );
                } else {
                    $wValue = (int) ($request->input('warranty_value') ?? 0);
                    $wUnit  = in_array($request->input('warranty_unit'), ['year', 'month'])
                        ? $request->input('warranty_unit') : 'month';

                    $product->consumableDetail()->updateOrCreate(
                        ['product_id' => $product->product_id],
                        [
                            'unit_id'            => $request->input('unit_id') ?: null,
                            'pieces_per_package' => $request->input('pieces_per_package') ? (int) $request->input('pieces_per_package') : null,
                            'package_unit'       => $request->input('package_unit') ?: null,
                            'is_serialized'      => 0,
                            'warranty_value'     => $wValue,
                            'warranty_unit'      => $wUnit,
                        ]
                    );
                }
            });

            $fresh = $product->fresh();
            $fresh->load(['category', 'status'])
                  ->load(['applianceDetail' => fn($q) => $q->with(['brand'])])
                  ->load(['consumableDetail' => fn($q) => $q->with('unit')])
                  ->loadSum(['inventories as stock_on_hand_total'], 'quantity_on_hand');

            $fresh->setAttribute('quantity', (int) ($fresh->stock_on_hand_total ?? 0));
            $this->flattenExtension($fresh);

            return $this->success($fresh, 'Product updated successfully');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    // -----------------------------------------------------------------------
    // DESTROY
    // -----------------------------------------------------------------------

    public function destroy(Product $product)
    {
        $product->delete();
        return $this->success(null, 'Product archived successfully');
    }

    public function archived(Request $request)
    {
        $query = Product::onlyTrashed()
            ->with(['category', 'applianceDetail.brand', 'consumableDetail.unit']);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('product_name', 'like', "%{$s}%")
                  ->orWhere('product_code', 'like', "%{$s}%");
            });
        }

        return $this->paginated(
            $query->orderBy('deleted_at', 'desc')->paginate($request->integer('per_page', 15)),
            'Archived products retrieved'
        );
    }

    public function restore($id)
    {
        $product = Product::onlyTrashed()->find($id);
        if (! $product) {
            return $this->notFound('Archived product not found');
        }
        $product->restore();
        return $this->success($product->fresh(), 'Product restored successfully');
    }

    public function forceDestroy($id)
    {
        $product = Product::onlyTrashed()->find($id);
        if (! $product) {
            return $this->notFound('Archived product not found');
        }
        $product->forceDelete();
        return $this->success(null, 'Product permanently deleted');
    }

    // -----------------------------------------------------------------------
    // ISSUABLE UNITS CRUD
    // -----------------------------------------------------------------------

    // GET /api/products/{id}/issuable-units
    public function issuableUnits($productId)
    {
        $product = Product::with('issuableUnits.unit')->find($productId);
        if (! $product) {
            return $this->notFound('Product not found');
        }
        return $this->success($product->issuableUnits, 'Issuable units retrieved');
    }

    // POST /api/products/{id}/issuable-units
    public function storeIssuableUnit(Request $request, $productId)
    {
        $product = Product::find($productId);
        if (! $product) {
            return $this->notFound('Product not found');
        }

        try {
            $validated = $request->validate([
                'unit_id'              => 'required|exists:unit_lookup,unit_id',
                'label'                => 'required|string|max:100',
                'conversion_factor'    => 'required|numeric|min:0.000001',
                'is_whole_package'     => 'boolean',
                'sort_order'           => 'integer|min:0',
                'show_as_quick_action' => 'boolean',
            ]);

            $iu = ProductIssuableUnit::create(
                array_merge($validated, ['product_id' => $productId])
            );

            return $this->success($iu->load('unit'), 'Issuable unit added', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    // PUT /api/products/issuable-units/{issuable_unit_id}
    public function updateIssuableUnit(Request $request, $iuId)
    {
        $iu = ProductIssuableUnit::find($iuId);
        if (! $iu) {
            return $this->notFound('Issuable unit not found');
        }

        try {
            $validated = $request->validate([
                'label'                => 'sometimes|string|max:100',
                'conversion_factor'    => 'sometimes|numeric|min:0.000001',
                'is_whole_package'     => 'sometimes|boolean',
                'sort_order'           => 'sometimes|integer|min:0',
                'show_as_quick_action' => 'sometimes|boolean',
            ]);

            $iu->update($validated);
            return $this->success($iu->load('unit'), 'Updated');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    // DELETE /api/products/issuable-units/{issuable_unit_id}
    public function destroyIssuableUnit($iuId)
    {
        $iu = ProductIssuableUnit::find($iuId);
        if (! $iu) {
            return $this->notFound('Issuable unit not found');
        }
        $iu->delete();
        return $this->success(null, 'Removed');
    }

    // -----------------------------------------------------------------------
    // EXPORT
    // -----------------------------------------------------------------------

    public function export(Request $request)
    {
        $products = Product::with([
            'category',
            'status',
            'applianceDetail.brand',
            'consumableDetail.unit',
        ])->orderBy('product_id')->get();

        $filename = 'item-master-' . now()->format('Ymd-His') . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'Product Code', 'Product Name', 'Type', 'Category', 'Status',
            'Brand', 'Model', 'Capacity', 'Variant', 'Color', 'Warranty',
            'Unit', 'Unit Abbreviation', 'Pieces/Package', 'Package Unit',
            'Cost Price', 'Unit Price',
            'Recommended Stock', 'Safety Stock', 'Max Stock Level',
            'Barcode', 'Description',
            'Created At', 'Updated At',
        ];

        $callback = function () use ($products, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            foreach ($products as $p) {
                $ad = $p->applianceDetail;
                $cd = $p->consumableDetail;

                fputcsv($handle, [
                    $p->product_code,
                    $p->product_name,
                    ucfirst($p->product_type),
                    $p->category?->category_name   ?? '',
                    $p->status?->status_name        ?? '',
                    $ad?->brand?->brand_name                   ?? '',
                    $ad?->model_code                            ?? '',
                    $ad?->capacity_rating                       ?? '',
                    $ad?->variant                               ?? '',
                    $ad?->color                                 ?? '',
                    $ad?->warranty_display ?? ($cd?->warranty_display ?? 'No Warranty'),
                    $cd?->unit?->unit_name                      ?? '',
                    $cd?->unit?->unit_abbreviation              ?? '',
                    $cd?->pieces_per_package                    ?? '',
                    $cd?->package_unit                          ?? '',
                    number_format((float) $p->cost_price, 2),
                    number_format((float) $p->unit_price, 2),
                    $p->recommended_stocks,
                    $p->safety_stock      ?? '',
                    $p->max_stock_level   ?? '',
                    $p->barcode           ?? '',
                    $p->description       ?? '',
                    $p->created_at?->format('Y-m-d H:i:s') ?? '',
                    $p->updated_at?->format('Y-m-d H:i:s') ?? '',
                ]);
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    // -----------------------------------------------------------------------
    // HELPERS
    // -----------------------------------------------------------------------

    private function flattenExtension(Product $p): void
    {
        if ($p->applianceDetail) {
            $ad = $p->applianceDetail;
            $p->setAttribute('brand_id',               $ad->brand_id);
            $p->setAttribute('brand_name',             $ad->brand?->brand_name);
            $p->setAttribute('model_code',             $ad->model_code);
            $p->setAttribute('model_name',             $ad->model_code);
            $p->setAttribute('capacity_rating',        $ad->capacity_rating);
            $p->setAttribute('variant',                $ad->variant);
            $p->setAttribute('color',                  $ad->color);
            $p->setAttribute('warranty_period_months', $ad->warranty_period_months);
            $p->setAttribute('warranty_value',         $ad->warranty_value);
            $p->setAttribute('warranty_unit',          $ad->warranty_unit ?? 'month');
            $p->setAttribute('warranty_display',       $ad->warranty_display);
            $p->setAttribute('is_serialized',          (bool) $ad->is_serialized);
            $p->setRelation('brand',       $ad->brand);
        }

        if ($p->consumableDetail) {
            $cd = $p->consumableDetail;
            $p->setAttribute('unit_id',            $cd->unit_id);
            $p->setAttribute('unit_name',          $cd->unit?->unit_name);
            $p->setAttribute('unit_abbreviation',  $cd->unit?->unit_abbreviation);
            $p->setAttribute('pieces_per_package', $cd->pieces_per_package);
            $p->setAttribute('package_unit',       $cd->package_unit);
            $p->setAttribute('warranty_value',   $cd->warranty_value);
            $p->setAttribute('warranty_unit',    $cd->warranty_unit ?? 'month');
            $p->setAttribute('warranty_display', $cd->warranty_display);
            $p->setAttribute('is_serialized',    (bool) $cd->is_serialized);
            $p->setRelation('unit', $cd->unit);
        }
    }
}
