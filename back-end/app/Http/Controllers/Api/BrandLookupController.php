<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandLookup;
use App\Models\Product;
use App\Models\Inventory;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class BrandLookupController extends Controller
{
    use ApiResponse;

    /**
     * List brands with item count and total value (from items matching brand name + inventory).
     */
    public function index()
    {
        $brands = BrandLookup::orderBy('brand_name')->get();
        $result = $brands->map(function ($brand) {
            $productIds = Product::where('brand_id', $brand->brand_id)->pluck('product_id');
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
                'brand_id' => $brand->brand_id,
                'brand_name' => $brand->brand_name,
                'description' => $brand->description,
                'items_count' => $itemCount,
                'total_units' => $totalUnits,
                'total_value' => round($totalValue, 2),
            ];
        });

        return $this->success($result->toArray(), 'Brands retrieved');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'brand_name' => 'required|string|max:100|unique:brand_lookup,brand_name',
            'description' => 'nullable|string',
        ]);
        $brand = BrandLookup::create($validated);
        return $this->success($brand, 'Brand created', 201);
    }

    public function show($brand)
    {
        $b = BrandLookup::find($brand);
        if (!$b) return $this->notFound('Brand not found');
        return $this->success($b);
    }

    public function update(Request $request, $brand)
    {
        $b = BrandLookup::find($brand);
        if (!$b) return $this->notFound('Brand not found');
        $validated = $request->validate([
            'brand_name' => 'sometimes|string|max:100|unique:brand_lookup,brand_name,' . $brand . ',brand_id',
            'description' => 'nullable|string',
        ]);
        $b->update($validated);
        return $this->success($b, 'Brand updated');
    }

    public function destroy($brand)
    {
        $b = BrandLookup::find($brand);
        if (!$b) return $this->notFound('Brand not found');
        $b->delete();
        return $this->success(null, 'Brand archived');
    }

    public function archived()
    {
        $brands = BrandLookup::onlyTrashed()->orderBy('deleted_at', 'desc')->get();
        return $this->success($brands->toArray(), 'Archived brands retrieved');
    }

    public function restore($brand)
    {
        $b = BrandLookup::onlyTrashed()->find($brand);
        if (!$b) return $this->notFound('Archived brand not found');
        $b->restore();
        return $this->success($b, 'Brand restored');
    }
}
