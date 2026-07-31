<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Models\IssuableUnitPreset;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A preset is a reusable template (label + base unit + default conversion
 * factor) that prefills a product's issuable unit. It is NOT the owner of
 * product_issuable_units.label — that column belongs to the product and is
 * independently editable, so renaming a preset must never rewrite product
 * rows.
 *
 * This is the opposite of capacity and variant, whose values are copied
 * verbatim onto product rows and are expected to cascade on rename. That is
 * why this controller does not use ScopesLookupsToCategory (presets have no
 * category_id) and deliberately does NOT override cascadeValueChange() or
 * supportsMerge(): with no cascade wired, a merge would archive the source
 * preset and move zero rows, silently orphaning every product that still
 * carries the source's label — so the trait's default supportsMerge() ===
 * false, and the 422 refusal it produces, is the correct behaviour here, not
 * a gap to fill in.
 */
class IssuableUnitPresetController extends Controller
{
    use ApiResponse;
    use HandlesLookupCrud;

    protected function lookupModel(): string
    {
        return IssuableUnitPreset::class;
    }

    protected function lookupNameColumn(): string
    {
        return 'label';
    }

    protected function lookupLabel(): string
    {
        return 'Issuable unit preset';
    }

    /** issuable_unit_preset has a sort_order column, so order by it before the label. */
    protected function lookupOrderColumns(): array
    {
        return ['sort_order', 'label'];
    }

    /** Eager-load the unit relation; ordering still comes from the trait. */
    protected function lookupIndexQuery(Request $request)
    {
        return parent::lookupIndexQuery($request)->with('unit');
    }

    protected function lookupRules(Request $request, $id = null): array
    {
        $unique = Rule::unique('issuable_unit_preset', 'label')->whereNull('deleted_at');

        if ($id !== null) {
            $unique->ignore($id, 'preset_id');
        }

        return [
            'label' => [
                $id === null ? 'required' : 'sometimes',
                'string',
                'max:100',
                $unique,
            ],
            'unit_id' => [
                $id === null ? 'required' : 'sometimes',
                'integer',
                'exists:unit_lookup,unit_id',
            ],
            // The column is decimal(10,6); zero or negative would define an
            // issuable unit that deducts nothing (or adds stock) from
            // inventory, so the floor sits just above zero rather than at it.
            'default_conversion_factor' => 'sometimes|numeric|min:0.000001',
            'is_whole_package'          => 'sometimes|boolean',
            'description'               => 'nullable|string',
            // 'sometimes', not 'nullable': the column is a NOT NULL
            // unsignedTinyInteger default 0, and with strict mode on, a JSON
            // {"sort_order": null} would otherwise pass validation, reach the
            // query builder as a literal NULL, and raise SQLSTATE 1048 -> a
            // 500. 'sometimes' skips an absent key; a key that IS present is
            // still validated, so an explicit null fails 'integer' and
            // returns a clean 422 instead.
            'sort_order' => 'sometimes|integer|min:0|max:255',
        ];
    }

    protected function usageCountFor(Model $row): int
    {
        // Paired form, not a bare whereRaw: ->where('label', $value) first
        // narrows through any ci index on the column, then the BINARY
        // comparison confirms the exact byte match. A lone `BINARY label = ?`
        // is non-sargable and would ignore such an index outright.
        return DB::table('product_issuable_units')
            ->where('label', $row->label)
            ->whereRaw('BINARY label = ?', [$row->label])
            ->count();
    }
}
