<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Models\AcTypeLookup;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Unlike capacity and variant, an AC type is referenced by foreign key rather
 * than copied onto appliance_details as a string. That removes the whole
 * rename-cascade problem: renaming a type is a single-row update and cannot
 * drift from the products naming it. Hence no cascadeTargets(),
 * no cascadeValueChange(), and supportsMerge() left at its false default —
 * merging exists to fold two spellings of the same string together, which
 * cannot happen here.
 */
class AcTypeLookupController extends Controller
{
    use ApiResponse;
    use HandlesLookupCrud;

    protected function lookupModel(): string
    {
        return AcTypeLookup::class;
    }

    protected function lookupNameColumn(): string
    {
        return 'ac_type_name';
    }

    protected function lookupLabel(): string
    {
        return 'AC Type';
    }

    protected function lookupRules(Request $request, $id = null): array
    {
        return [
            // max:60 matches ac_type_lookup.ac_type_name's column width.
            'ac_type_name' => [
                $id === null ? 'required' : 'sometimes',
                'string',
                'max:60',
                $this->lookupUniqueRule($request, $id),
            ],
            'is_two_piece' => 'sometimes|boolean',
        ];
    }

    protected function usageCountFor(Model $row): int
    {
        return DB::table('appliance_details')
            ->where('ac_type_id', $row->ac_type_id)
            ->count();
    }
}
