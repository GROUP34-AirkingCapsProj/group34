<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Models\UnitLookup;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A pure vanilla lookup: no category scope, no cascade, no merge. All CRUD
 * behaviour comes from HandlesLookupCrud; this controller supplies only
 * configuration.
 *
 * Deliberately does NOT override lookupOrderColumns(): unit_lookup has no
 * sort_order column, and the trait's default (name column only) is already
 * correct — see HandlesLookupCrud::lookupOrderColumns() for why.
 */
class UnitLookupController extends Controller
{
    use ApiResponse;
    use HandlesLookupCrud;

    protected function lookupModel(): string
    {
        return UnitLookup::class;
    }

    protected function lookupNameColumn(): string
    {
        return 'unit_name';
    }

    protected function lookupLabel(): string
    {
        return 'Unit';
    }

    protected function lookupRules(Request $request, $id = null): array
    {
        return [
            // Widths mirror the columns exactly (unit_name varchar(50),
            // unit_abbreviation varchar(10)) so an over-length value is a
            // clean 422 instead of reaching MySQL and raising SQLSTATE 22001
            // under strict mode.
            'unit_name' => [
                $id === null ? 'required' : 'sometimes',
                'string',
                'max:50',
                $this->lookupUniqueRule($request, $id),
            ],
            'unit_abbreviation' => 'nullable|string|max:10',
        ];
    }

    /**
     * unitsAPI.getArchived(params) passes a `search` parameter that the
     * archived list must keep honouring; this same filter also covers the
     * active list since HandlesLookupCrud::lookupArchivedFilters() defaults
     * to delegating here.
     */
    protected function lookupIndexFilters(Request $request, $query): void
    {
        if ($request->filled('search')) {
            $query->where('unit_name', 'like', '%' . $request->input('search') . '%');
        }
    }

    protected function usageCountFor(Model $row): int
    {
        // unit_id is a real foreign key on installation_material_details
        // (the products' installation material-unit detail row), so a plain where() is
        // correct — the BINARY-paired form elsewhere in this codebase is
        // only needed for string-valued lookups copied verbatim onto
        // products, which this is not.
        return DB::table('installation_material_details')
            ->where('unit_id', $row->unit_id)
            ->count();
    }
}
