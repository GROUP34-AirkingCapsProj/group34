<?php

namespace App\Http\Controllers\Api;

use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Models\ApplianceDetail;
use App\Models\BrandLookup;
use App\Models\Inventory;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

/**
 * Partial retrofit onto HandlesLookupCrud (see UnitLookupController for the
 * full retrofit; capacity/variant/preset/unit all use the trait's CRUD
 * outright).
 *
 * index() is bespoke and stays that way: it returns the
 * items_count/total_units/total_value aggregate the dashboards and the
 * Brands admin page consume, which the trait has no hook for.
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
class BrandLookupController extends Controller
{
    use ApiResponse;
    use HandlesLookupCrud;

    /**
     * List brands with item count and total value (from items matching brand name + inventory).
     */
    public function index()
    {
        $brands = BrandLookup::orderBy('brand_name')->get();
        $result = $brands->map(function ($brand) {
            $productIds = ApplianceDetail::where('brand_id', $brand->brand_id)->pluck('product_id');
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
                'brand_id' => $brand->brand_id,
                'brand_name' => $brand->brand_name,
                'description' => $brand->description,
                'items_count' => $itemCount,
                'total_units' => $totalUnits,
                'total_value' => round($totalValue, 2),
                // Equals items_count for brands (both count appliance_details
                // by brand_id) — included anyway so a generic frontend panel
                // consuming all six lookup shapes can rely on usage_count
                // being present everywhere, capacity/variant/preset/unit
                // included, without a per-lookup shape shim.
                'usage_count' => $itemCount,
            ];
        });

        return $this->success($result->toArray(), 'Brands retrieved');
    }

    /**
     * brand_lookup.brand_name carries a real DB-level UNIQUE constraint that
     * is NOT scoped by deleted_at (unlike capacity/variant, whose scope_idx
     * indexes are non-unique and enforce uniqueness purely at the app
     * layer). lookupRules()'s lookupUniqueRule() deliberately exempts
     * archived rows so an archived name never blocks a new one — but for
     * THIS table the DB disagrees: an archived row still occupies its name
     * in the unique index. Reusing an archived brand_name therefore passes
     * validation and then hits MySQL SQLSTATE 23000. Caught here and turned
     * into the same 422 shape a failed unique validation rule would have
     * produced, instead of an uncaught 500. See CategoryLookupController for
     * the identical situation there, and RetrofitCompatTest for the
     * regression test.
     */
    public function store(Request $request)
    {
        $validated = $request->validate($this->lookupRules($request));

        try {
            // Wrapped in a nested transaction so the failing INSERT runs inside a
            // SAVEPOINT. PostgreSQL aborts the ENTIRE transaction on any error
            // (SQLSTATE 25P02) and refuses every later statement until rollback,
            // where MySQL leaves the transaction usable. Without the savepoint,
            // catching this exception yields a correct 422 and then poisons
            // whatever the caller does next inside the same transaction.
            $brand = DB::transaction(fn () => BrandLookup::create($validated));
        } catch (UniqueConstraintViolationException $e) {
            // Catching Laravel's exception rather than testing for SQLSTATE
            // 23000: that is MySQL's code for every integrity violation, while
            // PostgreSQL raises 23505 for a unique violation. The framework
            // normalises both onto this one class.
            return $this->validationError(['brand_name' => ['The brand name has already been taken.']]);
        }

        return $this->success($this->transformRow($brand), 'Brand created', 201);
    }

    public function show($brand)
    {
        $b = BrandLookup::find($brand);
        if (!$b) return $this->notFound('Brand not found');
        return $this->success($this->transformRow($b));
    }

    public function update(Request $request, $brand)
    {
        $b = BrandLookup::find($brand);
        if (!$b) return $this->notFound('Brand not found');
        $validated = $request->validate($this->lookupRules($request, $brand));

        try {
            // Wrapped in a nested transaction so the failing INSERT runs inside a
            // SAVEPOINT. PostgreSQL aborts the ENTIRE transaction on any error
            // (SQLSTATE 25P02) and refuses every later statement until rollback,
            // where MySQL leaves the transaction usable. Without the savepoint,
            // catching this exception yields a correct 422 and then poisons
            // whatever the caller does next inside the same transaction.
            DB::transaction(fn () => $b->update($validated));
        } catch (UniqueConstraintViolationException $e) {
            // Catching Laravel's exception rather than testing for SQLSTATE
            // 23000: that is MySQL's code for every integrity violation, while
            // PostgreSQL raises 23505 for a unique violation. The framework
            // normalises both onto this one class.
            return $this->validationError(['brand_name' => ['The brand name has already been taken.']]);
        }

        return $this->success($this->transformRow($b), 'Brand updated');
    }

    /**
     * Returns the same {usage_count} payload shape as
     * HandlesLookupCrud::destroy() (see that method's docblock) so a shared
     * frontend toast can read `data.usage_count` uniformly across all six
     * lookups. Archiving stays permitted regardless of usage — usage_count
     * here is informational only, same as the trait.
     */
    public function destroy($brand)
    {
        $b = BrandLookup::find($brand);
        if (!$b) return $this->notFound('Brand not found');

        $usage = $this->usageCountFor($b);
        $b->delete();

        return $this->success(
            ['usage_count' => $usage],
            $usage > 0
                ? "Brand archived (still used by {$usage} items)"
                : 'Brand archived'
        );
    }

    protected function lookupModel(): string
    {
        return BrandLookup::class;
    }

    protected function lookupNameColumn(): string
    {
        return 'brand_name';
    }

    protected function lookupLabel(): string
    {
        return 'Brand';
    }

    protected function lookupRules(Request $request, $id = null): array
    {
        return [
            'brand_name' => [
                $id === null ? 'required' : 'sometimes',
                'string',
                'max:100',
                $this->lookupUniqueRule($request, $id),
            ],
            'description' => 'nullable|string',
        ];
    }

    protected function usageCountFor(Model $row): int
    {
        return ApplianceDetail::where('brand_id', $row->brand_id)->count();
    }
}
