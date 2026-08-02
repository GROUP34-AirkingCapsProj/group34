<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Models\CategoryLookup;
use App\Models\Product;
use App\Models\Inventory;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * Partial retrofit onto HandlesLookupCrud (see UnitLookupController for the
 * full retrofit; capacity/variant/preset/unit all use the trait's CRUD
 * outright).
 *
 * index() and performance() are bespoke and stay that way: they return the
 * items_count/total_units/total_value/avg_price aggregate the dashboards and
 * the Categories admin page consume, which the trait has no hook for.
 * store()/show()/update()/destroy() are ALSO bespoke, defined in this class
 * body below — that is a deliberate choice, not an oversight: PHP resolves a
 * class-body method over a same-named trait method silently, with no
 * conflict warning, so the trait's store/show/update/destroy are discarded
 * here in favour of these four. (Confirmed via ReflectionMethod during
 * review — all four resolve to this file, not the trait.) Only
 * archived()/restore()/forceDestroy() actually come from HandlesLookupCrud.
 *
 * Because these four are intentionally shadowed, `parent::store()` etc. must
 * NEVER be added to reach the trait's versions — Controller (the actual
 * parent class) doesn't have them, so parent:: resolves to
 * App\Http\Controllers\Controller, an empty abstract class, and raises "Call
 * to undefined method". This exact mistake already shipped a 500 once in
 * this codebase (see HandlesLookupCrud's own docblock) — the fix, if these
 * four should ever delegate to the trait, is to simply delete them from this
 * class body, not to call parent::.
 *
 * store()/show()/update()/destroy() stay separate methods (for the 23000
 * duplicate-name catch below, and destroy()'s own delete), but each now
 * routes its row through $this->transformRow() / reports the same
 * {usage_count} shape as the trait's destroy() — so the response SHAPE
 * matches capacity/variant/preset/unit even though the method bodies don't.
 */
class CategoryLookupController extends Controller
{
    use ApiResponse;
    use HandlesLookupCrud;

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
                    ->leftJoin('installation_material_details as imd', 'imd.product_id', '=', 'products.product_id')
                    ->whereIn('inventory.product_id', $productIds)
                    ->selectRaw('COALESCE(SUM(inventory.quantity_on_hand), 0) as units, '
                        . 'COALESCE(SUM(inventory.quantity_on_hand * COALESCE(products.unit_price, 0) '
                        . '/ GREATEST(COALESCE(imd.pieces_per_package, 1), 1)), 0) as value')
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
                // Equals items_count for categories (both count products by
                // category_id) — included anyway so a generic frontend panel
                // consuming all six lookup shapes can rely on usage_count
                // being present everywhere, capacity/variant/preset/unit
                // included, without a per-lookup shape shim.
                'usage_count' => $itemCount,
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
                    ->leftJoin('installation_material_details as imd', 'imd.product_id', '=', 'products.product_id')
                    ->whereIn('inventory.product_id', $productIds)
                    ->selectRaw('COALESCE(SUM(inventory.quantity_on_hand), 0) as units, '
                        . 'COALESCE(SUM(inventory.quantity_on_hand * COALESCE(products.unit_price, 0) '
                        . '/ GREATEST(COALESCE(imd.pieces_per_package, 1), 1)), 0) as value')
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

    /**
     * category_lookup.category_name carries a real DB-level UNIQUE
     * constraint that is NOT scoped by deleted_at (see
     * database/migrations/...create_capacity_lookup_table for how
     * capacity/variant differ: their scope_idx indexes are non-unique, so
     * uniqueness there is enforced purely at the app layer). lookupRules()'s
     * lookupUniqueRule() deliberately exempts archived rows
     * (->whereNull('deleted_at')) so an archived name never blocks a
     * genuinely new one — but for THIS table the DB disagrees: an archived
     * row still occupies its name in the unique index. Reusing an archived
     * category_name therefore passes validation and then hits MySQL
     * SQLSTATE 23000. Caught here and turned into the same 422 shape a
     * failed unique validation rule would have produced, instead of an
     * uncaught 500. (This also means the archived-row exemption in
     * lookupUniqueRule() is currently inert for categories in practice — a
     * duplicate can never actually land — see RetrofitCompatTest for the
     * regression test.)
     */
    public function store(Request $request)
    {
        $validated = $request->validate($this->lookupRules($request));
        // 'appliance', not 'product': ItemManagement.jsx filters on exactly
        // 'appliance' / 'installation_material', so any other value makes the
        // category invisible in both dropdowns.
        $validated['category_type'] = $validated['category_type'] ?? 'appliance';

        try {
            $cat = CategoryLookup::create($validated);
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            return $this->validationError(['category_name' => ['The category name has already been taken.']]);
        }

        return $this->success($this->transformRow($cat), 'Category created', 201);
    }

    public function show($category)
    {
        $cat = CategoryLookup::find($category);
        if (!$cat) return $this->notFound('Category not found');
        return $this->success($this->transformRow($cat));
    }

    public function update(Request $request, $category)
    {
        $cat = CategoryLookup::find($category);
        if (!$cat) return $this->notFound('Category not found');
        $validated = $request->validate($this->lookupRules($request, $category));

        try {
            $cat->update($validated);
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            return $this->validationError(['category_name' => ['The category name has already been taken.']]);
        }

        return $this->success($this->transformRow($cat), 'Category updated');
    }

    /**
     * Returns the same {usage_count} payload shape as
     * HandlesLookupCrud::destroy() (see that method's docblock) so a shared
     * frontend toast can read `data.usage_count` uniformly across all six
     * lookups. Archiving stays permitted regardless of usage — usage_count
     * here is informational only, same as the trait.
     */
    public function destroy($category)
    {
        $cat = CategoryLookup::find($category);
        if (!$cat) return $this->notFound('Category not found');

        $usage = $this->usageCountFor($cat);
        $cat->delete();

        return $this->success(
            ['usage_count' => $usage],
            $usage > 0
                ? "Category archived (still used by {$usage} items)"
                : 'Category archived'
        );
    }

    protected function lookupModel(): string
    {
        return CategoryLookup::class;
    }

    protected function lookupNameColumn(): string
    {
        return 'category_name';
    }

    protected function lookupLabel(): string
    {
        return 'Category';
    }

    protected function lookupLabelPlural(): string
    {
        return 'Categories';
    }

    protected function lookupRules(Request $request, $id = null): array
    {
        return [
            'category_name' => [
                $id === null ? 'required' : 'sometimes',
                'string',
                'max:100',
                $this->lookupUniqueRule($request, $id),
            ],
            'category_type' => 'nullable|string|max:50',
            'description'   => 'nullable|string',
        ];
    }

    protected function usageCountFor(Model $row): int
    {
        return Product::where('category_id', $row->category_id)->count();
    }
}
