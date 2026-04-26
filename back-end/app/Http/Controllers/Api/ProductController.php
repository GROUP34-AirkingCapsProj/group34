<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Product;
use App\Models\CategoryLookup;
use App\Models\BrandLookup;
use App\Models\ModelLookup;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    use ApiResponse;

    /** Valid capacity ratings for appliance products. */
    private const CAPACITY_RATINGS = ['1HP', '1.5HP', '2HP', '2.5HP', '3HP', '5HP'];

    public function index(Request $request)
    {
        $query = Product::with(['category', 'brand', 'model', 'status', 'unit'])
            // Sum inventory.quantity_on_hand (avoid alias "quantity" — collides with products.quantity column)
            ->withSum(['inventories as stock_on_hand_total' => function ($q) {
                // all locations
            }], 'quantity_on_hand');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                    ->orWhere('product_code', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('capacity_rating', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        // Filter by product class: 'appliance' or 'consumable'
        if ($request->has('product_type') && in_array($request->product_type, ['appliance', 'consumable'])) {
            $query->where('product_type', $request->product_type);
        }

        // Filter by capacity rating (appliances only)
        if ($request->has('capacity_rating')) {
            $query->where('capacity_rating', $request->capacity_rating);
        }

        $perPage = $request->get('per_page', 50);
        $products = $query
            ->orderByDesc('created_at')
            ->orderByDesc('product_id')
            ->paginate($perPage);

        $products->getCollection()->transform(function (Product $product) {
            $sum = (int) ($product->stock_on_hand_total ?? 0);
            $product->setAttribute('quantity', $sum);

            return $product;
        });

        return $this->paginated($products, 'Products retrieved successfully');
    }

    public function show(Product $product)
    {
        $product->load(['category', 'brand', 'model', 'status', 'unit'])
            ->loadSum(['inventories as stock_on_hand_total' => function ($q) {
                // all locations
            }], 'quantity_on_hand');
        $product->setAttribute('quantity', (int) ($product->stock_on_hand_total ?? 0));

        return $this->success($product, 'Product retrieved successfully');
    }

    public function store(Request $request)
    {
        try {
            // Support both naming conventions:
            // ItemManagement sends: product_code, product_name, category_id, brand_id
            // PORecommendation sends: code, name, category (string), brand (string)
            $input = $request->all();

            // Normalise code / name
            if (!isset($input['product_code']) && isset($input['code'])) {
                $input['product_code'] = $input['code'];
            }
            if (!isset($input['product_name']) && isset($input['name'])) {
                $input['product_name'] = $input['name'];
            }

            // Auto-generate code if still missing
            if (empty($input['product_code'])) {
                $input['product_code'] = 'PROD-' . strtoupper(substr(md5(uniqid()), 0, 8));
            }

            // Resolve category string → category_id
            if (empty($input['category_id']) && !empty($input['category'])) {
                $cat = CategoryLookup::firstOrCreate(
                    ['category_name' => trim($input['category'])],
                    ['category_type' => 'product']
                );
                $input['category_id'] = $cat->category_id;
            }

            // Resolve brand string → brand_id
            if (empty($input['brand_id']) && !empty($input['brand'])) {
                $brand = BrandLookup::firstOrCreate(
                    ['brand_name' => trim($input['brand'])]
                );
                $input['brand_id'] = $brand->brand_id;
            }

            // Merge resolved values back into the request for validation
            $request->merge($input);

            $validated = $request->validate([
                'product_code'       => 'required|string|unique:products,product_code|max:50',
                'product_name'       => 'required|string|max:255',
                'product_type'       => 'nullable|string|in:appliance,consumable',
                'capacity_rating'    => 'nullable|string|max:20',
                'variant'            => 'nullable|string|max:100',
                'description'        => 'nullable|string',
                'pieces_per_package' => 'nullable|integer|min:1',
                'category_id'        => 'nullable|exists:category_lookup,category_id',
                'brand_id'           => 'nullable|exists:brand_lookup,brand_id',
                'model_id'           => 'nullable|exists:model_lookup,model_id',
                'unit_id'            => 'nullable|exists:unit_lookup,unit_id',
                'barcode'            => 'nullable|string|max:100',
                'unit_price'         => 'nullable|numeric|min:0',
                'cost_price'         => 'nullable|numeric|min:0',
                'warranty_period_months' => 'nullable|integer|min:0',
                'recommended_stocks' => 'nullable|integer|min:0',
                'safety_stock'       => 'nullable|integer|min:0',
                'max_stock_level'    => 'nullable|integer|min:0',
                'status_id'          => 'nullable|exists:status_lookup,status_id',
            ]);

            // Default product_type to 'appliance' when omitted
            $validated['product_type'] = $validated['product_type'] ?? 'appliance';

            // Capacity ratings only apply to appliances; strip them from consumables
            if ($validated['product_type'] === 'consumable') {
                $validated['capacity_rating'] = null;
            }

            $product = Product::create($validated);

            // Return with a shape that matches what the frontend expects from both callers
            $product->load(['category', 'brand', 'unit', 'status']);
            return $this->success(array_merge($product->toArray(), [
                'id'   => $product->product_id,
                'name' => $product->product_name,
                'code' => $product->product_code,
            ]), 'Product created successfully', 201);

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    public function update(Request $request, Product $product)
    {
        try {
            $validated = $request->validate([
                'product_name'       => 'sometimes|string|max:255',
                'product_type'       => 'nullable|string|in:appliance,consumable',
                'capacity_rating'    => 'nullable|string|max:20',
                'variant'            => 'nullable|string|max:100',
                'description'        => 'nullable|string',
                'pieces_per_package' => 'nullable|integer|min:1',
                'category_id'        => 'nullable|exists:category_lookup,category_id',
                'brand_id'           => 'nullable|exists:brand_lookup,brand_id',
                'model_id'           => 'nullable|exists:model_lookup,model_id',
                'unit_id'            => 'nullable|exists:unit_lookup,unit_id',
                'barcode'            => 'nullable|string|max:100',
                'unit_price'         => 'nullable|numeric|min:0',
                'cost_price'         => 'nullable|numeric|min:0',
                'warranty_period_months' => 'nullable|integer|min:0',
                'recommended_stocks' => 'nullable|integer|min:0',
                'safety_stock'       => 'nullable|integer|min:0',
                'max_stock_level'    => 'nullable|integer|min:0',
                'status_id'          => 'nullable|exists:status_lookup,status_id',
            ]);

            // If switching to consumable, clear the appliance-only field
            $resolvedType = $validated['product_type'] ?? $product->product_type;
            if ($resolvedType === 'consumable') {
                $validated['capacity_rating'] = null;
            }

            $product->update($validated);
            return $this->success($product->fresh(), 'Product updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    public function destroy(Product $product)
    {
        $product->delete();
        return $this->success(null, 'Product deleted successfully');
    }
}
