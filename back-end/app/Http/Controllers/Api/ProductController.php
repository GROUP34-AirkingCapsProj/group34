<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApplianceDetail;
use App\Models\BrandLookup;
use App\Models\CategoryLookup;
use App\Models\ConsumableDetail;
use App\Models\Product;
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
            ->with(['applianceDetail' => fn($q) => $q->with(['brand', 'model'])])
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

        if ($request->filled('product_type') && in_array($request->product_type, ['appliance', 'consumable'])) {
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
                ->load(['applianceDetail' => fn($q) => $q->with(['brand', 'model'])])
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
                'product_type'       => 'nullable|string|in:appliance,consumable',
                'description'        => 'nullable|string',
                'barcode'            => 'nullable|string|max:100',
                'category_id'        => 'nullable|exists:category_lookup,category_id',
                'unit_price'         => 'nullable|numeric|min:0',
                'cost_price'         => 'nullable|numeric|min:0',
                'recommended_stocks' => 'nullable|integer|min:0',
                'safety_stock'       => 'nullable|integer|min:0',
                'max_stock_level'    => 'nullable|integer|min:0',
                'status_id'          => 'nullable|exists:status_lookup,status_id',
            ]);

            $sharedValidated['product_type'] = $type;

            $product = DB::transaction(function () use ($request, $sharedValidated, $type) {
                $product = Product::create($sharedValidated);

                if ($type === 'appliance') {
                    ApplianceDetail::create([
                        'product_id'             => $product->product_id,
                        'brand_id'               => $request->input('brand_id') ?: null,
                        'model_id'               => $request->input('model_id') ?: null,
                        'capacity_rating'        => $request->input('capacity_rating') ?: null,
                        'variant'                => $request->input('variant') ?: null,
                        'color'                  => $request->input('color') ?: null,
                        'warranty_period_months' => (int) ($request->input('warranty_period_months') ?? 0),
                        'is_serialized'          => 1,
                    ]);
                } else {
                    ConsumableDetail::create([
                        'product_id'         => $product->product_id,
                        'unit_id'            => $request->input('unit_id') ?: null,
                        'pieces_per_package' => $request->input('pieces_per_package') ? (int) $request->input('pieces_per_package') : null,
                        'package_unit'       => $request->input('package_unit') ?: null,
                        'is_serialized'      => 0,
                    ]);
                }

                return $product;
            });

            $product->load(['category', 'status'])
                    ->load(['applianceDetail' => fn($q) => $q->with(['brand', 'model'])])
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
            ]);

            DB::transaction(function () use ($request, $product, $sharedValidated) {
                $product->update($sharedValidated);

                if ($product->product_type === 'appliance') {
                    $product->applianceDetail()->updateOrCreate(
                        ['product_id' => $product->product_id],
                        [
                            'brand_id'               => $request->input('brand_id') ?: null,
                            'model_id'               => $request->input('model_id') ?: null,
                            'capacity_rating'        => $request->input('capacity_rating') ?: null,
                            'variant'                => $request->input('variant') ?: null,
                            'color'                  => $request->input('color') ?: null,
                            'warranty_period_months' => (int) ($request->input('warranty_period_months') ?? 0),
                            'is_serialized'          => 1,
                        ]
                    );
                } else {
                    $product->consumableDetail()->updateOrCreate(
                        ['product_id' => $product->product_id],
                        [
                            'unit_id'            => $request->input('unit_id') ?: null,
                            'pieces_per_package' => $request->input('pieces_per_package') ? (int) $request->input('pieces_per_package') : null,
                            'package_unit'       => $request->input('package_unit') ?: null,
                            'is_serialized'      => 0,
                        ]
                    );
                }
            });

            $fresh = $product->fresh();
            $fresh->load(['category', 'status'])
                  ->load(['applianceDetail' => fn($q) => $q->with(['brand', 'model'])])
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
        return $this->success(null, 'Product deleted successfully');
    }

    // -----------------------------------------------------------------------
    // HELPERS
    // -----------------------------------------------------------------------

    /**
     * Promote extension-table fields up to the product level so the API
     * response shape stays backward-compatible with the frontend.
     */
    private function flattenExtension(Product $p): void
    {
        if ($p->applianceDetail) {
            $ad = $p->applianceDetail;
            $p->setAttribute('brand_id', $ad->brand_id);
            $p->setAttribute('model_id', $ad->model_id);
            $p->setAttribute('capacity_rating', $ad->capacity_rating);
            $p->setAttribute('variant', $ad->variant);
            $p->setAttribute('color', $ad->color);
            $p->setAttribute('warranty_period_months', $ad->warranty_period_months);
            $p->setAttribute('is_serialized', $ad->is_serialized);
            // Expose relations at the product level (serialises as brand:{...})
            $p->setRelation('brand', $ad->brand);
            $p->setRelation('model', $ad->model);
        }

        if ($p->consumableDetail) {
            $cd = $p->consumableDetail;
            $p->setAttribute('unit_id', $cd->unit_id);
            $p->setAttribute('pieces_per_package', $cd->pieces_per_package);
            $p->setAttribute('package_unit', $cd->package_unit);
            $p->setAttribute('is_serialized', $cd->is_serialized);
            $p->setRelation('unit', $cd->unit);
        }
    }
}
