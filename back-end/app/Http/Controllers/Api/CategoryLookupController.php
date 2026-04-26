<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoryLookup;
use App\Models\Product;
use App\Models\Inventory;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class CategoryLookupController extends Controller
{
    use ApiResponse;

    /**
     * List categories with item count and total value (from items matching category name + inventory).
     */
    public function index()
    {
        $categories = CategoryLookup::orderBy('category_name')->get();
        $result = $categories->map(function ($cat) {
            $productIds = Product::where('category_id', $cat->category_id)->pluck('product_id');
            $totalUnits = 0;
            $totalValue = 0;
            if ($productIds->isNotEmpty()) {
                $agg = Inventory::join('products', 'inventory.product_id', '=', 'products.product_id')
                    ->whereIn('inventory.product_id', $productIds)
                    ->selectRaw('COALESCE(SUM(inventory.quantity_on_hand), 0) as units, COALESCE(SUM(inventory.quantity_on_hand * COALESCE(products.unit_price, 0)), 0) as value')
                    ->first();
                $totalUnits = (int) ($agg->units ?? 0);
                $totalValue = (float) ($agg->value ?? 0);
            }
            $itemCount = $productIds->count();
            return [
                'category_id' => $cat->category_id,
                'category_name' => $cat->category_name,
                'category_type' => $cat->category_type,
                'description' => $cat->description,
                'items_count' => $itemCount,
                'total_units' => $totalUnits,
                'total_value' => round($totalValue, 2),
                'avg_price' => $itemCount > 0 ? round($totalValue / max(1, $totalUnits), 2) : 0,
            ];
        });

        return $this->success($result->toArray(), 'Categories retrieved');
    }

    /**
     * Category performance table: category, total items, total units, total value, avg price.
     */
    public function performance()
    {
        $categories = CategoryLookup::orderBy('category_name')->get();
        $rows = [];
        foreach ($categories as $cat) {
            $productIds = Product::where('category_id', $cat->category_id)->pluck('product_id');
            $totalUnits = 0;
            $totalValue = 0;
            if ($productIds->isNotEmpty()) {
                $agg = Inventory::join('products', 'inventory.product_id', '=', 'products.product_id')
                    ->whereIn('inventory.product_id', $productIds)
                    ->selectRaw('COALESCE(SUM(inventory.quantity_on_hand), 0) as units, COALESCE(SUM(inventory.quantity_on_hand * COALESCE(products.unit_price, 0)), 0) as value')
                    ->first();
                $totalUnits = (int) ($agg->units ?? 0);
                $totalValue = (float) ($agg->value ?? 0);
            }
            $itemCount = $productIds->count();
            $avgPrice = $itemCount > 0 ? round($totalValue / max(1, $totalUnits), 2) : 0;
            $rows[] = [
                'category' => $cat->category_name,
                'total_items' => $itemCount,
                'total_units' => $totalUnits,
                'total_value' => round($totalValue, 2),
                'avg_price' => $avgPrice,
            ];
        }
        return $this->success($rows, 'Category performance retrieved');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_name' => 'required|string|max:100|unique:category_lookup,category_name',
            'category_type' => 'nullable|string|max:50',
            'description' => 'nullable|string',
        ]);
        $validated['category_type'] = $validated['category_type'] ?? 'product';
        $cat = CategoryLookup::create($validated);
        return $this->success($cat, 'Category created', 201);
    }

    public function show($category)
    {
        $cat = CategoryLookup::find($category);
        if (!$cat) return $this->notFound('Category not found');
        return $this->success($cat);
    }

    public function update(Request $request, $category)
    {
        $cat = CategoryLookup::find($category);
        if (!$cat) return $this->notFound('Category not found');
        $validated = $request->validate([
            'category_name' => 'sometimes|string|max:100|unique:category_lookup,category_name,' . $category . ',category_id',
            'category_type' => 'nullable|string|max:50',
            'description' => 'nullable|string',
        ]);
        $cat->update($validated);
        return $this->success($cat, 'Category updated');
    }

    public function destroy($category)
    {
        $cat = CategoryLookup::find($category);
        if (!$cat) return $this->notFound('Category not found');
        $cat->delete();
        return $this->success(null, 'Category archived');
    }

    public function archived()
    {
        $categories = CategoryLookup::onlyTrashed()->orderBy('deleted_at', 'desc')->get();
        return $this->success($categories->toArray(), 'Archived categories retrieved');
    }

    public function restore($category)
    {
        $cat = CategoryLookup::onlyTrashed()->find($category);
        if (!$cat) return $this->notFound('Archived category not found');
        $cat->restore();
        return $this->success($cat, 'Category restored');
    }
}
